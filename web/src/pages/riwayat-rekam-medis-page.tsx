import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useQuery, type UseQueryResult } from '@tanstack/react-query';
import { Link } from 'react-router';
import { CalendarDays, FileHeart, ScrollText, type LucideIcon } from 'lucide-react';
import { ApiError, type ApiMeta, type ApiResult } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import {
    daftarRekamMedisOptions,
    STATUS_DOKUMEN_LABEL,
} from '@/lib/api/rekam-medis';
import { riwayatResepOptions, LABEL_STATUS_RESEP } from '@/lib/api/resep';
import {
    daftarSuratKeteranganOptions,
    labelTipeSuratKeterangan,
} from '@/lib/api/surat-keterangan';
import { bookingPasienOptions, labelStatusBooking, labelTipeLayanan } from '@/lib/api/booking';
import { useDocumentTitle } from '@/hooks/use-document-title';
import {
    cocokCari,
    kelompokkanBulan,
    kunciBulanInstan,
    kunciBulanTanggal,
    urutkanInstanMenurun,
} from '@/lib/riwayat';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    BarisKunjungan,
    BarisRekamMedis,
    BarisResepTanpaRekamMedis,
    BarisSuratKeterangan,
} from '@/features/rekam-medis/riwayat-baris';
import type {
    Booking,
    Iso,
    RekamMedisDaftar,
    Resep,
    StatusResep,
    SuratKeterangan,
} from '@/lib/api/types';

const UKURAN_REKAM_MEDIS = 50;
const UKURAN_RESEP = 100;
const UKURAN_TURUNAN = 15;

const PESAN_OFFLINE = 'Anda sedang offline. Menampilkan data terakhir yang tersimpan.';

const SEGMEN: ReadonlyArray<{
    nilai: 'rekam-medis' | 'surat' | 'kunjungan';
    label: string;
    ikon: LucideIcon;
}> = [
    { nilai: 'rekam-medis', label: 'Rekam medis', ikon: FileHeart },
    { nilai: 'surat', label: 'Surat keterangan', ikon: ScrollText },
    { nilai: 'kunjungan', label: 'Riwayat kunjungan', ikon: CalendarDays },
];

type NilaiSegmen = (typeof SEGMEN)[number]['nilai'];

type BarisRekamGabungan =
    | { jenis: 'rekam'; waktu: Iso; rekam: RekamMedisDaftar }
    | { jenis: 'resep'; waktu: Iso; resep: Resep };

type HasilDaftarRekam = ApiResult<{ rekam_medis: RekamMedisDaftar[] }>;

type HasilRiwayatResep = ApiResult<{ resep: Resep[] }>;

/**
 * `/rekam-medis` - F10's hub: one entry point, three views, one search.
 *
 * ## The index is the primary source, and the detail read is never prefetched
 *
 * Rows come from `GET /rekam-medis` (writes zero access-log rows). `GET /pasien/resep`
 * contributes ONLY prescriptions whose `rekam_medis_id` is `null`, which have no
 * record to open and are rendered as non-clickable rows. The detail read
 * (`GET /rekam-medis/{id}`) writes one log row per success, so no row-building code
 * path may call it; opening a row is the patient's action and nothing else.
 *
 * ## Search is client-side over the loaded rows; the status chips are a server param
 *
 * The scope line says so ("Menyaring data yang sudah dimuat di halaman ini"), which is
 * the NN/g rule the pattern records. The status chips map onto `GET /pasien/resep`'s
 * `status` parameter - the one endpoint in this view that accepts one.
 *
 * ## Every segment carries its own loading/empty/error/filter-empty state
 *
 * One failed source never blanks the others, and a 403 is a capability refusal about
 * the caller, so it renders `ForbiddenState` rather than copy claiming the list is
 * empty.
 */
export function RiwayatRekamMedisPage() {
    useDocumentTitle('Riwayat dan rekam medis | Sehatly');

    const [segmen, setSegmen] = useState<NilaiSegmen>('rekam-medis');
    const [q, setQ] = useState('');
    const [status, setStatus] = useState<StatusResep | ''>('');
    const [halamanRekam, setHalamanRekam] = useState(1);
    const [halamanSurat, setHalamanSurat] = useState(1);
    const [halamanKunjungan, setHalamanKunjungan] = useState(1);

    const judulSegmenRef = useRef<HTMLHeadingElement>(null);
    const gantiSegmenRef = useRef(false);

    const saya = useQuery(meOptions());
    const user = saya.data?.data.user ?? null;
    const boleh = user === null || user.tipe === 'pasien';

    const rekam = useQuery({
        ...daftarRekamMedisOptions({
            page: halamanRekam,
            per_page: UKURAN_REKAM_MEDIS,
        }),
        enabled: boleh,
    });

    const resep = useQuery({
        ...riwayatResepOptions({
            page: 1,
            per_page: UKURAN_RESEP,
            ...(status === '' ? {} : { status }),
        }),
        enabled: boleh,
    });

    const surat = useQuery({
        ...daftarSuratKeteranganOptions({
            page: halamanSurat,
            per_page: UKURAN_TURUNAN,
        }),
        enabled: boleh,
    });

    const kunjungan = useQuery({
        ...bookingPasienOptions({
            page: halamanKunjungan,
            per_page: UKURAN_TURUNAN,
        }),
        enabled: boleh,
    });

    useEffect(() => {
        if (gantiSegmenRef.current) {
            judulSegmenRef.current?.focus();
        }
    }, [segmen]);

    const barisGabungan = useMemo<BarisRekamGabungan[]>(() => {
        const rows: BarisRekamGabungan[] = [];

        for (const row of rekam.data?.data.rekam_medis ?? []) {
            rows.push({ jenis: 'rekam', waktu: row.tanggal_periksa, rekam: row });
        }

        for (const row of resep.data?.data.resep ?? []) {
            if (row.rekam_medis_id === null) {
                rows.push({ jenis: 'resep', waktu: row.tanggal_resep, resep: row });
            }
        }

        return urutkanInstanMenurun(rows, (row) => row.waktu);
    }, [rekam.data, resep.data]);

    const barisGabunganTampil = useMemo(
        () =>
            barisGabungan.filter((row) =>
                row.jenis === 'rekam'
                    ? cocokCari(
                          q,
                          row.rekam.keluhan_utama,
                          row.rekam.diagnosis_kerja,
                          row.rekam.dokter.nama_lengkap,
                          STATUS_DOKUMEN_LABEL[row.rekam.status_dokumen],
                      )
                    : cocokCari(
                          q,
                          row.resep.nomor_resep,
                          LABEL_STATUS_RESEP[row.resep.status],
                          row.resep.is_kedaluwarsa ? 'kedaluwarsa' : null,
                      ),
            ),
        [barisGabungan, q],
    );

    const barisSurat = useMemo(
        () => urutkanInstanMenurun(surat.data?.data.surat_keterangan ?? [], (row) => row.dibuat_at),
        [surat.data],
    );

    const barisSuratTampil = useMemo(
        () =>
            barisSurat.filter((row: SuratKeterangan) =>
                cocokCari(
                    q,
                    row.nomor_surat,
                    labelTipeSuratKeterangan(row.tipe),
                    row.dokter?.nama_lengkap,
                ),
            ),
        [barisSurat, q],
    );

    const barisKunjungan = useMemo(() => {
        const rows = [...(kunjungan.data?.data.booking ?? [])];

        rows.sort((a, b) => {
            const tanggal = (b.tanggal_kunjungan ?? '').localeCompare(
                a.tanggal_kunjungan ?? '',
            );

            return tanggal !== 0 ? tanggal : b.slot_mulai.localeCompare(a.slot_mulai);
        });

        return rows;
    }, [kunjungan.data]);

    const barisKunjunganTampil = useMemo(
        () =>
            barisKunjungan.filter((row: Booking) =>
                cocokCari(
                    q,
                    row.nomor_booking,
                    row.keluhan,
                    labelStatusBooking(row.status),
                    labelTipeLayanan(row.tipe_layanan),
                    `Dokter #${row.dokter_id}`,
                ),
            ),
        [barisKunjungan, q],
    );

    const penyaringSegmen =
        q.trim() !== '' || (segmen === 'rekam-medis' && status !== '');

    const jumlahTampil =
        segmen === 'rekam-medis'
            ? barisGabunganTampil.length
            : segmen === 'surat'
              ? barisSuratTampil.length
              : barisKunjunganTampil.length;

    const pesanJumlah =
        jumlahTampil > 0
            ? `${jumlahTampil} data ditemukan.`
            : penyaringSegmen
              ? 'Tidak ada data yang cocok.'
              : 'Belum ada data.';

    const totalRekam =
        rekam.data === undefined
            ? null
            : (rekam.data.meta?.total ?? rekam.data.data.rekam_medis.length);
    const totalSurat =
        surat.data === undefined
            ? null
            : (surat.data.meta?.total ?? surat.data.data.surat_keterangan.length);
    const totalKunjungan =
        kunjungan.data === undefined
            ? null
            : (kunjungan.data.meta?.total ?? kunjungan.data.data.booking.length);

    const hapusPenyaring = () => {
        setQ('');
        setStatus('');
        setHalamanRekam(1);
        setHalamanSurat(1);
        setHalamanKunjungan(1);
    };

    const labelSegmen = SEGMEN.find((item) => item.nilai === segmen)?.label ?? 'Rekam medis';

    if (!boleh) {
        return (
            <>
                <PageHeader
                    title="Riwayat dan rekam medis"
                    description="Rekam medis, surat keterangan, dan riwayat kunjungan Anda di satu tempat."
                />

                <ForbiddenState detail="Daftar riwayat ini disusun dari data milik akun pasien. Akun dokter tidak dapat membukanya dari halaman ini." />
            </>
        );
    }

    return (
        <>
            <PageHeader
                title="Riwayat dan rekam medis"
                description="Rekam medis, surat keterangan, dan riwayat kunjungan Anda di satu tempat."
            />

            <OfflineBanner message={PESAN_OFFLINE} />

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    <ToggleGroup
                        type="single"
                        value={segmen}
                        onValueChange={(nilai) => {
                            if (nilai === '') {
                                return;
                            }

                            gantiSegmenRef.current = true;
                            setSegmen(nilai as NilaiSegmen);
                        }}
                        role="group"
                        aria-label="Pilih tampilan riwayat"
                        className="flex w-full flex-wrap gap-2 rounded-none bg-transparent"
                    >
                        {SEGMEN.map((item) => {
                            const Ikon = item.ikon;

                            return (
                                <ToggleGroupItem
                                    key={item.nilai}
                                    value={item.nilai}
                                    role="button"
                                    aria-checked={undefined}
                                    aria-pressed={segmen === item.nilai}
                                    data-slot="riwayat-segmen"
                                    className="border-input min-h-11 flex-1 gap-2 rounded-md border px-3"
                                >
                                    <Ikon aria-hidden />

                                    {item.label}
                                </ToggleGroupItem>
                            );
                        })}
                    </ToggleGroup>

                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="riwayat-cari">Cari</Label>

                        <Input
                            id="riwayat-cari"
                            data-slot="riwayat-cari"
                            type="search"
                            value={q}
                            autoComplete="off"
                            placeholder="Cari nomor atau status"
                            className="min-h-11"
                            onChange={(event) => {
                                setQ(event.target.value);
                            }}
                        />

                        <p className="text-muted-foreground text-sm">
                            Menyaring data yang sudah dimuat di halaman ini.
                        </p>

                        <p
                            role="status"
                            aria-live="polite"
                            data-slot="riwayat-jumlah"
                            className="text-muted-foreground text-sm"
                        >
                            {pesanJumlah}
                        </p>
                    </div>

                    {segmen === 'rekam-medis' ? (
                        <div
                            role="group"
                            aria-label="Saring status resep"
                            className="flex flex-wrap gap-2"
                        >
                            <Button
                                type="button"
                                size="sm"
                                variant={status === '' ? 'default' : 'outline'}
                                aria-pressed={status === ''}
                                data-slot="riwayat-filter"
                                data-status="semua"
                                className="min-h-11"
                                onClick={() => {
                                    setStatus('');
                                    setHalamanRekam(1);
                                }}
                            >
                                Semua
                            </Button>

                            {(Object.keys(LABEL_STATUS_RESEP) as StatusResep[]).map(
                                (nilai) => (
                                    <Button
                                        key={nilai}
                                        type="button"
                                        size="sm"
                                        variant={status === nilai ? 'default' : 'outline'}
                                        aria-pressed={status === nilai}
                                        data-slot="riwayat-filter"
                                        data-status={nilai}
                                        className="min-h-11"
                                        onClick={() => {
                                            setStatus(nilai);
                                            setHalamanRekam(1);
                                        }}
                                    >
                                        {LABEL_STATUS_RESEP[nilai]}
                                    </Button>
                                ),
                            )}
                        </div>
                    ) : null}

                    <h2
                        ref={judulSegmenRef}
                        tabIndex={-1}
                        data-slot="riwayat-judul-segmen"
                        className="text-lg font-semibold outline-none"
                    >
                        {labelSegmen}
                    </h2>

                    {segmen === 'rekam-medis' ? (
                        <BagianRekamMedis
                            rekam={rekam}
                            resep={resep}
                            tampil={barisGabunganTampil}
                            jumlah={barisGabunganTampil.length}
                            penyaring={penyaringSegmen}
                            onHapusPenyaring={hapusPenyaring}
                            onHalaman={setHalamanRekam}
                        />
                    ) : null}

                    {segmen === 'surat' ? (
                        <BagianTurunan
                            pending={surat.isPending}
                            error={surat.error}
                            jumlah={barisSuratTampil.length}
                            penyaring={penyaringSegmen}
                            onHapusPenyaring={hapusPenyaring}
                            meta={surat.data?.meta}
                            onHalaman={setHalamanSurat}
                            onRetry={() => {
                                void surat.refetch();
                            }}
                            kosong={{
                                title: 'Belum ada surat keterangan.',
                                description:
                                    'Surat keterangan dibuat dokter dari sesi konsultasi. Setelah terbit, surat akan tampil di sini.',
                                action: (
                                    <Button asChild variant="outline" className="min-h-11">
                                        <Link to="/booking">Lihat booking saya</Link>
                                    </Button>
                                ),
                            }}
                        >
                            <DaftarBulan
                                grup={kelompokkanBulan(barisSuratTampil, (row) =>
                                    kunciBulanInstan(row.dibuat_at),
                                )}
                                renderBaris={(row) => (
                                    <BarisSuratKeterangan key={row.id} surat={row} />
                                )}
                            />
                        </BagianTurunan>
                    ) : null}

                    {segmen === 'kunjungan' ? (
                        <BagianTurunan
                            pending={kunjungan.isPending}
                            error={kunjungan.error}
                            jumlah={barisKunjunganTampil.length}
                            penyaring={penyaringSegmen}
                            onHapusPenyaring={hapusPenyaring}
                            meta={kunjungan.data?.meta}
                            onHalaman={setHalamanKunjungan}
                            onRetry={() => {
                                void kunjungan.refetch();
                            }}
                            kosong={{
                                title: 'Belum ada riwayat kunjungan.',
                                description:
                                    'Janji temu yang pernah Anda buat akan tampil di sini beserta statusnya.',
                                action: (
                                    <Button asChild variant="outline" className="min-h-11">
                                        <Link to="/booking">Lihat booking saya</Link>
                                    </Button>
                                ),
                            }}
                        >
                            <DaftarBulan
                                grup={kelompokkanBulan(barisKunjunganTampil, (row) =>
                                    kunciBulanTanggal(row.tanggal_kunjungan),
                                )}
                                renderBaris={(row) => (
                                    <BarisKunjungan key={row.id} booking={row} />
                                )}
                            />
                        </BagianTurunan>
                    ) : null}
                </div>

                <aside
                    aria-label="Ringkasan riwayat"
                    className="hidden flex-col gap-4 lg:flex"
                >                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Ringkasan</CardTitle>
                        </CardHeader>

                        <CardContent className="flex flex-col gap-2">
                            <p className="text-base">
                                {totalRekam ?? '-'} rekam medis
                            </p>
                            <p className="text-base">
                                {totalSurat ?? '-'} surat keterangan
                            </p>
                            <p className="text-base">
                                {totalKunjungan ?? '-'} kunjungan
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Catatan akses</CardTitle>
                        </CardHeader>

                        <CardContent>
                            <p className="text-base">
                                Setiap kali rekam medis dibuka, aksesnya dicatat demi
                                keamanan data Anda.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Tentang halaman ini
                            </CardTitle>
                        </CardHeader>

                        <CardContent>
                            <p className="text-base">
                                Data yang tampil hanya milik akun Anda. Buka baris rekam
                                medis untuk membaca isinya.
                            </p>
                        </CardContent>
                    </Card>
                </aside>
            </div>
        </>
    );
}

/**
 * The "Rekam medis" segment: the index list merged with prescriptions that have no
 * record yet.
 *
 * The index owns the pagination; the prescription source is auxiliary and fetched at
 * its 100-row cap so a no-record row on the first page is never dropped for being on
 * a page this view does not visit.
 */
function BagianRekamMedis({
    rekam,
    resep,
    tampil,
    jumlah,
    penyaring,
    onHapusPenyaring,
    onHalaman,
}: {
    rekam: UseQueryResult<HasilDaftarRekam>;
    resep: UseQueryResult<HasilRiwayatResep>;
    tampil: BarisRekamGabungan[];
    jumlah: number;
    penyaring: boolean;
    onHapusPenyaring: () => void;
    onHalaman: (page: number) => void;
}) {
    if (rekam.isPending) {
        return (
            <div data-slot="riwayat-loading">
                <SkeletonRows rows={4} />
            </div>
        );
    }

    if (rekam.isError) {
        if (rekam.error instanceof ApiError && rekam.error.isForbidden) {
            return (
                <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga riwayat rekam medis tidak dapat dimuat." />
            );
        }

        return (
            <ErrorState
                error={rekam.error}
                title="Gagal memuat riwayat."
                onRetry={() => {
                    void rekam.refetch();
                }}
            />
        );
    }

    if (jumlah === 0 && penyaring) {
        return <KosongPenyaring onHapus={onHapusPenyaring} />;
    }

    if (jumlah === 0) {
        return (
            <EmptyState
                title="Belum ada rekam medis."
                description="Rekam medis ditulis dokter setelah konsultasi. Mulai konsultasi untuk melihatnya di sini."
                action={
                    <Button asChild className="min-h-11">
                        <Link to="/dokter">Cari dokter</Link>
                    </Button>
                }
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            <DaftarBulan
                grup={kelompokkanBulan(tampil, (row) =>
                    row.jenis === 'rekam'
                        ? kunciBulanInstan(row.rekam.tanggal_periksa)
                        : kunciBulanInstan(row.resep.tanggal_resep),
                )}
                renderBaris={(row) =>
                    row.jenis === 'rekam' ? (
                        <BarisRekamMedis key={`rekam-${row.rekam.id}`} rekam={row.rekam} />
                    ) : (
                        <BarisResepTanpaRekamMedis
                            key={`resep-${row.resep.id}`}
                            resep={row.resep}
                        />
                    )
                }
            />

            {resep.isError ? (
                resep.error instanceof ApiError && resep.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga riwayat resep tidak dapat dimuat." />
                ) : (
                    <ErrorState
                        error={resep.error}
                        title="Gagal memuat riwayat."
                        onRetry={() => {
                            void resep.refetch();
                        }}
                    />
                )
            ) : null}

            <Pagination meta={rekam.data?.meta} onPageChange={onHalaman} />
        </div>
    );
}

/**
 * The shared body of the "Surat keterangan" and "Riwayat kunjungan" segments: the two
 * differ only in their row renderer and their copy.
 */
function BagianTurunan({
    pending,
    error,
    jumlah,
    penyaring,
    onHapusPenyaring,
    meta,
    onHalaman,
    onRetry,
    kosong,
    children,
}: {
    pending: boolean;
    error: unknown;
    jumlah: number;
    penyaring: boolean;
    onHapusPenyaring: () => void;
    meta: ApiMeta | undefined;
    onHalaman: (page: number) => void;
    onRetry: () => void;
    kosong: { title: string; description: string; action: ReactNode };
    children: ReactNode;
}) {
    if (pending) {
        return (
            <div data-slot="riwayat-loading">
                <SkeletonRows rows={4} />
            </div>
        );
    }

    if (error != null) {
        if (error instanceof ApiError && error.isForbidden) {
            return (
                <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga daftar ini tidak dapat dimuat." />
            );
        }

        return (
            <ErrorState error={error} title="Gagal memuat riwayat." onRetry={onRetry} />
        );
    }

    if (jumlah === 0 && penyaring) {
        return <KosongPenyaring onHapus={onHapusPenyaring} />;
    }

    if (jumlah === 0) {
        return (
            <EmptyState
                title={kosong.title}
                description={kosong.description}
                action={kosong.action}
            />
        );
    }

    return (
        <div className="flex flex-col gap-4">
            {children}

            <Pagination meta={meta} onPageChange={onHalaman} />
        </div>
    );
}

function DaftarBulan<T>({
    grup,
    renderBaris,
}: {
    grup: Array<{ kunci: string; label: string; baris: T[] }>;
    renderBaris: (row: T) => ReactNode;
}) {
    return (
        <div data-slot="riwayat-timeline" className="flex flex-col gap-5">
            {grup.map((item) => (
                <section key={item.kunci} className="flex flex-col gap-2">
                    <h3 className="text-base font-semibold">{item.label}</h3>

                    <ul className="flex flex-col gap-3">
                        {item.baris.map((row) => renderBaris(row))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

function KosongPenyaring({ onHapus }: { onHapus: () => void }) {
    return (
        <div
            data-slot="riwayat-filter-kosong"
            className="bg-muted/30 flex flex-col items-center gap-2 rounded-lg border border-dashed px-6 py-10 text-center"
        >
            <p className="text-base font-semibold">Tidak ada data yang cocok.</p>

            <Button type="button" variant="outline" className="min-h-11" onClick={onHapus}>
                Hapus penyaring
            </Button>
        </div>
    );
}

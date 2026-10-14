import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertCircle, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import {
    adminDokterOptions,
    verifikasiDokter,
    ubahStatusDokter,
    type AdminDokter,
    type AdminDokterListFilter,
    type UrutanDokter,
} from '@/lib/api/admin';
import { ApiError } from '@/lib/http';
import { useDocumentTitle } from '@/hooks/use-document-title';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { AdminErrorState, AdminGate } from '@/features/admin/admin-gate';
import {
    AdminAktifBadge,
    AdminTelemedisinBadge,
    AdminVerifikasiBadge,
    StrMasaBerlaku,
} from '@/features/admin/status-badges';
import { tanggalSingkat } from '@/features/admin/format-admin';
import { gantiDokterCache } from '@/features/admin/cache';
import { pesanRingkas } from '@/features/admin/pesan';
import { PageHeader } from '@/components/layout/page-header';
import { Pagination } from '@/components/layout/pagination';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { EmptyState } from '@/components/states/empty-state';
import { SkeletonRows } from '@/components/states/loading-state';

const PER_HALAMAN = 10;

const ZONA_KONFLIK =
    'Status dokter sudah berubah di sesi lain. Data disegarkan.';

type ChipFilter = 'semua' | 'pending' | 'nonaktif' | 'tersedia';

const CHIP: ReadonlyArray<{ nilai: ChipFilter; label: string }> = [
    { nilai: 'semua', label: 'Semua' },
    { nilai: 'pending', label: 'Perlu verifikasi' },
    { nilai: 'nonaktif', label: 'Nonaktif' },
    { nilai: 'tersedia', label: 'Tersedia telemedisin' },
];

type HasilBulk = {
    berhasil: string[];
    gagal: Array<{ nama: string; alasan: string }>;
};

/**
 * `/admin/dokter` - F14's admin directory.
 *
 * ## This list is the complement of the public directory
 *
 * It reads `GET /admin/dokter`, which the backend deliberately does not route
 * through `v_dokter_katalog`: pending, rejected, inactive, STR-expired and
 * soft-deleted-account doctors are exactly what an operator must see. The
 * default order is the server's `str_berlaku_sampai ASC` worklist, so the row
 * nearest to lapsing is first.
 *
 * ## Verification is per doctor; suspension is the only bulk action
 *
 * `dokter.verifikasi` is a one-row decision (the endpoint refuses a row that is
 * no longer `pending`), and no bulk endpoint exists or was invented - the bulk
 * bar fans `PUT /admin/dokter/{id}/status` out one request per selected doctor
 * and reports the per-item outcome. The type-to-confirm gate applies to
 * deactivation with more than five rows, per the F14 pattern's blast-radius
 * rule.
 */
export function AdminDokterPage() {
    useDocumentTitle('Dokter');

    return (
        <AdminGate>
            <AdminDokterContent />
        </AdminGate>
    );
}

function AdminDokterContent() {
    const online = useOnlineStatus();
    const queryClient = useQueryClient();

    const [chip, setChip] = useState<ChipFilter>('semua');
    const [cari, setCari] = useState('');
    const [q, setQ] = useState('');
    const [urutan, setUrutan] = useState<UrutanDokter>('str_berlaku_sampai');
    const [halaman, setHalaman] = useState(1);
    const [dipilih, setDipilih] = useState<ReadonlySet<number>>(new Set());
    const [verifikasi, setVerifikasi] = useState<AdminDokter | null>(null);
    const pemicuVerifikasi = useRef<HTMLButtonElement | null>(null);
    const pemicuBulk = useRef<HTMLButtonElement | null>(null);
    const [konflik, setKonflik] = useState<string | null>(null);
    const [dialogBulk, setDialogBulk] = useState<'aktifkan' | 'nonaktifkan' | null>(
        null,
    );
    const [ketikan, setKetikan] = useState('');
    const [hasilBulk, setHasilBulk] = useState<HasilBulk | null>(null);

    const filter = useMemo<AdminDokterListFilter>(
        () => ({
            page: halaman,
            per_page: PER_HALAMAN,
            urutan,
            ...(q === '' ? {} : { q }),
            ...(chip === 'pending' ? { status_verifikasi: 'pending' as const } : {}),
            ...(chip === 'nonaktif' ? { status_aktif: false } : {}),
            ...(chip === 'tersedia' ? { tersedia_telemedisin: true } : {}),
        }),
        [chip, q, halaman, urutan],
    );

    const daftar = useQuery(adminDokterOptions(filter));

    const rows: AdminDokter[] = daftar.data?.data.dokter ?? [];

    const terpilih = useMemo(
        () => rows.filter((row) => dipilih.has(row.id)),
        [rows, dipilih],
    );

    const semuaTerpilih = rows.length > 0 && terpilih.length === rows.length;
    const sebagianTerpilih = terpilih.length > 0 && !semuaTerpilih;

    useEffect(() => {
        setDipilih(new Set());
    }, [filter]);

    const onlineSebelumnya = useRef(online);

    useEffect(() => {
        if (!onlineSebelumnya.current && online) {
            void daftar.refetch();
        }

        onlineSebelumnya.current = online;
    }, [online, daftar.refetch]);

    const putuskan = useMutation({
        mutationFn: (input: {
            id: number;
            status: 'terverifikasi' | 'ditolak';
        }) => verifikasiDokter(input.id, input.status),
        onSuccess: (hasil) => {
            gantiDokterCache(queryClient, hasil.data.dokter);
            toast.success('Status dokter diperbarui.');
            setVerifikasi(null);
            setKonflik(null);
        },
        onError: (error) => {
            if (error instanceof ApiError && error.isValidation) {
                setVerifikasi(null);
                setKonflik(ZONA_KONFLIK);
                void daftar.refetch();

                return;
            }

            toast.error(pesanRingkas(error) ?? 'Gagal memperbarui status dokter.');
        },
    });

    const bulk = useMutation({
        mutationFn: async (aksi: 'aktifkan' | 'nonaktifkan') => {
            const target = rows.filter((row) => dipilih.has(row.id));

            const hasil = await Promise.allSettled(
                target.map((row) =>
                    ubahStatusDokter(row.id, { status_aktif: aksi === 'aktifkan' }),
                ),
            );

            const berhasil: string[] = [];
            const gagal: HasilBulk['gagal'] = [];

            hasil.forEach((satu, index) => {
                const row = target[index];
                const nama = row.nama_lengkap ?? `Dokter #${row.id}`;

                if (satu.status === 'fulfilled') {
                    berhasil.push(nama);
                    gantiDokterCache(queryClient, satu.value.data.dokter);

                    return;
                }

                gagal.push({
                    nama,
                    alasan:
                        pesanRingkas(satu.reason) ??
                        'Perubahan tidak dapat diterapkan.',
                });
            });

            return { berhasil, gagal };
        },
        onSuccess: (hasil) => {
            setHasilBulk(hasil);
            setDialogBulk(null);
            setKetikan('');
            setDipilih(new Set());
        },
    });

    const resetFilter = (): void => {
        setChip('semua');
        setCari('');
        setQ('');
        setUrutan('str_berlaku_sampai');
        setHalaman(1);
    };

    const jumlahBulk = terpilih.length;
    const perluKetik = dialogBulk === 'nonaktifkan' && jumlahBulk > 5;
    const tombolBulkAktif =
        online &&
        !bulk.isPending &&
        (!perluKetik || ketikan === 'NONAKTIFKAN');

    return (
        <>
            <PageHeader
                title="Dokter"
                description="Seluruh dokter, termasuk yang belum terverifikasi dan nonaktif."
                action={
                    <div data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                void daftar.refetch();
                            }}
                        >
                            <RefreshCw aria-hidden />
                            Muat ulang
                        </Button>
                    </div>
                }
            />

            <OfflineBanner message="Anda sedang offline. Perubahan tidak dikirim." />

            {konflik === null ? null : (
                <Alert variant="destructive" role="alert" data-slot="admin-konflik">
                    <AlertCircle aria-hidden />

                    <AlertTitle>Status sudah berubah</AlertTitle>

                    <AlertDescription>{konflik}</AlertDescription>
                </Alert>
            )}

            <form
                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    setQ(cari.trim());
                    setHalaman(1);
                }}
            >
                <div className="flex flex-1 flex-col gap-1.5">
                    <Label htmlFor="admin-cari">Cari nama atau nomor STR</Label>

                    <Input
                        id="admin-cari"
                        value={cari}
                        autoComplete="off"
                        className="h-11"
                        onChange={(event) => {
                            setCari(event.target.value);
                        }}
                    />
                </div>

                <div className="flex flex-col gap-1.5 sm:w-56">
                    <Label htmlFor="admin-urutan">Urutan</Label>

                    <Select
                        value={urutan}
                        onValueChange={(nilai) => {
                            setUrutan(nilai as UrutanDokter);
                            setHalaman(1);
                        }}
                    >
                        <SelectTrigger id="admin-urutan" className="h-11">
                            <SelectValue />
                        </SelectTrigger>

                        <SelectContent>
                            <SelectItem value="str_berlaku_sampai">
                                STR paling dekat berakhir
                            </SelectItem>
                            <SelectItem value="nama">Nama</SelectItem>
                            <SelectItem value="jumlah_konsultasi">
                                Konsultasi terbanyak
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div data-testid="admin-aksi">
                    <Button type="submit" variant="secondary" className="h-11">
                        Cari
                    </Button>
                </div>
            </form>

            <div
                role="group"
                aria-label="Filter dokter"
                className="flex flex-wrap gap-2"
            >
                {CHIP.map((item) => (
                    <div key={item.nilai} data-testid="admin-aksi">
                        <Button
                            type="button"
                            variant={chip === item.nilai ? 'default' : 'outline'}
                            aria-pressed={chip === item.nilai}
                            className="h-11"
                            onClick={() => {
                                setChip(item.nilai);
                                setHalaman(1);
                            }}
                        >
                            {item.label}
                        </Button>
                    </div>
                ))}
            </div>

            {terpilih.length === 0 ? null : (
                <div
                    role="status"
                    aria-live="polite"
                    data-slot="admin-action-bar"
                    className="bg-muted/40 flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3"
                >
                    <p className="text-base font-semibold tabular-nums">
                        {terpilih.length} dipilih
                    </p>

                    <div data-testid="admin-aksi" className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online}
                            onClick={(event) => {
                                pemicuBulk.current = event.currentTarget;
                                setKetikan('');
                                setDialogBulk('aktifkan');
                            }}
                        >
                            Aktifkan
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online}
                            onClick={(event) => {
                                pemicuBulk.current = event.currentTarget;
                                setKetikan('');
                                setDialogBulk('nonaktifkan');
                            }}
                        >
                            Nonaktifkan
                        </Button>

                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11"
                            onClick={() => {
                                setDipilih(new Set());
                            }}
                        >
                            Batal pilih
                        </Button>
                    </div>
                </div>
            )}

            {hasilBulk === null ? null : (
                <div
                    role="status"
                    data-slot="admin-hasil-bulk"
                    className="border-border flex flex-col gap-2 rounded-lg border p-3"
                >
                    <p className="text-base font-semibold tabular-nums">
                        {hasilBulk.berhasil.length} berhasil, {hasilBulk.gagal.length}{' '}
                        gagal
                    </p>

                    {hasilBulk.gagal.length === 0 ? (
                        <p className="text-muted-foreground text-sm">
                            Semua perubahan berhasil diterapkan.
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-1 text-sm">
                            {hasilBulk.gagal.map((item) => (
                                <li key={item.nama}>
                                    <span className="font-medium">{item.nama}</span>:{' '}
                                    {item.alasan}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {daftar.isPending ? (
                <SkeletonRows rows={5} />
            ) : daftar.isError ? (
                <AdminErrorState
                    title="Gagal memuat daftar dokter."
                    error={daftar.error}
                    onRetry={() => {
                        void daftar.refetch();
                    }}
                />
            ) : rows.length === 0 ? (
                <EmptyState
                    title="Belum ada dokter pada filter ini."
                    description="Ubah atau hapus filter untuk melihat dokter lain."
                    action={
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={resetFilter}
                        >
                            Hapus filter
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="flex items-center gap-2">
                        <label className="flex min-h-11 min-w-11 items-center justify-center">
                            <Checkbox
                                aria-label="Pilih semua dokter di halaman ini"
                                checked={
                                    semuaTerpilih
                                        ? true
                                        : sebagianTerpilih
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(nilai) => {
                                    setDipilih(
                                        nilai === true
                                            ? new Set(rows.map((row) => row.id))
                                            : new Set(),
                                    );
                                }}
                            />
                        </label>

                        <span className="text-muted-foreground text-sm">
                            Pilih semua di halaman ini
                        </span>
                    </div>

                    <ul role="list" className="flex flex-col gap-3">
                        {rows.map((row) => {
                            const nama = row.nama_lengkap ?? `Dokter #${row.id}`;

                            return (
                                <li
                                    key={row.id}
                                    data-slot="admin-dokter-row"
                                    data-dokter-id={row.id}
                                    className="border-border flex flex-col gap-3 rounded-lg border p-4 md:flex-row md:items-start md:justify-between"
                                >
                                    <div className="flex min-w-0 flex-1 items-start gap-3">
                                        <label className="flex min-h-11 min-w-11 shrink-0 items-center justify-center">
                                            <Checkbox
                                                aria-label={`Pilih ${nama}`}
                                                checked={dipilih.has(row.id)}
                                                onCheckedChange={(nilai) => {
                                                    setDipilih((lama) => {
                                                        const baru = new Set(lama);

                                                        if (nilai === true) {
                                                            baru.add(row.id);
                                                        } else {
                                                            baru.delete(row.id);
                                                        }

                                                        return baru;
                                                    });
                                                }}
                                            />
                                        </label>

                                        <div className="flex min-w-0 flex-col gap-2">
                                            <p className="text-base font-semibold">
                                                {nama}

                                                {row.akun_dihapus ? (
                                                    <span className="text-destructive">
                                                        {' '}
                                                        (akun dihapus)
                                                    </span>
                                                ) : null}
                                            </p>

                                            <div className="flex flex-wrap gap-2">
                                                <AdminVerifikasiBadge
                                                    status={row.status_verifikasi}
                                                />
                                                <AdminAktifBadge aktif={row.status_aktif} />
                                                <AdminTelemedisinBadge
                                                    tersedia={row.tersedia_telemedisin}
                                                />
                                            </div>

                                            <p className="flex flex-wrap items-center gap-2 text-base">
                                                <span className="tabular-nums">
                                                    STR {row.nomor_str ?? 'Belum diisi'}
                                                </span>

                                                <StrMasaBerlaku dokter={row} />
                                            </p>

                                            <p className="text-base tabular-nums">
                                                SIP {row.nomor_sip ?? 'Belum diisi'}
                                                {row.sip_berlaku_sampai === null
                                                    ? ''
                                                    : ` · s.d. ${tanggalSingkat(row.sip_berlaku_sampai)}`}
                                            </p>

                                            <p className="text-muted-foreground text-sm">
                                                {row.spesialisasi_utama?.nama ??
                                                    'Spesialisasi belum diisi'}
                                                {' · '}
                                                <span className="tabular-nums">
                                                    {row.jumlah_booking ?? 0} booking
                                                </span>
                                            </p>
                                        </div>
                                    </div>

                                    <div
                                        data-testid="admin-aksi"
                                        className="flex flex-wrap gap-2 md:justify-end"
                                    >
                                        {row.status_verifikasi === 'pending' ? (
                                            <Button
                                                type="button"
                                                className="h-11"
                                                data-testid="admin-tulis"
                                                disabled={!online}
                                                onClick={(event) => {
                                                    pemicuVerifikasi.current =
                                                        event.currentTarget;
                                                    setKonflik(null);
                                                    setVerifikasi(row);
                                                }}
                                            >
                                                Verifikasi
                                            </Button>
                                        ) : null}

                                        <Button
                                            asChild
                                            variant="outline"
                                            className="h-11"
                                        >
                                            <Link to={`/admin/dokter/${row.id}`}>
                                                Buka
                                            </Link>
                                        </Button>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>

                    <Pagination
                        meta={daftar.data?.meta}
                        onPageChange={(halamanBerikut) => {
                            setHalaman(halamanBerikut);
                        }}
                    />
                </>
            )}

            <Dialog
                open={verifikasi !== null}
                onOpenChange={(buka) => {
                    if (!buka) {
                        setVerifikasi(null);
                    }
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        pemicuVerifikasi.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Verifikasi dokter ini?</DialogTitle>

                        <DialogDescription>
                            Keputusan ini dicatat pada jejak audit dan hanya dapat
                            dibuat satu kali.
                        </DialogDescription>
                    </DialogHeader>

                    {verifikasi === null ? null : (
                        <div className="flex flex-col gap-2 text-base">
                            <p className="font-semibold">
                                {verifikasi.nama_lengkap ?? `Dokter #${verifikasi.id}`}
                            </p>

                            <p className="tabular-nums">
                                STR {verifikasi.nomor_str ?? 'Belum diisi'} · berlaku
                                sampai {tanggalSingkat(verifikasi.str_berlaku_sampai)}
                            </p>

                            <p className="text-muted-foreground text-sm">
                                Dengan memverifikasi, dokter ini dapat tampil di
                                direktori publik dan menerima booking baru.
                            </p>
                        </div>
                    )}

                    <DialogFooter
                        data-testid="admin-aksi"
                        className="flex flex-wrap gap-2 sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setVerifikasi(null);
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online || putuskan.isPending}
                            onClick={() => {
                                if (verifikasi !== null) {
                                    putuskan.mutate({
                                        id: verifikasi.id,
                                        status: 'ditolak',
                                    });
                                }
                            }}
                        >
                            Tolak verifikasi
                        </Button>

                        <Button
                            type="button"
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!online || putuskan.isPending}
                            onClick={() => {
                                if (verifikasi !== null) {
                                    putuskan.mutate({
                                        id: verifikasi.id,
                                        status: 'terverifikasi',
                                    });
                                }
                            }}
                        >
                            Verifikasi dokter
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={dialogBulk !== null}
                onOpenChange={(buka) => {
                    if (!buka) {
                        setDialogBulk(null);
                        setKetikan('');
                    }
                }}
            >
                <DialogContent
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        pemicuBulk.current?.focus();
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {dialogBulk === 'aktifkan'
                                ? `Aktifkan ${jumlahBulk} dokter?`
                                : `Nonaktifkan ${jumlahBulk} dokter?`}
                        </DialogTitle>

                        <DialogDescription>
                            {dialogBulk === 'aktifkan'
                                ? 'Dokter yang dipilih akan kembali aktif dan dapat menerima booking baru.'
                                : 'Dokter yang dipilih tidak akan tampil di direktori dan tidak menerima booking baru. Booking yang ada tidak otomatis dibatalkan dan tetap berjalan.'}
                        </DialogDescription>
                    </DialogHeader>

                    <ul className="flex flex-col gap-1 text-base">
                        {terpilih.map((row) => (
                            <li key={row.id}>
                                {row.nama_lengkap ?? `Dokter #${row.id}`}
                            </li>
                        ))}
                    </ul>

                    {perluKetik ? (
                        <div className="flex flex-col gap-1.5">
                            <Label htmlFor="admin-kerik-nonaktifkan">
                                Ketik NONAKTIFKAN untuk melanjutkan
                            </Label>

                            <Input
                                id="admin-kerik-nonaktifkan"
                                value={ketikan}
                                autoComplete="off"
                                className="h-11"
                                aria-describedby="admin-kerik-hint"
                                onChange={(event) => {
                                    setKetikan(event.target.value);
                                }}
                            />

                            <p
                                id="admin-kerik-hint"
                                className="text-muted-foreground text-xs"
                                aria-live="polite"
                            >
                                {ketikan === 'NONAKTIFKAN'
                                    ? 'Kata kunci cocok.'
                                    : 'Tombol aktif setelah kata kunci diketik persis.'}
                            </p>
                        </div>
                    ) : null}

                    <DialogFooter
                        data-testid="admin-aksi"
                        className="flex flex-wrap gap-2 sm:justify-end"
                    >
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => {
                                setDialogBulk(null);
                                setKetikan('');
                            }}
                        >
                            Batal
                        </Button>

                        <Button
                            type="button"
                            variant={
                                dialogBulk === 'nonaktifkan' ? 'destructive' : 'default'
                            }
                            className="h-11"
                            data-testid="admin-tulis"
                            disabled={!tombolBulkAktif}
                            onClick={() => {
                                if (dialogBulk !== null) {
                                    bulk.mutate(dialogBulk);
                                }
                            }}
                        >
                            {dialogBulk === 'aktifkan'
                                ? 'Aktifkan dokter'
                                : 'Nonaktifkan dokter'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import {
    ArrowLeft,
    Building2,
    GraduationCap,
    Mail,
    Star,
    Stethoscope,
    Video,
} from 'lucide-react';
import { dokterDetailOptions, labelTipeDokter } from '@/lib/api/dokter';
import { ApiError } from '@/lib/http';
import { formatDecimal, formatRupiah, formatWaktu } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, NotFoundState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';

/**
 * `/dokter/:id` - one doctor's public profile, with no session required.
 *
 * ## The 404 is the interesting state here
 *
 * `DokterController::show()` answers one 404 for **six** different situations: the id never
 * existed, `status_verifikasi` is not `terverifikasi`, the account is inactive,
 * `tersedia_telemedisin` is 0, `str_berlaku_sampai` has passed, or the account is
 * soft-deleted. One status for all six, on purpose - a caller who could tell "unverified"
 * from "absent" could enumerate the verification state of every doctor account from an
 * unauthenticated endpoint, which is exactly what the rule protects.
 *
 * So the copy here says only what the client is allowed to say, and the page **stops**.
 * A spinner left running against a request that has already answered 404 is the failure
 * mode this state exists to prevent, which is why `retry: 0` is set on the query and the
 * 4xx rule in `lib/query-client.ts` agrees with it.
 *
 * A non-numeric segment behaves identically: the route parameter is typed `string` and the
 * controller casts it to `int`, where `"abc"` becomes `0` and matches no row.
 */
export function DoctorDetailPage() {
    const { id } = useParams<{ id: string }>();

    const detail = useQuery(dokterDetailOptions(id ?? ''));

    if (detail.isPending) {
        return (
            <>
                <PageHeader title="Profil dokter" description="Memuat profil..." />

                <SkeletonRows rows={5} />
            </>
        );
    }

    if (detail.isError) {
        if (detail.error instanceof ApiError && detail.error.isNotFound) {
            return (
                <>
                    <PageHeader title="Profil dokter" />

                    <NotFoundState
                        action={
                            <Button asChild variant="outline" size="sm">
                                <Link to="/dokter">
                                    <ArrowLeft />

                                    Kembali ke direktori
                                </Link>
                            </Button>
                        }
                    />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Profil dokter" />

                <ErrorState
                    error={detail.error}
                    onRetry={() => {
                        void detail.refetch();
                    }}
                />

                <Button asChild variant="outline" className="w-fit">
                    <Link to="/dokter">
                        <ArrowLeft />

                        Kembali ke direktori
                    </Link>
                </Button>
            </>
        );
    }

    const dokter = detail.data.data.dokter;
    const spesialisasiUtama = dokter.spesialisasi.find((row) => row.is_utama);
    const spesialisasiLain = dokter.spesialisasi.filter((row) => !row.is_utama);

    return (
        <>
            <Button asChild variant="ghost" size="sm" className="w-fit">
                <Link to="/dokter">
                    <ArrowLeft />

                    Kembali ke direktori
                </Link>
            </Button>

            <PageHeader
                title={dokter.nama_lengkap}
                description={`${labelTipeDokter(dokter.tipe)}${
                    spesialisasiUtama?.nama === null ||
                    spesialisasiUtama?.nama === undefined
                        ? ''
                        : ` - ${spesialisasiUtama.nama}`
                }`}
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Informasi dokter</CardTitle>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-4">
                        <dl className="grid gap-4 sm:grid-cols-2">
                            <Cell
                                label="Biaya konsultasi online"
                                value={formatRupiah(dokter.biaya_konsultasi_online)}
                            />

                            <Cell
                                label="Biaya di luar jam"
                                value={formatRupiah(dokter.biaya_luar_jam)}
                            />

                            <Cell
                                label="Durasi default"
                                value={
                                    dokter.durasi_default_menit === null
                                        ? '-'
                                        : `${dokter.durasi_default_menit} menit`
                                }
                            />

                            <Cell
                                label="Pengalaman"
                                value={
                                    dokter.pengalaman_tahun === null
                                        ? '-'
                                        : `${dokter.pengalaman_tahun} tahun`
                                }
                            />

                            <Cell
                                label="Rating"
                                value={formatDecimal(dokter.rating_rata_rata, 2)}
                            />

                            <Cell
                                label="Jumlah ulasan"
                                value={
                                    dokter.jumlah_ulasan === null
                                        ? '-'
                                        : String(dokter.jumlah_ulasan)
                                }
                            />

                            <Cell
                                label="Jumlah konsultasi"
                                value={String(dokter.jumlah_konsultasi)}
                            />

                            <Cell label="Terdaftar" value={formatWaktu(dokter.dibuat_at)} />
                        </dl>

                        <Separator />

                        <section className="flex flex-col gap-2">
                            <h2 className="text-base font-semibold">Tentang dokter</h2>

                            {dokter.bio === null || dokter.bio === '' ? (
                                <EmptyState
                                    compact
                                    title="Bio belum diisi"
                                    description="Dokter ini belum menambahkan deskripsi singkat pada profilnya."
                                />
                            ) : (
                                <p className="text-sm leading-relaxed">{dokter.bio}</p>
                            )}
                        </section>

                        <Separator />

                        <section className="flex flex-col gap-3">
                            <h2 className="text-base font-semibold">Spesialisasi</h2>

                            {dokter.spesialisasi.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Spesialisasi belum dicatat"
                                    description="Belum ada relasi spesialisasi untuk dokter ini."
                                />
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {spesialisasiUtama === undefined ? null : (
                                            <Badge>{spesialisasiUtama.nama ?? '-'}</Badge>
                                        )}

                                        {spesialisasiLain.map((row) => (
                                            <Badge
                                                key={row.id ?? row.kode ?? row.nama}
                                                variant="secondary"
                                            >
                                                {row.nama ?? '-'}
                                            </Badge>
                                        ))}
                                    </div>

                                    <p className="text-muted-foreground text-xs">
                                        Urutan mengikuti `is_utama` lebih dulu, lalu nama.
                                    </p>
                                </>
                            )}
                        </section>

                        <Separator />

                        <section className="flex flex-col gap-3">
                            <h2 className="text-base font-semibold">Pendidikan</h2>

                            {dokter.pendidikan.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Riwayat pendidikan belum dicatat"
                                    description="Belum ada data jenjang atau institusi untuk dokter ini."
                                />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {dokter.pendidikan.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <GraduationCap
                                                aria-hidden
                                                className="mt-0.5 size-4 shrink-0"
                                            />

                                            <span>
                                                {row.jenjang ?? '-'} - {row.institusi ?? '-'}
                                                {row.tahun_lulus === null
                                                    ? ''
                                                    : ` (${row.tahun_lulus})`}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Informasi lain</CardTitle>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <Badge variant="secondary">
                                <Star className="size-3" />

                                {formatDecimal(dokter.rating_rata_rata, 2)}
                            </Badge>

                            {dokter.tersedia_telemedisin ? (
                                <Badge>
                                    <Video className="size-3" />

                                    Telemedisin
                                </Badge>
                            ) : null}
                        </div>

                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">Fasilitas</h3>

                            {dokter.faskes.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Belum ada afiliasi"
                                    description="Dokter ini tidak tertaut ke fasilitas kesehatan mana pun."
                                />
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {dokter.faskes.map((row) => (
                                        <li
                                            key={row.faskes_id}
                                            className="flex flex-col gap-1 text-sm"
                                        >
                                            <span className="flex items-center gap-2 font-medium">
                                                <Building2
                                                    aria-hidden
                                                    className="size-4 shrink-0"
                                                />

                                                {row.nama ?? '-'}
                                            </span>

                                            <span className="text-muted-foreground">
                                                {row.kode_faskes ?? '-'}
                                                {row.kelas_rs === null
                                                    ? ''
                                                    : ` - kelas ${row.kelas_rs}`}
                                            </span>

                                            {row.alamat === null ? null : (
                                                <span className="text-muted-foreground">
                                                    {row.alamat}
                                                </span>
                                            )}

                                            {row.status_aktif ? null : (
                                                <Badge variant="outline">
                                                    Afiliasi tidak aktif
                                                </Badge>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        {/**
                         * The four fields `DokterDetailResource` withholds are named here
                         * rather than left as a mystery: a patient looking for a licence
                         * number, a phone number or an email should be told those are
                         * deliberately admin-only rather than left wondering whether the
                         * page failed to load them.
                         */}
                        <section className="text-muted-foreground flex flex-col gap-2 text-xs">
                            <h3 className="text-foreground flex items-center gap-2 text-sm font-semibold">
                                <Stethoscope className="size-4" />

                                Tidak ditampilkan
                            </h3>

                            <p className="flex items-start gap-2">
                                <Mail aria-hidden className="mt-0.5 size-3.5 shrink-0" />

                                Nomor STR, nomor SIP, berkas STR, dan kontak langsung
                                dokter tidak dipublikasikan. Kontak pasien ke dokter
                                dilakukan melalui pemesanan konsultasi.
                            </p>
                        </section>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function Cell({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-muted-foreground text-xs">{label}</dt>

            <dd className="text-sm font-medium">{value}</dd>
        </div>
    );
}

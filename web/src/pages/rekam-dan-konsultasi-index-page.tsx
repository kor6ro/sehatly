import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { FileHeart, MessagesSquare } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { riwayatResepOptions } from '@/lib/api/resep';
import { daftarKonsultasi, daftarRekamMedis } from '@/lib/api/tujuan';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { EmptyState } from '@/components/states/empty-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/**
 * `/konsultasi` and `/rekam-medis` - the two destinations the sidebar used to address by a
 * hardcoded id `1`.
 *
 * ## What the server will and will not let a client discover
 *
 * F3-06 named these two links as dead ends: `/konsultasi/1` and `/rekam-medis/1` belong to
 * whoever was seeded first, so every other account got a 404 card. The API publishes no
 * `GET /konsultasi` and no `GET /rekam-medis` list - `routes/api.php` registers only the
 * `{id}` reads, and the medical-record block says so in its own comment ("There is
 * deliberately NO list endpoint"). A list route is a backend change, and this page does not
 * pretend otherwise.
 *
 * What the API DOES publish is `ResepResource.konsultasi_id` and `ResepResource.rekam_medis_id`
 * on every row of `GET /api/v1/pasien/resep`. So the caller's own ids are derivable from a
 * list the server already serves them, and this page resolves them through
 * `lib/api/tujuan.ts` - the pure functions, which answer `null` rather than inventing an id.
 * The source list is the caller's own, so the page is tenant-correct by construction: a
 * consultation belonging to another patient cannot appear here even to be refused.
 *
 * ## The rows are ids, and they are labelled as ids
 *
 * There is no consultation title to show - `KonsultasiResource` publishes a status, a type
 * and two names, and no title at all - so a row reads `Konsultasi 12` and links to the
 * screen that renders the names. Rendering a bare number as though it were a name would be
 * the F3-18 defect in a new place.
 *
 * ## A doctor is not offered a patient's list
 *
 * `GET /api/v1/pasien/resep` starts with `PasienRecordAccess::ownPasien()`, so an account
 * with no `pasien` row gets a 403 and the page says so. That is a capability refusal about
 * the CALLER, not an empty list, so it gets `ForbiddenState` and never the copy that would
 * claim there is nothing to see. A doctor's own consultation list is a real server-side gap;
 * it is reported in `.omo/evidence/F3B-web-robustness.md` and not papered over here.
 */
export function KonsultasiIndexPage() {
    return (
        <DaftarTurunan
            judul="Konsultasi"
            ikon={<MessagesSquare aria-hidden className="size-4" />}
            label="Konsultasi"
            tujuan="/konsultasi"
            keDaftar={daftarKonsultasi}
            deskripsi="Nomor konsultasi diturunkan dari resep milik akun ini, dibaca dari GET /api/v1/pasien/resep. Server belum menyediakan endpoint daftar konsultasi."
            tolakDetail="Daftar ini disusun dari resep milik akun pasien, jadi akun dokter tidak bisa membacanya. Server juga belum menyediakan endpoint daftar konsultasi, sehingga nomor konsultasi tidak dapat ditemukan dari halaman ini."
            kosong={{
                judul: 'Belum ada konsultasi',
                detail: 'Konsultasi dimulai dari booking yang sudah dibayar. Setelah sesi pertama dimulai, nomor konsultasi Anda akan tampil di sini.',
            }}
            aksiKosong={
                <Button asChild variant="outline" size="sm">
                    <Link to="/booking">Lihat booking saya</Link>
                </Button>
            }
        />
    );
}

export function RekamMedisIndexPage() {
    return (
        <DaftarTurunan
            judul="Rekam medis"
            ikon={<FileHeart aria-hidden className="size-4" />}
            label="Rekam medis"
            tujuan="/rekam-medis"
            keDaftar={daftarRekamMedis}
            deskripsi="Nomor rekam medis diturunkan dari resep milik akun ini, dibaca dari GET /api/v1/pasien/resep. Server belum menyediakan endpoint daftar rekam medis."
            tolakDetail="Daftar ini disusun dari resep milik akun pasien, jadi akun dokter tidak bisa membacanya. Server juga belum menyediakan endpoint daftar rekam medis, sehingga nomor rekam medis tidak dapat ditemukan dari halaman ini."
            kosong={{
                judul: 'Belum ada rekam medis',
                detail: 'Rekam medis ditulis dokter pada sesi konsultasi yang sedang berjalan, lalu dapat dibuka kembali dari halaman ini.',
            }}
            aksiKosong={
                <Button asChild variant="outline" size="sm">
                    <Link to="/konsultasi">Lihat konsultasi</Link>
                </Button>
            }
        />
    );
}

/**
 * The shared body, because the two pages differ only in the noun and in which foreign key
 * they read. Two near-identical page components would be two places to forget a rule; this
 * is one place with the rules written down once.
 */
function DaftarTurunan({
    judul,
    ikon,
    label,
    tujuan,
    keDaftar,
    deskripsi,
    tolakDetail,
    kosong,
    aksiKosong,
}: {
    judul: string;
    ikon: ReactNode;
    label: string;
    tujuan: string;
    keDaftar: (resep: Parameters<typeof daftarRekamMedis>[0]) => number[];
    deskripsi: string;
    tolakDetail: string;
    kosong: { judul: string; detail: string };
    aksiKosong: ReactNode;
}) {
    const saya = useQuery(meOptions());

    const user = saya.data?.data.user ?? null;
    const boleh = user === null || user.tipe === 'pasien';

    const riwayat = useQuery({
        ...riwayatResepOptions({ page: 1, per_page: 50 }),
        enabled: boleh,
    });

    const ids = riwayat.data === undefined ? [] : keDaftar(riwayat.data.data.resep);

    return (
        <>
            <PageHeader title={judul} description={deskripsi} />

            {!boleh ? (
                <ForbiddenState detail={tolakDetail} />
            ) : riwayat.isPending ? (
                <SkeletonRows rows={4} />
            ) : riwayat.isError ? (
                riwayat.error instanceof ApiError &&
                riwayat.error.isForbidden ? (
                    <ForbiddenState detail="Akun ini tidak memiliki data pasien, sehingga riwayat resep tidak dapat dimuat." />
                ) : (
                    <ErrorState
                        error={riwayat.error}
                        onRetry={() => {
                            void riwayat.refetch();
                        }}
                    />
                )
            ) : ids.length === 0 ? (
                <EmptyState
                    title={kosong.judul}
                    description={kosong.detail}
                    action={aksiKosong}
                />
            ) : (
                <ul data-slot="daftar-turunan" className="flex flex-col gap-3">
                    {ids.map((id) => (
                        <li key={id}>
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        {ikon}

                                        {label} #{id}
                                    </CardTitle>

                                    <CardDescription>
                                        Data dibaca dari {tujuan}/{'{id}'} milik akun ini.
                                        Server menjawab 404 untuk baris milik akun lain.
                                    </CardDescription>
                                </CardHeader>

                                <CardContent>
                                    <Button asChild variant="outline" size="sm">
                                        <Link to={`${tujuan}/${id}`}>Buka</Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

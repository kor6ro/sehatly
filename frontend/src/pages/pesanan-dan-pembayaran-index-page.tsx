import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { PageHeader } from '@/components/layout/page-header';
import { EmptyState } from '@/components/states/empty-state';
import { Button } from '@/components/ui/button';

/**
 * `/pesanan` and `/pembayaran` - the two destinations the API cannot enumerate at all.
 *
 * ## These are the honest half of the F3-06 fix
 *
 * The sidebar linked `/pesanan/1` and `/pembayaran/1`, and both are 404 cards for every
 * account that does not own row `1`. A client cannot resolve them: `routes/api.php`
 * registers only `GET /api/v1/pesanan-obat/{id}`, and there is no `GET /pesanan-obat` and
 * no `GET /pasien/pesanan-obat`, so a patient's own orders are not reachable over HTTP at
 * all. `pesanan-page.tsx` records the same gap in its own docblock.
 *
 * A list route is a **backend change**, and the brief for this fix is explicit that a
 * backend change is reported rather than made. So the honest client behaviour is the one
 * here: the destination exists, it says exactly what the server does and does not publish,
 * and it offers the one place a patient really does learn an order id - the checkout
 * confirmation, which carries the number and links onward to `/pesanan/{id}` and
 * `/pembayaran/{pesananId}`.
 *
 * The alternative - hiding the two links - was rejected for the reason
 * `app-shell.tsx` gives for every other role-gated entry: a link the account is entitled to
 * follow, whose content explains the limit, beats a missing menu item. And an empty state
 * that names the next step is not a dead end; an error card for a row that is not yours is.
 */
export function PesananIndexPage() {
    return (
        <DaftarBelumTersedia
            judul="Lacak pesanan obat"
            deskripsi="Daftar nomor pesanan milik akun ini belum tersedia di halaman ini."
            detail="Nomor pesanan diberikan oleh server setelah checkout berhasil. Buka riwayat resep, pilih resep yang sudah diverifikasi, lalu tekan Checkout untuk mendapat nomor pesanan dan membuka halaman lacaknya."
            aksi={
                <Button asChild variant="outline" size="sm">
                    <Link to="/pasien/resep">Lihat riwayat resep</Link>
                </Button>
            }
        />
    );
}

export function PembayaranIndexPage() {
    return (
        <DaftarBelumTersedia
            judul="Pembayaran"
            deskripsi="Daftar tagihan pembayaran milik akun ini belum tersedia di halaman ini."
            detail="Pembayaran dilakukan untuk sebuah pesanan. Nomor pesanan diberikan setelah checkout berhasil, lalu halaman pembayaran menanyakan nomor tagihan yang memang tidak dapat ditemukan dari halaman ini."
            aksi={
                <Button asChild variant="outline" size="sm">
                    <Link to="/checkout">Lihat resep yang bisa dipesan</Link>
                </Button>
            }
        />
    );
}

function DaftarBelumTersedia({
    judul,
    deskripsi,
    detail,
    aksi,
}: {
    judul: string;
    deskripsi: string;
    detail: string;
    aksi: ReactNode;
}) {
    return (
        <>
            <PageHeader title={judul} description={deskripsi} />

            <EmptyState
                title="Daftar ini belum tersedia dari server"
                description={detail}
                action={aksi}
            />
        </>
    );
}

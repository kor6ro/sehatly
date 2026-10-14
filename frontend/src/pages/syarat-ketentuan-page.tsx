import { useDocumentTitle } from '@/hooks/use-document-title';
import { StaticPage } from '@/components/layout/static-page';

/**
 * `/syarat-ketentuan` - the plain-language terms of service.
 *
 * Static, like the privacy policy, and for the same reason (owner decision F02 §12 #2):
 * no endpoint serves document text. It is linked from the `syarat_ketentuan` slot and from
 * the registration notice, and readable without a session.
 */
export function SyaratKetentuanPage() {
    useDocumentTitle('Syarat dan ketentuan');

    return (
        <StaticPage
            title="Syarat dan ketentuan"
            description="Berlaku sejak 1 Januari 2026 untuk layanan Sehatly."
        >
            <Bagian judul="Penerimaan">
                <p>
                    Dengan membuat akun, Anda menyetujui aturan di halaman ini.
                    Bila Anda tidak menyetujuinya, jangan memakai layanan Sehatly.
                </p>
            </Bagian>

            <Bagian judul="Akun Anda">
                <p>
                    Isi data yang benar dan jaga kerahasiaan kata sandi Anda. Akun
                    ini untuk Anda sendiri; pemakaian oleh orang tanpa izin adalah
                    tanggung jawab pemilik akun.
                </p>
                <p>
                    Untuk pasien di bawah 18 tahun, akun dibuat dan didampingi oleh
                    orang tua atau wali.
                </p>
            </Bagian>

            <Bagian judul="Layanan telemedicine">
                <p>
                    Sehatly menghubungkan pasien dengan dokter melalui chat, video,
                    kunjungan klinik, atau kunjungan rumah.
                </p>
                <p>
                    Layanan ini bukan pengganti keadaan darurat. Untuk keadaan
                    darurat, hubungi nomor darurat 119 atau fasilitas kesehatan
                    terdekat.
                </p>
            </Bagian>

            <Bagian judul="Kewajiban Anda">
                <p>
                    Jangan menyalahgunakan layanan, mengirim data orang lain tanpa
                    izin, atau memberi informasi yang menyesatkan kepada dokter.
                </p>
                <p>
                    Dokter dapat menolak memberi layanan bila permintaan di luar
                    kewenangan atau membahayakan.
                </p>
            </Bagian>

            <Bagian judul="Pembayaran dan pembatalan">
                <p>
                    Biaya konsultasi dan obat ditampilkan sebelum Anda membayar.
                    Pembatalan mengikuti ketentuan yang tampil pada pesanan atau
                    jadwal yang bersangkutan.
                </p>
            </Bagian>

            <Bagian judul="Resep dan obat">
                <p>
                    Resep hanya diterbitkan oleh dokter yang berwenang. Apoteker
                    menyiapkan dan menyerahkan obat sesuai resep tersebut.
                </p>
            </Bagian>

            <Bagian judul="Penghentian">
                <p>
                    Anda dapat berhenti memakai layanan kapan saja. Sehatly dapat
                    menangguhkan akun yang melanggar aturan ini atau hukum yang
                    berlaku.
                </p>
            </Bagian>

            <Bagian judul="Perubahan ketentuan">
                <p>
                    Bila aturan ini berubah, versinya dinaikkan dan tanggal
                    berlakunya diperbarui. Persetujuan Anda merujuk pada versi yang
                    berlaku saat keputusan dicatat.
                </p>
            </Bagian>

            <Bagian judul="Hukum yang berlaku">
                <p>
                    Aturan ini tunduk pada hukum Republik Indonesia.
                </p>
            </Bagian>
        </StaticPage>
    );
}

function Bagian({
    judul,
    children,
}: {
    judul: string;
    children: React.ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-base font-semibold">{judul}</h2>

            <div className="text-muted-foreground flex flex-col gap-2 text-sm leading-relaxed">
                {children}
            </div>
        </section>
    );
}

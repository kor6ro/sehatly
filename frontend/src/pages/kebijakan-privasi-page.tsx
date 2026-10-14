import { useDocumentTitle } from '@/hooks/use-document-title';
import { StaticPage } from '@/components/layout/static-page';

/**
 * `/kebijakan-privasi` - the plain-language privacy policy.
 *
 * There is no endpoint that serves document text, so this page is static content owned by
 * the frontend (owner decision F02 §12 #2). It is linked from the `kebijakan_privasi`,
 * `berbagi_data_medis`, `pemasaran` and `komunikasi_tindak_lanjut` slots and from the
 * registration notice, and it is readable without a session.
 *
 * The wording follows UU PDP Pasal 21: what is collected, why, how long, who receives it,
 * and which rights a data subject can exercise. Sentences stay short and avoid legal
 * jargon, the same rule the consent slots follow.
 */
export function KebijakanPrivasiPage() {
    useDocumentTitle('Kebijakan privasi');

    return (
        <StaticPage
            title="Kebijakan privasi"
            description="Berlaku sejak 1 Januari 2026. Ditulis dengan bahasa sederhana sesuai UU No. 27 Tahun 2022."
        >
            <Bagian judul="Data yang kami kumpulkan">
                <p>
                    Data identitas: nama lengkap, tanggal lahir, jenis kelamin,
                    alamat, nomor telepon, dan email bila Anda mengisinya.
                </p>
                <p>
                    Data layanan: keluhan, jadwal, konsultasi, resep, dan tagihan
                    yang muncul saat Anda memakai Sehatly.
                </p>
                <p>
                    Data teknis: informasi perangkat dan alamat IP saat Anda
                    mencatat persetujuan, sebagai bukti keputusan.
                </p>
            </Bagian>

            <Bagian judul="Cara kami memakai data">
                <p>
                    Menjalankan akun Anda, termasuk verifikasi OTP dan pengamanan
                    sesi.
                </p>
                <p>
                    Menghubungkan Anda dengan dokter, menyimpan rekam konsultasi,
                    dan menerbitkan resep.
                </p>
                <p>
                    Memproses pembayaran, mengirim pesanan obat, dan memenuhi
                    kewajiban hukum yang berlaku.
                </p>
            </Bagian>

            <Bagian judul="Dasar pemrosesan">
                <p>
                    Persetujuan Anda, sesuai UU PDP Pasal 20. Setiap jenis
                    persetujuan dicatat terpisah dan dapat ditarik kapan saja di
                    Profil &#8594; Privasi dan data.
                </p>
                <p>
                    Pelaksanaan layanan yang Anda minta, dan kewajiban hukum yang
                    harus Sehatly penuhi.
                </p>
            </Bagian>

            <Bagian judul="Berbagi data">
                <p>
                    Fasilitas kesehatan lain hanya menerima data medis Anda bila
                    Anda menyetujui berbagi data medis, misalnya saat surat rujukan
                    dibuat.
                </p>
                <p>
                    Penyedia pembayaran menerima data tagihan seperlunya. Sehatly
                    tidak menjual data pribadi Anda.
                </p>
            </Bagian>

            <Bagian judul="Berapa lama data disimpan">
                <p>
                    Data akun disimpan selama akun Anda aktif. Catatan persetujuan
                    disimpan sebagai bukti selama Anda memiliki akun.
                </p>
                <p>
                    Data medis disimpan mengikuti aturan retensi fasilitas
                    kesehatan yang menaunginya.
                </p>
            </Bagian>

            <Bagian judul="Hak Anda">
                <p>
                    Anda berhak melihat data pribadi Anda, meminta perbaikan data
                    yang salah, menarik persetujuan, dan meminta data dihapus.
                </p>
                <p>
                    Semua permintaan itu dapat dimulai dari halaman Privasi dan
                    data pada akun Anda.
                </p>
            </Bagian>

            <Bagian judul="Perubahan kebijakan">
                <p>
                    Bila isi halaman ini berubah, versinya dinaikkan dan tanggal
                    berlakunya diperbarui. Keputusan Anda selalu merujuk pada versi
                    dokumen yang berlaku saat keputusan itu dicatat.
                </p>
            </Bagian>

            <Bagian judul="Pertanyaan">
                <p>
                    Sampaikan pertanyaan tentang data pribadi Anda melalui fitur
                    dukungan di aplikasi Sehatly.
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

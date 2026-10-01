# F10 — Apple Health "Health Records" (lihat rekaman lama, unduh otomatis, ekspor)

Flow: pasien sudah login (akun faskes terhubung) → menemukan rekam medis lama → membacanya → mengunduh/ekspor.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://support.apple.com/en-gb/guide/iphone/iphc30019594/ios [akses 2026-10-01] | Panduan resmi Apple (Download health records) | Konten diekstrak dari halaman |
| S2 | https://support.apple.com/en-gb/guide/iphone/iph2b3a37ddd/ios [akses 2026-10-01] | Panduan resmi Apple (View health records) | Konten diekstrak |
| S3 | https://support.apple.com/en-us/guide/iphone/iph5ede58c3d/ios [akses 2026-10-01] | Panduan resmi Apple (Share your data) | Export All Health Data (XML) |
| S4 | https://developer.apple.com/documentation/healthkit/accessing-health-records [akses 2026-10-01 via indeks pencarian] | Dokumentasi developer Apple | "Users can download their FHIR records … updates in the background" |
| S5 | https://support.apple.com/en-gb/guide/healthregister/welcome/web [akses 2026-10-01 via indeks pencarian] | Panduan registrasi Health Records | Verifikasi lewat faskes |
| S6 | https://support.apple.com/en-ca/102208 [akses 2026-10-01 via indeks pencarian] | Dukungan Apple (salinan data akun) | Export Health Data lewat profil |

- **Batasan riset:** app iOS tidak bisa diamati di lingkungan ini; halaman panduan penuh navigasi, sebagian dikutip lewat indeks pencarian yang sama (ditandai).

## Langkah terlihat (fakta)

1. **Siapkan unduhan rekaman** [S1]: Health app → **Summary** → picture/initials → **Health Records** → **Get Started** (akun pertama) atau **Add Account** → pilih organisasi → **Connect Account** → masuk ke patient portal → rekaman baru diunduh otomatis ("you automatically receive new records in Health as they become available").
2. **QR/link faskes:** "You can use a QR code or a link from a healthcare provider or authority to download a test result record." [S1].
3. **Lihat rekaman lama** [S2]: Health → **Browse** → Health Categories; lalu (a) search field ketik kategori/tipe, (b) scroll → kategori di bawah **Health Records** (mis. Allergies, Clinical Vitals), atau (c) scroll → nama organisasi; "To see more details, tap any section." Bila tidak muncul: Browse → daftar akun → nama penyedia → sign in.
4. **Sematkan hasil lab:** Browse → **Lab Results** → swipe left → Pin / long-press → "Pin this Lab" [S2].
5. **Ekspor semua data** [S3]: "export all of your health and fitness data from Health in **XML** format" — Health → Summary → picture/initials → **Export All Health Data** → pilih metode berbagi. [S6] menyebut jalur serupa: tap profil → **Export Health Data**.
6. **Berbagi ke app lain** [S3]: pilih kategori (allergies, medications, immunizations) + "current and future health records" atau "current records" saja.
7. **Developer/FHIR** [S4]: rekaman FHIR diunduh dari institusi yang didukung, diperbarui background berkala.

## Hitungan

Titik awal: **pasien sudah login, ingin menemukan rekam medis lama** → tugas inti: **ditemukan, terbaca, dan bisa diunduh/diekspor**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Lihat rekaman lama (Browse → kategori → detail) | TIDAK TERVERIFIKASI | **TIDAK TERVERIFIKASI sebagai angka** — sumber menyebut urutan 4 aksi deskriptif, bukan langkah bernomor [S2] | 0 |
| Ekspor semua data (XML) | TIDAK TERVERIFIKASI | **urutan aksi deskriptif** (Health → Summary → profil → Export All Health Data → pilih metode); sumber tidak menyebut angka langkah [S3] | 0 |
| Setup koneksi akun baru | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (sumber tidak menyebut angka) | kredensial patient portal |
| End-to-end temukan → baca → unduh per-item | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (unduhan bersifat otomatis/latar, bukan per-item) | TIDAK TERVERIFIKASI |

## State terlihat

- **Tidak tersedia (geografis):** "not available in all countries or regions." [S1].
- **Organisasi belum terhubung (empty):** "Your healthcare organization might not appear in this feature. Organizations are added frequently." [S1].
- **Sinkronisasi latar:** rekaman baru masuk otomatis; update background [S1][S4].
- **Terkunci/terenkripsi:** "When iPhone is locked with Face ID, Touch ID, or a passcode, all of the health data … is encrypted." [S3].
- **Loading / error / offline:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Data medis di notifikasi berbagi:** "people you share data with … can also view the health notifications you receive, including high heart rate and irregular rhythm notifications." [S3] — kalau pasien memakai fitur berbagi, isi notifikasi medis ikut terlihat. Untuk Sehatly: jangan meniru pola notifikasi berisi data medis.
- **Kunci kepercayaan di tangan pengguna saja:** "Third-party apps can request access to your health records. Before you grant access, be sure that you trust the app with your records." [S3].
- **Ketergantungan kredensial portal pihak ketiga:** tanpa dukungan faskes, fitur kosong [S1].
- Tidak ada paywall untuk ekspor XML [S3].

## Yang TIDAK bisa diverifikasi

- Jumlah layar/ketukan/field end-to-end.
- Unduhan per-rekaman lama secara manual (sumber mendeskripsikan unduhan otomatis, bukan per-item).
- State loading/error/offline.
- Struktur/format file ekspor selain "XML".
- UI aktual app (tidak bisa diakses web).

# Evidence F12 — Halodoc (batal / jadwal ulang / refund)

Tanggal akses semua sumber: **2026-10-02**. Aplikasi native tidak dapat diakses langsung (tanpa akun); fakta dari halaman publik. F12 adalah pertama kalinya Halodoc diuji untuk **kebijakan pembatalan janji + refund**, bukan untuk UI layar (UI di balik login → tidak terverifikasi).

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.halodoc.com/cari-dokter/nama/irawan-purnama | Profil dokter publik dengan blok **“Kebijakan Pembatalan & Pengembalian Dana”** | Web (halaman publik, bukan app) | Dibaca penuh 2026-10-02 |
| S2 | https://www.halodoc.com/cari-dokter/nama/dr-metta-satyani-sp-gk | Profil dokter publik, blok kebijakan yang sama (redaksi jendela waktu berbeda) | Web | Dibaca penuh 2026-10-02 |
| S3 | https://www.halodoc.com/faq/category/pembatalan-dan-pengembalian-dana | Pusat Bantuan resmi — kategori “Pembatalan & Pengembalian Dana” (10 pertanyaan) | Web | Dibaca penuh 2026-10-02 |
| S4 | https://www.halodoc.com/faq/questions/jika-saya-membayar-menggunakan-virtual-account-kapan-dana-saya-dikembalikan-ke-saldo-halodoc | FAQ resmi — refund VA | Web | Dibaca penuh 2026-10-02 |
| S5 | https://www.halodoc.com/faq/questions/kenapa-pesanan-saya-dibatalkan | FAQ resmi — alasan pesanan dibatalkan | Web | Dibaca penuh 2026-10-02 |
| S6 | https://www.halodoc.com/syarat-dan-ketentuan | Syarat & Ketentuan Pengguna (menyebut Saldo Halodoc dari pengembalian dana) | Web; halaman menyebut Mar 2026 | **Hanya indeks pencarian** (tidak dibuka penuh) → klaim dari S6 ditandai |
| S7 | https://www.halodoc.com/faq/questions/jika-pesanan-atau-transaksi-saya-dibatalkan-apakah-dana-saya-akan-dikembalikan | FAQ resmi — “jika dibatalkan, apakah dana dikembalikan?” | Web | **Hanya judul di S3** (isi tidak dibuka) |

## Langkah terlihat (fakta)

**Kebijakan pembatalan janji (S1, S2):**
- “Pembatalan janji kunjungan dapat dilakukan **melalui aplikasi** **maksimal 24 jam sebelum** waktu janji kunjungan” (S1 — dr. Irawan Purnama).
- Profil lain menyatakan hal yang sama tanpa angka: “sesuai dengan **kebijakan jangka waktu dari Mitra Halodoc**” (S2 — dr. Metta Satyani). → **Dua redaksi berbeda untuk produk yang sama.**
- Biaya: tidak ada pernyataan biaya pembatalan di kedua profil.

**Jalur refund (S1, S2, S4):**
- VA → dikembalikan **otomatis ke Saldo Halodoc**, “dilakukan langsung setelah transaksi dibatalkan” (S4).
- GoPay / Halodoc Wallet → maksimal **3 hari kerja** (S1, S2).
- Kartu kredit/debit → **3 hari kerja + proses bank maksimal 14 hari kerja** (S1, S2).
- Jika refund belum diterima dalam batas waktu → hubungi CS via telepon atau “chat dengan kami” (S1).
- Kategori bantuan memuat artikel terpisah per metode: VA, uang elektronik, kartu kredit/debit, cek refund GoPay, AstraPay, pembayaran campuran Saldo+e-wallet, Saldo Halodoc — **artinya kebijakan refund Halodoc bergantung metode bayar** (S3, judul-judul yang terlihat; isi per artikel tidak dibuka kecuali VA).

**Pembatalan sisi merchant/pesanan obat (S5):** pesanan dapat dibatalkan karena produk tidak tersedia, tidak dijual satuan, stok kurang, harga salah di aplikasi, atau driver tidak dapat menghubungi — **bukan** alur pembatalan oleh pasien.

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar/ketukan end-to-end | **TIDAK TERVERIFIKASI** — tidak ada dokumentasi langkah tombol batal di app (native, di balik login) | — |
| Jendela pembatalan | **≤ 24 jam sebelum janji** (satu profil) / “sesuai mitra” (profil lain) | S1, S2 |
| Field alasan batal | TIDAK TERVERIFIKASI | — |
| Waktu refund | VA “langsung” ke Saldo; GoPay/Wallet ≤3 hari kerja; kartu 3 hari kerja + bank ≤14 hari kerja | S4, S1, S2 |

## State terlihat

- **Refund berhasil (VA):** dana ke Saldo Halodoc setelah pembatalan (S4). ✓
- **Refund dalam proses:** ada janji waktu eksplisit per metode (3 hari kerja / +14 hari kerja bank) dan jalur eskalasi bila lewat (S1, S2). ✓
- **Batal oleh pihak lain:** 5 alasan pembatalan sisi penjual dinyatakan (S5). ✓
- Loading / kosong / error jaringan / offline / reschedule: **tidak didokumentasikan** → N/V.

## Red flag

- Tidak ada dark pattern terverifikasi di halaman yang dibaca (tidak ada biaya tersembunyi pada kebijakan janji).
- **Cacat kepercayaan (bukan dark pattern):** dua profil dokter menampilkan jendela pembatalan yang berbeda (24 jam vs “sesuai mitra”) dan salah satunya menampilkan “Bayar di rumah sakit” bersamaan dengan kebijakan refund — kebijakan refund tidak relevan bila tidak membayar di muka. Untuk Sehatly: **satu kebijakan tunggal yang dibaca dari kode**, bukan per-dokter.
- **Tidak boleh disalin:** kebijakan per mitra yang berbeda-beda; arahkan ke satu `kebijakan_pembatalan` milik Sehatly.

## Yang TIDAK bisa diverifikasi

1. **UI pembatalan/refund di app native** — tombol, dialog, field alasan, status pelacakan: tidak ada satu pun yang terdokumentasi publik.
2. **Jadwal ulang (reschedule)** — tidak ada bukti apa pun bahwa Halodoc menyediakannya untuk janji dokter; jangan mengklaim ada.
3. **Apakah biaya admin/penanganan dikenakan** pada pembatalan — tidak dinyatakan.
4. Apakah refund selalu ke Saldo vs metode asal untuk e-wallet lain (hanya VA yang eksplisit “ke Saldo”).
5. Isi S6 dan S7 (hanya indeks).

## Independensi sumber

- S1, S2, S3, S4, S5 semuanya domain `halodoc.com` (PT Media Dokter Investama) → **satu penerbit**. 
- Tidak ada penerbit ketiga dalam sesi ini → Halodoc **tidak boleh menjadi pemenang rubrik F12**; hanya sumber pola lokal untuk jendela 24 jam + janji waktu refund per metode.

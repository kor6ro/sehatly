# Evidence F04 — Konteks kredensial Indonesia: STR & SIP (KKI/Kemenkes)

Tanggal akses semua sumber: **2026-10-02**. Jenis: regulator/pemerintah publik. Dipakai untuk menentukan sinyal kredensial apa yang **sah** ditampilkan di profil dokter Sehatly.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.kki.go.id/cekdokter/form | Pencarian publik KKI | Terbaca; hasil dilindungi **CAPTCHA** (tidak dipecahkan/di-bypass) |
| S2 | https://www.kki.go.id/faq | FAQ KKI | Aktif |
| S3 | https://kemkes.go.id/id/tata-cara-penyelenggaraan-perizinan-tenaga-medis-dan-tenaga-kesehatan-dalam-uu-no-17-tahun-2023 | Artikel resmi Kemenkes | Aktif |
| S4 | https://kemkes.go.id/id/surat-izin-praktik-tenaga-medis-dan-tenaga-kesehatan-bisa-digunakan-sampai-masa-berlaku-habis | Artikel resmi Kemenkes | Aktif |
| S5 | SE HK.02.01/MENKES/6/2024 (SIP) | Surat edaran | Via halaman SKP |

## Fakta terlihat

- **STR** (Surat Tanda Registrasi) diterbitkan **Konsil Kesehatan Indonesia (KKI)**. Di bawah UU No. 17/2023 berlaku **seumur hidup**; sebelumnya perpanjangan 5 tahun (S2, S3).
- **SIP** (Surat Izin Praktik) diterbitkan **Dinas Kesehatan Kabupaten/Kota / DPMPTSP** di lokasi praktik; masa berlaku mengikuti STR (atau 5 tahun untuk pemegang STR seumur hidup tertentu). Praktisi dapat memiliki SIP ke-2/ke-3. Syarat: STR + surat tempat praktik (+ bukti kompetensi pada kasus tertentu) (S3, S5).
- Verifikasi SIP aktif oleh otoritas via `sisdmk.kemkes.go.id`; SKP via `skp.kemkes.go.id` (S5).
- **Verifikasi publik:** ada alat KKI untuk mencari tenaga medis **berdasarkan Nama atau No STR** ("telah teregistrasi di Konsil Kesehatan Indonesia"), dilindungi CAPTCHA. **Tidak ditemukan pencarian publik SIP tunggal.**

## Implikasi untuk profil Sehatly (fakta + batasan)

- Yang **dapat ditampilkan sah**: spesialisasi/gelar (dari kompetensi terdaftar) dan klaim registrasi yang pengguna bisa cek sendiri di pencarian KKI berdasarkan nama/No STR.
- **SIP bersifat lokal & terikat tempat** — badge "dokter terdaftar" saja **tidak membuktikan hak praktik saat ini di fasilitas tertentu**. Profil yang ketat harus membedakan STR (registrasi) dari SIP aktif per lokasi.
- Menampilkan nomor STR = **keputusan privasi/pemilik produk** (Halodoc mengumpulkan tetapi tidak memublikasikan — lihat `F04/halodoc.md`).

## Yang TIDAK bisa diverifikasi

Apakah pencarian KKI yang ber-CAPTCHA mengembalikan status/kedaluwarsa persis ke publik (tidak diuji, menghormati aturan no-bypass); tidak ada portal publik pencarian nomor SIP.

## Red flag

Tidak ada dark pattern. Catatan kepatuhan: mengklaim "terverifikasi" tanpa menyatakan **apa** yang diverifikasi (STR? SIP? keduanya?) berpotensi menyesatkan.

## Independensi sumber

S1–S2 = KKI; S3–S5 = Kemenkes — dua lembaga berbeda (KKI adalah badan yang dibentuk pemerintah; dalam praktiknya satu ekosistem regulator). Dipakai sebagai **dasar faktual kredensial**, bukan pembanding UX.

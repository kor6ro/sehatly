# F05 Bukti: Zocdoc (zocdoc.com)

Tanggal akses semua sumber: **2026-10-01**. Sumber: **web publik, press release resmi, help center, dokumentasi API, dua pustaka rekaman alur pihak ketiga**. Zocdoc memblokir fetch otomatis (HTTP 403 untuk `zocdoc.com`, `robots.txt` juga 403) → **UI live tidak diamati**. Tanpa akun, tanpa login, tanpa submit.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| Z1 | https://www.prnewswire.com/news-releases/zocdoc-enters-its-next-chapter-expanding-beyond-its-marketplace-to-power-access-to-care-everywhere-302879956.html | 2026-09-16 | Press release resmi (aktivitas 2026) |
| Z2 | https://www.prnewswire.com/news-releases/as-patients-look-for-care-in-more-places-zocdoc-makes-providers-bookable-everywhere-patients-search-302885376.html | 2026-09-22 | Press release resmi |
| Z3 | https://www.techtarget.com/searchhealthit/news/366650558/Zocdoc-opens-appointment-scheduling-architecture-to-health-partners | 2026-09-17 | Berita dagang |
| Z4 | https://www.zocdoc.com/blog/facts/real-time-availability-instant-booking/ | 2026-04-02 | Blog resmi — ketersediaan real-time, peringkat berdasarkan asuransi |
| Z5 | https://www.zocdoc.com/blog/facts/customer-support-and-cancellations/ | 2025-12-19 | Blog resmi — dukungan & pembatalan |
| Z6 | https://www.zocdoc.com/patient-help/en/articles/8724858-how-do-i-make-an-appointment | diakses 2026-10-01 | Help resmi: "create an account or login to book your appointment instantly" |
| Z7 | https://www.zocdoc.com/patient-help/en/articles/8724683-how-do-i-book-an-appointment-for-someone-else | diakses 2026-10-01 | Help resmi — field pemesanan PII |
| Z8 | https://www.zocdoc.com/patient-help/en/articles/8724732-how-do-i-reschedule-or-cancel-my-appointment | diakses 2026-10-01 | Help resmi — reschedule/batal |
| Z9 | https://www.zocdoc.com/patient-help/en/articles/8814211-what-is-zocdoc-s-cancellation-and-no-show-policy | diakses 2026-10-01 | Help resmi — kebijakan no-show |
| Z10 | https://api-docs.zocdoc.com/guides/patient/book-appointments | diakses 2026-10-01 | Dokumentasi API resmi — skema field & `empty array` bila tanpa slot |
| Z11 | https://pageflows.com/post/desktop-web/booking-an-appointment/zocdoc/ | rekaman versi **Desember 2024**; diakses 2026-10-01 | Pustaka rekaman alur pihak ketiga — 17 layar |
| Z12 | https://pageflows.com/post/desktop-web/onboarding/zocdoc/ | diakses 2026-10-01 | Pustaka rekaman alur pihak ketiga — jalur pendaftaran + booking |
| Z13 | https://uk.trustpilot.com/review/www.zocdoc.com | diakses 2026-10-01 | Platform ulasan (laporan pengguna) |
| Z14 | https://www.complaintsboard.com/zocdoc-b120204 | diakses 2026-10-01 | Platform pengaduan (laporan pengguna/pemasok) |

**Masih aktif 2026:** ya (Z1 2026-09-16, Z2 2026-09-22, Z3 2026-09-17, Z4 blog 2026-04-02). Platform: web + iOS/Android. **Aplikasi native tidak diamati.**

## Langkah yang terlihat

**Titik awal sama: pengguna di profil/jadwal dokter dan ingin memesan slot.**

Rekonstruksi dari rekaman PageFlows (Z11, rekaman **Desember 2024** — usang, ditandai) + help resmi:

1. **Dashboard** → **Cari** (spesialisasi/gejala, lokasi, asuransi) → **Pilih layanan** → **Hasil pencarian** (daftar penyedia + ketersediaan) → **Rincian jadwal**.
2. **Pilih waktu** (slot) → **Konfirmasi booking** → **Tinjau** → **pertanyaan survei "How did you hear about us?"** → **"Feedback submitted"** → **"Appointment confirmed"**.
3. Pascakonfirmasi (Z11/Z12): **"Upload ID card"** → **"Processing"** → **"Uploaded"** → **"Appointment details"**.
4. Jalur pendaftaran yang bisa menyelip sebelum booking (Z12): **Buat akun** → nama → jenis kelamin → Tgl lahir → jenis kelamin? — *label persis di rekaman: "Enter name", "D.O.B.", "Select gender", "Enter phone number", "Enter verification code"* → Dashboard.
5. Gerbang: help resmi Z6 — booking instan mensyaratkan **"create an account or login"**; field pemesanan termasuk **nama legal, tanggal lahir, jenis kelamin** (Z7); API menambah alamat, telepon, email, asuransi (Z10).
6. **BERHENTI: dinding akun/PII.** Tidak ada yang disubmit.

## Hitungan

Titik awal: **pengguna di rincian jadwal/profil dokter, ingin memesan slot**, sampai tugas inti (booking terkonfirmasi). **Konfirmasi tidak pernah tercapai** (dinding akun/PII). Angka dari rekaman 2024 + dokumen → **indikatif, bukan verifikasi 2026**.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar total rekaman desktop (Dashboard → selesai) | **17** | Z11 |
| Layar pre-konfirmasi (Dashboard → Feedback submitted) | **~10**; "Appointment confirmed" = layar ke-11 | Z11 |
| Ketukan | **~6–8** (pilih layanan, penyedia, slot, konfirmasi, tinjau, survei) — **count live TIDAK TERVERIFIKASI** | Z11 |
| Field sebelum konfirmasi | cabang akun **~6** (nama, tgl lahir, jenis kelamin, telepon, kode verifikasi) + asuransi + survei; API resmi: nama, tgl lahir, jenis kelamin, telepon, email, alamat, asuransi | Z12, Z7, Z10 |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **SEBAGIAN** | layar "Loading" & "Processing" ada di rekaman (Z11/Z12) |
| Kosong (tanpa ketersediaan) | **SEBAGIAN di level API** | API resmi mengembalikan `empty array` bila tak ada timeslot; feed availability punya status `none`/`limited`/`available` (Z10). **Copy layar kosong TIDAK TERVERIFIKASI** |
| Error | **TIDAK TERVERIFIKASI** | tidak ada layar error yang diamati/didokumentasikan |
| Sukses | **TERDOKUMENTASI** | "Appointment confirmed" (Z11); email konfirmasi + pengingat (Z6) |
| Offline | **TIDAK TERVERIFIKASI** | — |

## Red flag

1. **Gesekan pengumpulan data sebelum konfirmasi (teramati di rekaman):** pertanyaan survei **"How did you hear about us?"** dan promosi **"Enable Autofill"** diselipkan antara booking dan konfirmasi akhir (Z11/Z12). Termasuk kebisingan/upsell lemah, **bukan** hitung mundur palsu.
2. **Laporan pengguna (bukan fakta terverifikasi):** bombardir email/SMS per booking (Z13); dugaan "closed-loop review" — banner Trustpilot menyebut perusahaan minta ulasan dengan cara yang tak didukung Trustpilot (Z13); pengaduan sisi penyedia soal biaya per-booking (Z14). **Semua = laporan pihak ketiga.**
3. **TIDAK ditemukan:** hitung mundur palsu, tombol batal tersembunyi (pembatalan terdokumentasi di Z8: email "User Obligation" + akun → Appointments → Modify visit).

**Kesimpulan red flag:** tidak ada red flag daftar-keras rubrik yang **terverifikasi** (butir-butir di atas = gesekan teramati di rekaman 2024 / laporan pengguna) → Zocdoc **tidak didiskualifikasi**, tetapi buktinya lemah dan usang. Zocdoc tidak dipakai sebagai sumber langkah mana pun (bukti rekaman Desember 2024, UI live 403).

## Yang TIDAK bisa diverifikasi

- **UI live 2026** (fetch diblokir 403) — urutan layar, label, jumlah klik/field live.
- Layar aplikasi native; semua langkah setelah login (form intake, unggah dokumen).
- **Apakah akun wajib di 2026:** sumber bertentangan — Z6 mensyaratkan akun, sedangkan panduan pihak ketiga menyebut bisa tanpa akun → **TIDAK TERVERIFIKASI**.
- Dinding asuransi/in-network; copy state kosong/error/offline.

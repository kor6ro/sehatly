# F05 Bukti: Calendly (calendly.com)

Tanggal akses semua sumber: **2026-10-01**. Sumber: **web publik, help center resmi, blog/release notes resmi, dokumentasi `llms.txt` (diizinkan robots.txt), dua pustaka rekaman alur pihak ketiga**. Halaman booking publik adalah aplikasi client-side; fetch langsung hanya mengembalikan shell "Calendly" → **UI live tidak diamati**. Tanpa akun, tanpa submit.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| C1 | https://calendly.com/release-notes | entri 09 Jul, 13 & 17 Agu 2026 | Public web — release notes resmi (aktivitas 2026) |
| C2 | https://calendly.com/blog | pos 18–19 Agu 2026 | Public web — blog resmi |
| C3 | https://calendly.com/blog/company-and-product | 2026-08-19 | Blog resmi — "Introducing the new Calendly" |
| C4 | https://www.morningstar.com/news/business-wire/20260819211628/calendlys-new-ai-product-suite-transforms-scheduling-into-a-seamless-meeting-workflow | 2026-08-19 | Press release (Business Wire) |
| C5 | https://calendly.com/ | © 2026 | Halaman produk resmi |
| C6 | https://calendly.com/llms.txt | 2026-10-01 | Indeks mesin resmi (diizinkan robots.txt) |
| C7 | https://calendly.com/help/how-to-book-meetings-in-real-time | diperbarui 2026-09-01 | Help resmi |
| C8 | https://calendly.com/help/advanced-booking-form-features | 2026-10-01 | Help resmi — field wajib: nama + email |
| C9 | https://calendly.com/help/how-to-require-email-verification-for-event-types | diperbarui 2026-08-20 | Help resmi — verifikasi kode 6 digit |
| C10 | https://calendly.com/help/how-to-add-links-to-the-event-confirmation-page | 2026-10-01 | Help resmi — layar konfirmasi |
| C11 | https://calendly.com/help/how-to-turn-off-calendly-branding-on-your-scheduling-page | diperbarui 2026-03-21 | Help resmi — lencana "Powered by Calendly" |
| C12 | https://calendly.com/help/how-to-troubleshoot-unavailable-times-that-should-be-available | diperbarui 2026-08-20 | Help resmi — waktu tidak tersedia |
| C13 | https://calendly.com/help/how-to-include-cancel-and-reschedule-links-for-invitees | 2026-10-01 | Help resmi — tautan batal/reschedule ke invitee |
| C14 | https://calendly.com/help/group-event-type-overview | diperbarui 2026-09-01 | Help resmi — indikator sisa kuota |
| C15 | https://calendly.com/pricing | 2026-10-01 | Halaman harga resmi |
| C16 | https://community.calendly.com/asked-answered-79/disabling-the-powered-by-calendly-banner-4970 | 2026-10-01 | Komunitas resmi — banner berisi kolom email "Sign up for free" di halaman konfirmasi |
| C17 | https://mobbin.com/explore/flows/636defbe-1131-4b81-9ea4-f3c61be84f33 | diakses 2026-10-01 | Pustaka alur pihak ketiga — alur web 6 layar |
| C18 | https://pageflows.com/post/desktop-web/onboarding/calendly | 2026-10-01 | Pustaka alur pihak ketiga — label: Book a time → Select date → Select time → Add details → Booking confirmed |
| C19 | https://calendly.com/circleplus-io/demo (snapshot indeks pencarian; fetch live = shell JS) | snapshot | Halaman booking publik — struktur awal |
| C20 | https://calendly.com/robots.txt | 2026-10-01 | Kebijakan crawl (`Disallow: /*?*`, `/app/`) |

**Masih aktif 2026:** ya (C1 entri Jul–Agu 2026; C2/C3 18–19 Agu 2026; C4 press release 2026-08-19; C5 © 2026). Platform: web + iOS/Android. **Aplikasi native tidak diamati.**

## Langkah yang terlihat

**Titik awal sama: pengguna berada di halaman profil/jadwal penyedia dan ingin memesan slot.** Calendly **menggabungkan profil + booking dalam satu halaman booking publik** (`calendly.com/<user>/<event>`; C19 menunjukkan nama host, nama acara, durasi, deskripsi, "Select a Date & Time").

1. **Layar — halaman booking:** nama/acara, durasi (mis. "30 min"), catatan lokasi ("Web conferencing details provided upon confirmation"), deskripsi, kalender bulan + slot tersedia, indikator zona waktu (auto-detect), lencana **"Powered by Calendly"** secara bawaan (C11, C19).
   - **Aksi:** pilih tanggal dan jam **di halaman yang sama** (C18; konfirmasi dari blog perubahan UI Calendly).
2. **Layar — form booking:** ringkasan tanggal/jam + detail invitee: **nama dan email wajib** (C8); telepon opsional bila dikonfigurasi; pertanyaan kustom opsional; **"Add guests"** (maks 10, bawaan menyala, C8); **pembayaran** (Stripe/PayPal) bila event berbayar (C6/C2).
   - **Aksi:** klik **"Schedule Event."**
3. **(Kondisional) Layar — verifikasi email:** kode 6 digit; "bila invitee tidak menyelesaikan verifikasi, event tidak dibuat" (C9).
4. **Layar — konfirmasi (sukses):** halaman konfirmasi bawaan; tautan reschedule/batal bila host menyalakannya (C13); opsional tautan "jadwalkan acara lain", tautan kustom, atau redirect (berbayar, C10).

## Hitungan

Titik awal: **pengguna tiba di halaman booking publik dengan event yang sudah diketahui**, sampai tugas inti (booking terkonfirmasi). **"Schedule Event" tidak pernah diklik** (meminta PII). Calendly **tidak mensyaratkan akun invitee** — tidak ada dinding akun.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar minimal sampai konfirmasi | **2** (halaman booking → konfirmasi); **3** bila verifikasi email aktif | C10, C9 |
| Rekaman pihak ketiga | **6 layar** (Mobbin, C17) | C17 |
| Ketukan | **~3–4** (pilih tanggal + pilih jam + isi form + "Schedule Event"); +1 bila verifikasi email; Pageflows menampilkan tanggal & jam sebagai langkah terpisah → 4 | C18 |
| Field wajib sebelum konfirmasi | **2** (nama + email), atau **3** bila host memisahkan first/last name (C8); opsional: telepon 0–1, pertanyaan kustom 0–n, tamu 0–n, pembayaran (event berbayar) | C8 |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **TIDAK TERVERIFIKASI** | fetch live hanya shell "Calendly" |
| Kosong (tanpa ketersediaan) | **SEBAGIAN** | tool troubleshooting + FAQ resmi menjelaskan waktu tak tersedia (C12); **copy layar kosong ke invitee TIDAK TERVERIFIKASI** |
| Error | **SEBAGIAN** | verifikasi tak selesai → "event tidak dibuat" (C9); proteksi spam bawaan; **copy error form TIDAK TERVERIFIKASI** |
| Sukses | **TERDOKUMENTASI** | halaman konfirmasi bawaan (C10); tautan reschedule/batal (C13); lencana "Powered by Calendly" bisa muncul juga di konfirmasi (C11) |
| Offline | **SEBAGIAN** | status page (calendlystatus.com) ada; UI offline in-product TIDAK TERVERIFIKASI |

## Red flag

1. **Upsell/lead-capture di layar sukses invitee (terverifikasi, resmi).** Secara bawaan lencana **"Powered by Calendly"** muncul di halaman penjadwalan **dan di booking konfirmasi** (C11); sumber komunitas resmi (C16) menyebut banner itu "includes the '**Sign up for free**' email field on the confirmation page and the invitee email". Pemasangan berbayar untuk menghapusnya (C11, C15). *Kategori: upsell di layar konfirmasi — TIDAK menutupi aksi utama booking, sehingga **bukan** red flag daftar-keras rubrik; tetap dicatat sebagai kebiasaan yang ditolak.*
2. **Indikator kelangkaan opsional** "Display remaining spots" untuk event grup (C14) — data asli, dikonfigurasi host.
3. **Pembayaran di muka** (Stripe/PayPal) untuk event berbayar (C6) — gesekan, bukan pola menipu.
4. **TIDAK ditemukan:** hitung mundur palsu, tombol batal tersembunyi (tautan batal di-dokumentasikan, C13 — catatan: "Calendly will display your cancellation policy, but it doesn't restrict cancellations"), consent terselip teramati.

**Kesimpulan red flag:** tidak ada red flag daftar-keras rubrik yang terbukti → **Calendly layak jadi sumber pola** (dengan butir 1 masuk daftar "tidak ditiru").

## Yang TIDAK bisa diverifikasi

- **UI live terender** (halaman = shell JS): urutan/label persis halaman asli.
- Layar aplikasi mobile; isi layar pembayaran event berbayar.
- Copy pasti state kosong dan error ke invitee.
- Konfigurasi spesifik tiap halaman (verifikasi email, tamu, durasi) — semuanya diatur host.
- Pengiriman kalender/pengingat pascabooking (hingga dokumentasi).

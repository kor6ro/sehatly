# F05 Bukti: Practo (practo.com)

Tanggal akses semua sumber: **2026-10-01**. Sumber: **web publik (profils dokter diamati langsung), help center resmi, blog resmi, berita, blog pihak ketiga**. Modal slot dibuka lewat JS dan **tidak terender ke fetch** → detail layar slot tidak diamati. Tanpa akun, tanpa login, tanpa submit.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| P1 | https://blog.practo.com/strengthening-practos-leadership-for-our-next-phase-of-growth/ | 2026-09-17 | Blog resmi (aktivitas 2026) |
| P2 | https://www.financialexpress.com/business/news/practo-bets-on-infrastructure-model-ahead-of-ipo/4250065/ | 2026-05-24 | Berita bisnis |
| P3 | https://timesofindia.indiatimes.com/business/india-business/practo-appoints-new-ceo-shashank-nd-becomes-exec-chairman/articleshow/134314023.cms | 2026-09-17 | Berita |
| P4 | https://www.practo.com/ | 2026-10-01 | Public web — homepage, diamati langsung |
| P5 | https://www.practo.com/bangalore/doctors | 2026-10-01 | Public web — daftar dokter, diamati langsung |
| P6 | https://www.practo.com/bangalore/doctor/dr-deepthi-26-dentist?practice_id=1428535 | 2026-10-01 | Public web — profil dokter, diamati langsung |
| P7 | https://help.practo.com/practo-search/what-is-practo-instant/ | diakses 2026-10-01 | Help resmi — urutan alur "Book" |
| P8 | https://help.practo.com/practo-prime/faqs-for-practo-prime-patients/ | diakses 2026-10-01 | Help resmi — booking Prime, 5 langkah |
| P9 | https://help.practo.com/practo/patient-rights-and-responsibilities/ | diakses 2026-10-01 | Help resmi — hak/kewajiban pasien |
| P10 | https://help.practo.com/practo/practo-faq/ | diakses 2026-10-01 | FAQ resmi |
| P11 | https://www.practo.com/company/security | 2026-10-01 | Halaman resmi — consent pemasaran default-in (opt-out) |
| P12 | https://help.practo.com/practo-search/practo-patient-faqs/ | diakses 2026-10-01 | FAQ pasien — ID janji via SMS/email |
| P13 | https://help.practo.com/practo-prime/faqs-for-cashless-appointments-providers/ | diakses 2026-10-01 | Help resmi — denda telat/batal ₹50; auto no-show 72 jam |
| P14 | https://help.practo.com/terms/practo-terms-and-conditionsuae/ | diakses 2026-10-01 | S&K resmi — batal/refund/no-show |
| P15 | https://doctors.practo.com/new-patient-journey-patients-book-appointments/ | 2014 (blog resmi, usang) | Blog klinisi — narasi alur |
| P16 | https://medium.com/harshaparuchuri/redesigning-appointment-booking-experience-for-mobile-web-users-5956bb49d1ac | 2020 | Studi kasus desainer Practo |
| P17 | https://www.cufront.com/blog/practo-ray-pricing-india-worth-it-2026 | 2026-05-23 | Blog pihak ketiga — biaya sisi penyedia (bukan alur pasien) |

**Masih aktif 2026:** ya (P1 2026-09-17; P3 2026-09-17; P2 2026-05-24; situs live diakses 2026-10-01 — P4/P5/P6). Platform: web + iOS/Android. **Aplikasi native tidak diamati.**

## Langkah yang terlihat

**Titik awal sama: pengguna di profil dokter dan ingin memesan slot.**

Diamati langsung di profil (P6, 2026-10-01):

1. **Layar — profil dokter:** CTA **"Book Appointment"** dekat biaya (**₹300**), lencana **"Instant Pay Available"**, dan blok **"Pick a time slot"** berisi **"Clinic Appointment — ₹300 fee"**. Pada daftar (P5) terlihat varian **"Clinic Appointment"** dan **"Video Consultation"** (prompts "Choose the type of appointment" untuk dokter dengan dua mode), plus teks **"Fees are payable at clinic. There are NO charges for booking an appointment"** pada sebagian dokter.

Didokumentasikan help resmi (P7, P8, P15):

2. Klik **"Book Appointment"** → pemilih slot terbuka (modal JS). Bila ada >1 mode → **"Choose the type of appointment"** (klinik/video).
3. **Pemilihan slot:** "patient will see the doctor's available time slots. The ones already booked are **greyed out**… select an available appointment slot" (P7).
4. **Form detail:** "Patient now fills in all appointment details, which are sent to the practice" (P7); tujuan: mengambil **kontak pasien untuk verifikasi pasien asli** (P15). Jumlah & nama field di 2026: **TIDAK TERVERIFIKASI**.
5. **Konfirmasi:** "patient gets a **Guaranteed appointment** as per our Practo Guarantee Program" (P7).
6. **Pascakonfirmasi (dokumen):** konfirmasi + pengingat **SMS & email** ke pasien dan dokter, tautan batal/reschedule di pesan, janji masuk kalender Ray (P7); ID janji via SMS/email (P12).

**BERHENTI:** di form detail (meminta PII) — tidak disubmit. Modal slot tidak terender ke fetch.

Alur chat online: **TIDAK TERVERIFIKASI di web** (Practo Doctor + telemedicine tidak teramati dalam sesi ini).

## Hitungan

Titik awal: **pengguna di profil dokter, ingin memesan slot**, sampai tugas inti (booking terkonfirmasi). **Konfirmasi tidak pernah tercapai.**

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar dari profil | **~2–3** (profil → pemilih tipe/slot → detail/konfirmasi → halaman sukses) — **count live TIDAK TERVERIFIKASI** (modal JS) | P6, P7 |
| Ketukan | **~2–4** (Book Appointment → [pilih tipe] → pilih slot → konfirmasi) — **count live TIDAK TERVERIFIKASI** | P7 |
| Field sebelum konfirmasi | **TIDAK TERVERIFIKASI** (help hanya menyebut "all appointment details" / "contact details"); banyak dokter tanpa pembayaran online ("Fees are payable at clinic") | P7, P15, P5 |
| Langkah "Prime" (dokumen) | 5 langkah, termasuk item pascabooking (lihat arah, temui dokter, follow-up) | P8 |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **TIDAK TERVERIFIKASI** | tidak diamati/didokumentasikan |
| Kosong (tanpa ketersediaan) | **SEBAGIAN** | slot yang sudah dibooking **di-abu-abukan** (P7); **state "tidak ada slot sama sekali" TIDAK TERVERIFIKASI** |
| Error | **TIDAK TERVERIFIKASI** | tidak ada state error konsumen yang didokumentasikan |
| Sukses | **TERDOKUMENTASI** | konfirmasi + pengingat SMS/email, Practo Guarantee, "My appointments" (P7, P8, P12) |
| Offline | **TIDAK TERVERIFIKASI** | — |

## Red flag

1. **Upsell tier berbayar dekat aksi utama (teramati/dokumentasi).** Lencana **"Prime"** dan banner **"Practo One"** berdampingan dengan CTA booking (P6); help resmi menyebut booking non-Prime: **"No instant booking… No assurance that you will meet the doctor you booked"** (P8) — asimetri yang mendorong tier berbayar. Apakah banner **menutupi** CTA: TIDAK TERVERIFIKASI → **tidak termasuk red flag daftar-keras "upsell menutupi aksi utama"**.
2. **Consent pemasaran default-in (didokumentasikan, P11):** pasien dianggap memberi izin dihubungi untuk pemasaran, opt-out belakangan. *Ini consent yang **diungkapkan** (bukan tersembunyi); dicatat sebagai kelemahan privasi, bukan red flag keras.*
3. **Denda no-show/telat (didokumentasikan, P13):** klinik menahan ₹50 bila telat batal/no-show; janji tak dipindai **72 jam → auto no-show**. Denda muncul **di dokumentasi**, bukan setelah komitmen di layar yang diamati.
4. **Pelarangan akun berulang no-show** (P14). **Anekdot pengguna (opini):** pembatasan metode bayar (P17 & ulasan Play Store).
5. **TIDAK ditemukan:** hitung mundur palsu, tombol batal tersembunyi (batal/reschedule didokumentasikan di P7/P13/P14).

**Kesimpulan red flag:** tidak ada red flag daftar-keras rubrik yang **terverifikasi** → **Practo layak jadi sumber pola** (butir 1–3 masuk daftar "tidak ditiru" / catatan).

## Yang TIDAK bisa diverifikasi

- **Layar modal slot live** (JS tidak terender ke fetch) — urutan layar & jumlah ketukan pasti.
- **Daftar field form booking** (nama/telepon/email/OTP?) di 2026.
- Apakah booking mensyaratkan login/akun (TIDAK TERVERIFIKASI).
- Layar aplikasi native; layar "My appointments"; layar pembayaran ("Instant Pay").
- State loading/error/empty-konteks dan offline.
- Apakah artikel help (tak bertanggal) masih 100% berlaku untuk UI 2026.

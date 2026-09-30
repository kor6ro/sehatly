# F05 Bukti: Doctolib (doctolib.fr)

Tanggal akses semua sumber: **2026-10-01**. Sumber: **web publik, help center resmi, berita, regulator, lembar robots.txt**. **Tidak ada akun, tidak ada login, tidak ada submit.** Doctolib memblokir akses otomatis (HTTP 403) dan `robots.txt` melarang `/appointments/`, `*/booking/*`, `/sessions/`, `/api/`, `/search` → **UI live tidak diamati**; langkah di bawah berasal dari dokumentasi resmi + materi pihak ketiga, ditandai eksplisit.

## Sumber

| # | URL | Tanggal | Jenis sumber |
|---|---|---|---|
| D1 | https://about.doctolib.com/news/ | item berita 06 Mei 2026 | Public web — newsroom resmi (aktivitas 2026) |
| D2 | https://www.doctolib.fr/ | © 2026; "26 juta pengguna/bulan (data Mei 2025)" | Public web — halaman produk resmi |
| D3 | https://www.thelocal.fr/20260804/why-doctolib-users-in-france-are-being-asked-about-ai-research | 2026-08-04 | Berita — persetujuan riset AI kesehatan (default ikut, opt-out lewat form) |
| D4 | https://www.autoritedelaconcurrence.fr/en/article/autorite-fines-doctolib-eu4665000-abusing-its-dominant-position-online-medical-appointment | 2025-11-06 | Regulator — denda €4.665.000 |
| D5 | https://doctolibpatient.zendesk.com/hc/fr/articles/14141893264924-How-can-I-book-an-appointment-online | diperbarui 2024-07-30 (dibaca via indeks pencarian; fetch langsung 403) | Help resmi — langkah booking |
| D6 | https://doctolibpatient.zendesk.com/hc/fr/articles/360025316194-Prendre-et-confirmer-un-rendez-vous-sur-Doctolib | 2025-07-30 (via indeks pencarian) | Help resmi — langkah booking & konfirmasi |
| D7 | https://www.doctolib.de/gesundheit/how-to-book-a-doctors-appointment/ | 2026-06-29 (via indeks pencarian; fetch 403) | Help resmi — langkah booking |
| D8 | https://doctolibpatient.zendesk.com/hc/it/articles/14141811417500-Create-my-Doctolib-account | 2024-08-29 (via indeks pencarian) | Help resmi — field pendaftaran akun |
| D9 | https://doctolibpatient.zendesk.com/hc/fr/articles/360025470173-M-inscrire-%C3%A0-la-liste-d-attente | 2025-09-19 (via indeks pencarian) | Help resmi — daftar tunggu |
| D10 | https://media.doctolib.com/image/upload/mkg/file/patienteninformation_und_einwilligung_recall_sms_emails_eng.pdf | tanpa tanggal | Pemberitahuan mitra resmi: "A Doctolib user account is required for online appointment booking" |
| D11 | https://www.doctolib.fr/robots.txt | 2026-10-01 | Kebijakan crawl |
| D12 | https://status.doctolib.com/ | 2026-10-01 | Status page |
| D13 | https://www.doctolib.fr/medecin-generaliste/paris/gabriel-allali | snapshot indeks pencarian | Profil publik — state "reservasi tidak tersedia" |
| D14 | https://www.doctolib.fr/account/appointments | snapshot indeks pencarian | Halaman akun — dinding akun + teks error captcha |
| D15 | https://apps.apple.com/ca/app/doctolib-your-health-partner/id925339063 | ulasan 11 Jun | Store listing — laporan pengguna "mustahil memesan" |
| D16 | https://caroline-graver.medium.com/lets-see-how-doctolib-s-app-user-flow-works-14d41cd5453d | 2020 | Teardown pihak ketiga (usang) |

**Masih aktif 2026:** ya (D1 item Mei 2026; D2 © 2026; D3 Agustus 2026; D4 regulator Nov 2025). Platform: web + iOS/Android. **Aplikasi native tidak diamati.**

## Langkah yang terlihat

**Titik awal sama: pengguna di profil praktisi dan ingin memesan slot.**

Alur web (dari dokumentasi resmi D5/D6/D7 — direkonstruksi, bukan pengamatan live):

1. **Layar — profil praktisi**: alamat, biaya/sektor asuransi, peta, tombol "Prendre rendez-vous". Sebagian profil memang menampilkan state diblokir: *"La réservation n'est pas disponible. Pour plus d'informations, appelez le : 01 43 42 50 51."* (D13) → pengguna diarahkan menelepon.
2. **Layar — dinding akun**: "Log in / create your Doctolib account" adalah langkah resmi ke-2 (D5/D6). Akun **wajib** untuk booking online (D10).
   - Layar pendaftaran (D8): email **atau** telepon → nama depan, nama belakang, tanggal lahir, jenis kelamin → kata sandi → nomor telepon → kode verifikasi → checkbox wajib "saya menerima Persyaratan Penggunaan" → checkbox pemasaran opsional.
   - **Pengguna tanpa akun: BERHENTI di sini.**
3. **(Bila sudah login, didokumentasikan D5/D6/D7)** klik "Book an appointment" → pilih tatap muka vs video → pilih alasan kunjungan → pilih hari & jam (slot ditahan **15 menit** untuk konfirmasi) → identitas pasien ("untuk Anda atau kerabat?"; untuk kerabat nama belakang ibu & tempat lahir wajib) → deklarasi/consent ("saya adalah perwakilan sah…", "saya membaca instruksi") → "Konfirmasi".
4. **Hasil (dokumen)**: janji masuk "My appointments"; email konfirmasi ≤10 menit; pengingat email/push/SMS 24–48 jam sebelum; opsi daftar tunggu di layar konfirmasi (D9).

## Hitungan

Titik awal: **pengguna di profil praktisi, ingin memesan slot**, sampai tugas inti (booking terkonfirmasi) — **konfirmasi tidak pernah tercapai** (dinding akun). Angka = dokumentasi resmi, bukan DOM live.

| Metrik | Nilai | Dasar |
|---|---|---|
| Layar sampai berhenti (tanpa akun) | **2** (profil → login/daftar) | D5/D6, D10 |
| Ketukan sampai berhenti | ~1–2 | D5/D6 |
| Field sebelum konfirmasi (pembuatan akun) | **~7–8** (email/telepon, nama depan, nama belakang, tgl lahir, jenis kelamin, kata sandi, telepon, kode verifikasi) + 2 checkbox | D8 |
| Layar bila sudah login (didokumentasi) | **~6–8** (profil → [faskes] → tipe → alasan → jadwal → identitas/pertanyaan → konfirmasi → konfirmasi-informasi) | D5/D6/D7 |
| Ketukan bila sudah login | ~6–9 | D5/D6/D7 |
| Field booking bila sudah login | ~4–7 (alasan, tanggal, jam, identitas; tempat lahir wajib untuk kerabat; 2 checkbox) | D6/D9 |
| Konfirmasi tercapai | **TIDAK** | — |

## State yang terlihat

| State | Status | Bukti |
|---|---|---|
| Loading | **TIDAK TERVERIFIKASI** | UI live terblokir (403) |
| Kosong (tanpa ketersediaan) | **SEBAGIAN** | D13: "La réservation n'est pas disponible… appelez le: …" (negatif + aksi telepon); topik help "Mon soignant n'a pas de disponibilité en ligne"; daftar tunggu (D9) |
| Error | **SEBAGIAN** | D14: teks error "Échec de la résolution du Captcha"; ulasan pengguna D15 (11 Jun): "Impossible to book an appointment… showing appointments available they're impossible to book" (**laporan pengguna**) |
| Sukses | **TERDOKUMENTASI** | email konfirmasi ≤10 menit, "My appointments", pengingat 24–48 jam (D5/D6) |
| Offline | **SEBAGIAN** | status page ada dengan komponen "Operational" (D12); UI offline in-product TIDAK TERVERIFIKASI |

## Red flag

1. **Consent terbalik untuk riset AI di data kesehatan (terverifikasi).** Pengguna **otomatis ikut kecuali aktif menolak**; opt-out lewat form terpisah dengan nama + tanggal lahir (D3, 2026-08-04). → **"Consent terselip"** pada data kesehatan.
2. **Dinding akun sebelum slot** (D10): booking online tidak mungkin tanpa akun + PII.
3. **Hitung mundur penahanan slot 15 menit** (D5/D6): **bukan hitung mundur palsu** (menahan slot sungguhan), tapi menciptakan tekanan waktu.
4. **Konduksi pasar:** denda otoritas persaingan Prancis €4.665.000 atas klausul eksklusivitas (D4) — bukan pola UI, dicatat sebagai konteks.

**Kesimpulan red flag:** butir 1 termasuk daftar-keras rubrik (**consent terselip** pada data kesehatan) → **Doctolib DIDISKUALIFIKASI sebagai sumber pola untuk ditiru** (tetap dinilai di `web/ux/scores/F05.md`; temuannya masuk daftar "yang tidak ditiru").

## Yang TIDAK bisa diverifikasi

- Semua layar UI live (diblokir 403; `robots.txt` D11 melarang `/appointments/`, `*/booking/*`) dan **seluruh layar setelah login**.
- Layar aplikasi native.
- State loading/offline in-product; copy error spesifik di alur booking.
- Jumlah field pasti pada form booking praktisi (berbeda per praktisi).
- Apakah slot bisa dipilih sebelum login di web (dokumen menyebut login dulu; perilaku live tak teramati).

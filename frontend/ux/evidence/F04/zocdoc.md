# Evidence F04 — Zocdoc (profil dokter & kepercayaan) — diblokir, bukti dokumentasi + cache

Tanggal akses semua sumber: **2026-10-02**. Profil langsung **HTTP 403** (bot-wall, tanpa bypass). Fakta dari halaman resmi + cuplikan mesin pencari atas profil.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.zocdoc.com/doctor/sylvia-mohen-md-672032 | Profil | **HTTP 403**; konten via cuplikan indeks |
| S2 | https://www.zocdoc.com/about/verifiedreviews/ | Halaman resmi ulasan terverifikasi | Aktif |
| S3 | https://www.zocdoc.com/about/how-search-works/ | Halaman resmi verifikasi provider | Aktif |
| S4 | https://www.zocdoc.com/provider-help/en/articles/8834777-getting-started-with-reviews | Help provider resmi | Aktif |

## Fakta terlihat — profil (S1 via cuplikan indeks; Dr. Sylvia Mohen, MD, Neurologist)

- Header: rating **"4.88"**; **"93% of patients gave this doctor 5 stars"**; **"See all 1139 reviews"**.
- Tab: Highlights / About / Insurances / Locations / Reviews / FAQs.
- CTA: **"Book an appointment for free"**.
- Asuransi: "In-network insurances"; **"99% of patients have successfully booked with these insurances"**.
- Kredensial: Board certification ("Neurology (American Board of Psychiatry and Neurology)"); pendidikan (medical school, residency, fellowship, internship); **NPI "1962790907"**.
- Ulasan: "All reviews have been submitted by patients after interacting with the practice."; sub-skor FAQ: **"4.91/5 bedside manner, 4.61/5 wait time"**.

## Mekanisme verifikasi (S2–S4, resmi)

- **Closed-loop:** hanya pasien yang memesan dan kehadirannya dikonfirmasi provider dapat mengulas; *"Every review is written by an actual patient"*.
- Dua jenis: **Zocdoc Patient Reviews** + **Partner Reviews** (survei pihak ketiga, mis. Press Ganey); Partner Reviews **"clearly designated as such"**.
- Ulasan diterima hingga **120 hari** setelah janji; moderasi manusia; **provider boleh memilih tidak menampilkan ulasan**; *"patients are 30% more likely to book with a provider with at least 30 reviews"*; bila <30 ulasan, provider dapat meminta ulasan dari pasien non-Zocdoc.
- Verifikasi provider (S3): lisensi aktif & good standing di negara bagian, spesialisasi, pendidikan kedokteran, board certification dicek sebelum tampil; pemetaan spesialisasi/alasan kunjungan diverifikasi konsultan medis pihak ketiga.

## State parsial/kosong

Tidak teramati langsung. Berbasis aturan: provider bisa opt-out menampilkan ulasan; ambang 30 ulasan mengubah cara pengumpulan ulasan.

## Red flag

- **Ulasan dapat disembunyikan atas pilihan provider** — transparansi sosial berkurang.
- Ambang <30 ulasan memicu pengumpulan ulasan dari pasien non-platform → komposisi sumber ulasan bergeser.
- Bot-wall 403 membatasi verifikasi independen (konten hanya via cache + dokumen).

## Yang TIDAK bisa diverifikasi

Nama/inisial/tanggal/badge reviewer di daftar ulasan; pagination/sort/filter; tata letak profil 0 ulasan; rendering label "Partner Reviews"; perilaku login.

## Independensi sumber

S2–S4 = Zocdoc; S1 = cache mesin pencari (bukan penerbit independen). Tidak memenuhi syarat pemenang; sumber pola **ulasan closed-loop**, **sub-skor**, dan **label sumber ulasan**.

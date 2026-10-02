# Evidence F04 — Practo (profil dokter & kepercayaan) — teramati penuh

Tanggal akses semua sumber: **2026-10-02**. Native app tidak dibuka. Jenis: halaman web publik (terender) + help resmi.

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.practo.com/bangalore/doctor/dr-laxmi-devi-gynecologist-obstetrician?practice_id=657161 | Profil dokter | HTTP 200 |
| S2 | .../recommended | Tab ulasan | HTTP 200 |
| S3 | https://help.practo.com/practo-profile/guidelines-for-creating-profiles/ | Help resmi kredensial | Aktif |
| S4 | https://help.practo.com/practo-feedback/practo-feedback-guidelines-for-healthcare-service-providers/ | Help resmi ulasan | Aktif |

## Fakta terlihat (S1/S2)

- **Di atas fold:** foto, nama, kualifikasi "MBBS, MS - Obstetrics & Gynaecology", spesialisasi, **"20 Years Experience Overall (15 years as specialist)"**, badge **"Medical Registration Verified"**, skor **"95% (351 patients)"**.
- Skor dokter = **persentase + jumlah pasien**; kliniknya menampilkan **bintang 4,5** — dua format di satu halaman.
- **Ulasan:** "351 Patient Stories for Dr. Lakshmi Devi"; disclaimer "These are patient's opinions and do not necessarily reflect the doctor's medical capabilities"; **"Filter by health problem/treatment"**; **"Sort By: Most Helpful"**; "351 results".
- Identitas reviewer: nama depan/inisial + **"(Verified)"**; tanggal relatif ("a year ago", "8 years ago"); vonis **"I recommend the doctor" / "I do not recommend"**; tag "Happy with:"; **sebagian teks dimasking bintang**; balasan dokter terlihat; "Show all stories (351)"; pagination "More".
- **Kredensial:** Pendidikan (MBBS JJMMC 2006; MS Bangalore Medical College 2010), Membership (BSOG, FOGSI), Awards, Experience, dan **"Registrations: 74074 Karnataka Medical Council, 2006"** (nomor + konsil + tahun **ditampilkan publik**).
- Layanan: "126 Surgeries & Treatments"; blok FAQ "Common questions & answers".
- **CTA:** "Pick a time slot" → "Clinic Appointment ₹500 fee", "Change Clinic", "Call Now".
- **Mekanisme verifikasi (S3/S4):** profil wajib memuat nomor registrasi, konsil, tahun + bukti; *"Every registration detail submitted to us is verified against different medical councils across states, before making the profile live"*; *"Doctors with verified profiles get >95% patient views."* Ulasan diminta via SMS/WhatsApp/Email **≤4 hari setelah janji**; satu ulasan per janji; *">1000 Feedback verified manually every day"*; **skor rekomendasi tampil hanya bila ≥10 rekomendasi; skor pengalaman janji tampil hanya bila ≥5 feedback**; feedback tidak dihapus bila dokter pindah klinik.

## State parsial/kosong

Berbasis aturan: skor disembunyikan bila <10 rekomendasi / <5 feedback. Halaman dokter baru tanpa skor: **TIDAK TERVERIFIKASI** (tidak diamati langsung).

## Red flag

- Ulasan terverifikasi bisa berumur ~8–10 tahun → skor mencampur pengalaman lama & baru.
- Dokter "%" vs klinik "bintang" = bahasa rating campur.
- Masking (`*****`) mengaburkan isi ulasan yang tetap dipublikasikan.
- Banner upsell "Prime" + cross-sell "₹99 consult" bersebelahan dengan CTA pemesanan.

## Yang TIDAK bisa diverifikasi

Nilai bintang di balik "95%" (dokter tidak menampilkan bintang); glyph verifikasi selain teks "(Verified)".

## Independensi sumber

S1–S4 = **satu penerbit: Practo**. Tidak memenuhi syarat pemenang; **kandidat F04 terkuat secara isi** — kredensial + nomor registrasi publik, ulasan hanya dari janji terverifikasi, filter/sort ulasan, batas minimum sebelum menampilkan skor.

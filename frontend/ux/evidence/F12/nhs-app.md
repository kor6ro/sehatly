# Evidence F12 — NHS App (reschedule + cancel janji; telemedicine global)

Tanggal akses semua sumber: **2026-10-02**. Sumber resmi NHS (publik). NHS App adalah kandidat terkuat untuk **alur jadwal ulang janji rumah sakit** dan **status “menunggu review”**, dan satu-satunya kandidat F12 dengan pernyataan aksesibilitas terukur.

## Sumber

| # | URL | Jenis | Versi/platform | Catatan akses |
|---|---|---|---|---|
| S1 | https://www.nhs.uk/nhs-app/help/appointments/hospital-and-other-appointments | Bantuan resmi NHS App — janji rumah sakit/spesialis | Web; **halaman ditinjau 18 Mar 2026** | Dibaca penuh 2026-10-02 |
| S2 | https://www.nhs.uk/nhs-app/help/appointments/managing-gp-appointments | Bantuan resmi — kelola janji GP | Web; **ditinjau 19 Agu 2026** | Dibaca penuh 2026-10-02 |
| S3 | https://www.nhs.uk/nhs-app/help/appointments | Indeks bantuan janji temu NHS App | Web; diperbarui 20 Agu 2026 | Cuplikan indeks 2026-10-02 |
| S4 | https://www.nhs.uk/nhs-app/about/privacy-legal-information/nhs-app-accessibility-statement/ | Pernyataan aksesibilitas NHS App **v10.1** | Web; ditinjau 22 Des 2025; uji WCAG 2.2 AA Okt 2025 oleh Dig Inclusion atas **35 layar** | Dibaca penuh 2026-10-02 |
| S5 | https://digital.nhs.uk/services/nhs-app/nhs-app-features/appointments | Halaman fitur NHS England Digital (untuk staf) | Web | **Hanya cuplikan indeks:** rumah sakit dapat menetapkan **batas waktu pemberitahuan** untuk pembatalan; pasien dapat membatalkan meski tidak memesan lewat app |
| S6 | https://www.whh.nhs.uk/patients-and-visitors/appointments/managing-appointments-online | Halaman resmi **NHS Foundation Trust pihak ketiga** (North Cheshire and Mersey) | Web; “Last updated: Tuesday 26 May 2026” | **Hanya cuplikan indeks:** “You will be able to request to cancel or reschedule your appointment via the NHS App” 2026-10-02 |
| S7 | https://www.nth.nhs.uk/patients/manage-your-appointments/appointments | Halaman resmi **NHS Trust pihak ketiga** (North Tees and Hartlepool) | Web; **3 Mar 2026** | **Hanya cuplikan indeks:** “You can also now use the free, secure NHS app to manage your appointments” 2026-10-02 |

## Langkah terlihat (fakta)

**Jadwal ulang janji rujukan rumah sakit (S1) — 7 langkah:**
1. **Appointments** → 2. **Hospital and specialist appointments** → 3. pilih janji → 4. **Ask to reschedule appointment** → 5. **pilih tanggal & waktu baru** → 6. **pilih alasan** mengapa perlu reschedule → 7. review → **submit request to reschedule**.
- Alasan **wajib** untuk janji umum; untuk **kesehatan mental tidak wajib** (S1).
- **Permintaan tidak langsung mengubah janji.** Penyedia **dapat meninjau**: “While your healthcare provider is reviewing your request the appointment will show as **pending**.” Jika **tidak diterima**, janji **tetap seperti semula (booked)**; jika **diterima**, tampil waktu baru **atau** dibatalkan (S1).
- Hanya janji rujukan **pertama** yang dapat dikelola di app (S1).

**Membatalkan janji rujukan (S1) — 6 langkah:**
1–3 pilih janji → 4. **Ask to cancel appointment** → 5. **pilih alasan** → 6. review → request cancellation.
- Peringatan eksplisit di muka: “If you request to cancel an appointment **you may not be able to change your mind**. Depending on your healthcare provider they may **remove you from the waiting list** for care. If this happens and you then decide you want an appointment, **you will need a new referral**.” (S1)

**Janji GP (S2):**
- GP: **hanya lihat & batalkan** — 3 langkah: Appointments → **Manage GP appointments** → **Cancel this appointment**.
- **Batas waktu:** “If you try to cancel too close to the time of the appointment, your GP surgery **may not allow you to cancel it using the app**. Contact your GP surgery if you cannot cancel your appointment online.” (S2)
- **Jadwal ulang GP = batal + pesan baru**: “If you want to reschedule an upcoming appointment, **you'll need to cancel it and book a new appointment**.” (S2) — tidak ada alur reschedule di GP.
- Tidak ada janji yang tampil bila poli tidak mengaktifkan booking online / janji sudah dibatalkan (S2).

## Hitungan (titik awal yang sama)

| Besaran | Nilai | Dasar |
|---|---|---|
| Langkah reschedule (rumah sakit) | **7 langkah** (dari Appointments sampai submit) | S1 |
| Field reschedule | tanggal+waktu baru (pilih), alasan (pilih; wajib kecuali keswa) | S1 |
| Langkah batal (rumah sakit) | **6 langkah** | S1 |
| Langkah batal (GP) | **3 langkah** | S2 |
| Jadwal ulang GP | 2 alur terpisah: batal (3) + pesan baru | S2 |
| State menunggu | janji tampil **“pending”** selama ditinjau | S1 |

## State terlihat

- **Menunggu review (pending):** state eksplisit, bukan spinner (S1). ✓
- **Ditolak:** “the appointment will **stay as booked**” — pemulihan aman (S1). ✓
- **Diterima:** waktu baru atau dibatalkan (S1). ✓
- **Terlambat batal:** tombol app tidak mengizinkan; arahan menghubungi faskes (S2). ✓
- **Informasi tidak lengkap:** bagian “Missing or incorrect information” menjelaskan janji yang tidak tampil tetap berjadwal (S1). ✓
- Loading/kosong/error/offline: tidak didokumentasikan → N/V.

## Red flag

- Tidak ada dark pattern: batas & akibat dinyatakan **sebelum** konfirmasi (peringatan daftar tunggu), alasan diminta transparan, hasil review jujur.
- **Yang tidak boleh disalin:** (a) **efek ireversibel “dikeluarkan dari waiting list”** — konteks NHS gratis; Sehatly berbayar, pembatalan tidak boleh menghukum di luar kebijakan biaya yang disetujui; (b) **reschedule GP = cancel + rebook** — untuk booking berbayar ini berbahaya (slot & uang hilang); Sehatly memakai reschedule atomik; (c) **permintaan reschedule yang bisa ditolak** — Sehatly memilih slot dokter yang tersedia secara real-time, jadi konfirmasi harus langsung (kecuali dokter perlu menyetujui, yang harus dinyatakan di muka).

## Yang TIDAK bisa diverifikasi

1. UI native NHS App (visual) — langkah tekstual + video; tidak ada screenshot diambil.
2. Alasan pembatalan dalam bentuk apa (kode vs teks bebas) — tidak dinyatakan.
3. Berapa lama review berlangsung; tidak ada SLA.
4. Apakah pembatalan di app setelah “too close to time” masih mungkin lewat telepon (dinyatakan ada kontak, bukan hasil).
5. Jam berapa jendela pembatalan ditutup (angka) — S5 menyebut faskes menetapkan batas, tanpa angka.

## Independensi sumber

- S1–S4 `nhs.uk`; S5 `digital.nhs.uk` → keduanya **NHS England** = satu organisasi penerbit.
- S6 (`whh.nhs.uk`, NHS Foundation Trust North Cheshire and Mersey) dan S7 (`nth.nhs.uk`, North Tees and Hartlepool NHS Foundation Trust) adalah **badan NHS terpisah** yang mengonfirmasi kemampuan pasien membatalkan/menjadwalkan ulang lewat NHS App — **2 penerbit independen** dari NHS England (mengikuti konvensi `scores/F11.md` yang menghitung panduan faskes sebagai penerbit terpisah). Keduanya **hanya cuplikan indeks**, jadi memperkuat **keberadaan alur**, bukan angka langkah.
- Pernyataan aksesibilitas (S4) adalah bukti **terukur** (penguji eksternal), bukan klaim pemasaran.
- Dengan S6/S7: syarat **≥2 sumber independen terpenuhi** (NHS England + 2 trust). Detail langkah tetap satu penerbit (NHS England).

# F10 — Epic MyChart (Health records / Document Center / Sharing Hub)

Flow: pasien sudah login → menemukan rekam medis lama → membacanya → mengunduh/membagikannya.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.mychart.org/ [akses 2026-10-01] | Situs vendor resmi (Epic Systems) | Portal pasien MyChart; status aktif 2026 |
| S2 | https://www.mychart.org/l/en-us/features/share/ [akses 2026-10-01] | Halaman fitur resmi | Request formal copy, computer-readable format, Share Everywhere, Happy Together |
| S3 | https://www.mychart.org/l/en-us/help/ [akses 2026-10-01] | Help center resmi | Indeks artikel bantuan |
| S4 | https://www.mychart.org/l/en-us/help/download-results/ [akses 2026-10-01] | Artikel bantuan resmi | Langkah bernomor unduh hasil tes (PDF) |
| S5 | https://www.mychart.org/l/en-us/features/view-medications-test-results-bills/ [akses 2026-10-01] | Halaman fitur resmi | Test results + rentang normal + komentar dokter |
| S6 | https://www.hopkinsmedicine.org/-/media/patient-care/documents/mychart/download-health-records-tip-sheet.pdf [akses 2026-10-01 via indeks pencarian] | Panduan pasien dari faskes (Epic Training, bertopang Johns Hopkins), 2022 | **Fetch langsung = 403**; langkah bernomor Sharing Hub → Document Center |
| S7 | https://middlesexhealth.org/files/dmHTMLFile/mychart-patient-quick-start-guide-1.pdf [akses 2026-10-01 via indeks pencarian] | Panduan pasien faskes | Health > Document Center; Requested Records > Download; ekstrak .zip |

- **Batasan riset:** portal MyChart di balik login; menu bervariasi per faskes ("Each organization may customize MyChart" — dikutip dari halaman panduan faskes via indeks pencarian 2026-10-01). App mobile tidak bisa diamati.

## Langkah terlihat (fakta)

1. **Lihat hasil tes lama:** "View your lab and test results in MyChart as soon as they become available, along with standard ranges and provider comments." [S5].
2. **Unduh hasil tes (langkah resmi bernomor):** (1) Go to **Test Results**; (2) select the test result; (3) select **Compare result trends**; (4) in **Download results** section at bottom, select **Download**. [S4].
3. **Minta salinan rekam medis formal:** "Request a formal copy of your health record … might take a few days. You'll get the copy … as a PDF file with your organization's letterhead. You can download this file … or send it straight … from MyChart." [S2].
4. **Format terbaca mesin:** "Request your health record in a computer-readable format … a folder with multiple files." [S2].
5. **Lokasi dokumen lama (panduan faskes):** menu → **Sharing Hub** → **Yourself** → **Request Copy of Health Record**; setelah siap, notifikasi email/push bahwa file ada di **Document Center**; **Document Center → Record Request → Download** (8 langkah bernomor) [S6]; varian: **Health > Document Center > Visit Records / Requested Records > Download**, file `.zip` yang diekstrak berisi PDF [S7].
6. **Imunisasi:** "Go to **My Record** and select **Health Summary** to view your immunization records." [S1/S3, halaman fitur resmi MyChart, diakses 2026-10-01].
7. **Berbagi:** Share Everywhere = kode akses sementara; Happy Together = tautan akun antar-organisasi, tampilan terpusat (Allergies, Appointments, Medications, Test results …) [S2].

## Hitungan

Titik awal (sama semua app): **pasien sudah login, ingin menemukan rekam medis lama** → tugas inti: **rekaman ditemukan, terbaca, dan bisa diunduh**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Unduh hasil tes (resmi, bernomor) | TIDAK TERVERIFIKASI | **4 langkah** [S4] | 0 |
| Request formal copy (panduan faskes) | TIDAK TERVERIFIKASI | **8 langkah** [S6] | form permintaan (isi TIDAK TERVERIFIKASI) |
| Total end-to-end temukan → baca → unduh | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI (menu beda per faskes) | TIDAK TERVERIFIKASI |

## State terlihat

- **Menunggu (asynchronous):** permintaan salinan "might take a few days"; siap → email/teks/push bahwa file ada di Document Center [S2][S6].
- **Terlindungi:** "Some documents may have a lock icon … patient must enter a password to open the PDF." [S6].
- **Tidak tersedia:** rekaman yang tidak ada di portal diarahkan ke HIM/Health Records faskes (panduan faskes via indeks pencarian 2026-10-01) [S7-adjacent].
- **Loading / kosong / error / offline:** **TIDAK TERVERIFIKASI** (tidak didokumentasikan publik).

## Red flag

- **Password PDF yang dapat diprediksi:** tip sheet [S6] memakai pola `[MMDDYYYY]*JHHS#6!` (tanggal lahir + sufiks tetap), dapat dilihat lewat hover ikon lock / "Show Password". Spesifik faskes, bertanggal 2022 — skema 2026 **TIDAK TERVERIFIKASI**; dicatat, tidak ditiru.
- **Organisasi tak dikenal di daftar akun** [S2]: pasien bisa melihat organisasi yang tidak dikenal; penjelasan hanya via hover "Why am I seeing this?" → siapa pemegang rekaman bisa kabur.
- **Salinan formal bisa berbiaya** (panduan faskes via indeks pencarian: "for a fee"); unduhan mandiri portal tampak gratis. Tidak ditemukan paywall tersembunyi untuk unduhan mandiri di sumber resmi.
- Tidak ditemukan dark pattern lain di sumber resmi.

## Yang TIDAK bisa diverifikasi

- Jumlah layar/ketukan/field end-to-end alur lengkap (bergantung konfigurasi faskes).
- State loading, kosong, error, offline.
- Tata letak layar 2026 (portal & app di balik login).
- Keseragaman nama menu (Sharing Hub / Document Center / Record Request) lintas faskes.
- Skema password PDF Hopkins apakah masih berlaku 2026.

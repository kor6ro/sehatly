# F10 — NHS App (Documents / Test results / GP health record)

Flow: pasien sudah login → menemukan rekam medis/hasil lama → membacanya → mengunduh (bila bisa).
Format: `[URL] [akses 2026-10-01] [jenis sumber]`. Tanpa akun/login; tidak ada screenshot.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.nhs.uk/nhs-app/help/documents [akses 2026-10-01] | Bantuan resmi NHS (Viewing documents) | Langkah bernomor GP & rumah sakit |
| S2 | https://www.nhs.uk/nhs-app/help/test-results/ [akses 2026-10-01] | Bantuan resmi NHS (Viewing test results) | Langkah, riwayat hasil, larangan unduh |
| S3 | https://www.nhs.uk/nhs-app/help/ [akses 2026-10-01] | Indeks bantuan resmi NHS | Struktur topik |
| S4 | https://www.nhs.uk/nhs-app/ [akses 2026-10-01 via indeks pencarian] | Halaman resmi NHS App | Status aktif 2026 |
| S5 | https://digital.nhs.uk/services/nhs-app/nhs-app-features/gp-health-records-in-the-app [akses 2026-10-01 via indeks pencarian] | Dokumentasi resmi NHS England Digital | **Fetch langsung = 403**; rekaman prospektif vs historis, safeguarding |
| S6 | https://www.nhs.uk/nhs-app/help/health-records-in-the-nhs-app/gp-health-record [akses 2026-10-01 via indeks pencarian] | Bantuan resmi NHS | Fetch dialihkan ke indeks help; konten via indeks pencarian |

- **Batasan riset:** app native NHS tidak bisa diamati; verifikasi berbasis halaman bantuan resmi.

## Langkah terlihat (fakta)

1. **Dokumen GP** [S1]: (1) Select **Documents**; (2) select **Your documents**; (3) read "important notice about sensitive information" → **Continue**.
2. **Dokumen rumah sakit/spesialis** [S1]: (1) Select **Documents**; (2) select **Hospital and specialist documents and questionnaires**.
3. **Pratinjau vs unduh** [S1]: "Some documents cannot be previewed within the NHS App and will need to be downloaded to your device to view."
4. **Hasil tes** [S2]: (1) Select **Test results**; (2) read important notice → **Continue**; pilihan **GP-ordered** atau **Hospital-ordered test results**.
5. **Riwayat hasil:** buka satu hasil → bagian **Your result** → **View test result history** (grafik bila tes sama ≥2× dalam 3 tahun + reference range) [S2].
6. **Rekaman lama:** "If a test was carried out before October 2023, it may not appear automatically … Contact your GP surgery to request access to older test results." [S2]; rekaman **prospektif, bukan historis**: "and not historic data" [S5]; detail coded record: "contact your GP surgery and request access to your detailed coded record" [S6].
7. **Unduh hasil tes — dilarang di app:** "It is not possible to download your test results from the NHS App. Contact your GP surgery if you need a copy…" [S2].

## Hitungan

Titik awal: **pasien sudah login, ingin menemukan rekam medis/hasil lama** → tugas inti: **ditemukan, terbaca, dan (bila memungkinkan) diunduh**.

| Skenario | Layar | Ketukan/step | Field |
|---|---|---|---|
| Buka dokumen GP → baca | TIDAK TERVERIFIKASI | **3 langkah** [S1] | 0 |
| Buka dokumen rumah sakit → baca | TIDAK TERVERIFIKASI | **2 langkah** [S1] | 0 |
| Buka hasil tes → baca riwayat | TIDAK TERVERIFIKASI | **2 langkah** + drill-down riwayat [S2] | 0 |
| Temukan rekaman lama (pre-Okt 2023) → unduh | TIDAK TERVERIFIKASI | **Tidak mungkin dari app** (harus kontak GP) [S2] | 0 |
| End-to-end temukan → baca → unduh | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI | TIDAK TERVERIFIKASI |

## State terlihat

- **Notice/consent-like:** "Read through the important notice about sensitive information you may see and select continue." [S1][S2].
- **Tidak tersedia:** "Some documents may show as being unavailable in the NHS App. Contact your GP surgery to request these documents." [S1].
- **Tidak bisa pratinjau:** harus diunduh ke perangkat [S1].
- **Kuisioner berjalan:** tersimpan, bisa dilanjut; "take 3 to 5 minutes" [S1].
- **Data historis hilang:** pre-Oktober 2023 tidak muncul otomatis [S2].
- **Loading / error / offline:** **TIDAK TERVERIFIKASI**.

## Red flag

- **Risiko perangkat bersama (dinyatakan terbuka):** "When you download a document, it becomes your responsibility to keep it private. If you share your device … other people might be able to see your health information … delete any downloaded files before you log out." [S1] — ini mitigasi jujur, bukan dark pattern.
- **Safeguarding / coercive control** [S5]: "Viewing health records … may pose risks for some vulnerable adults … particularly if the patient is under coercive control."
- **Friction, bukan paywall:** hasil tes tidak dapat diunduh dari app [S2]; rekaman lama harus lewat GP [S2][S5].
- Tidak ditemukan paywall/upsell.

## Yang TIDAK bisa diverifikasi

- UI redesign NHS App 2024–2026 (nama layar versi baru).
- Jumlah tap/field end-to-end.
- State loading/error/offline.
- Halaman `gp-health-record` apakah masih berdiri sendiri (fetch dialihkan).
- Konten penuh digital.nhs.uk di luar kutipan indeks pencarian (403).

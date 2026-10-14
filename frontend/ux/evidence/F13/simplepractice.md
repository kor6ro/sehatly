# F13 — SimplePractice (kalender, telehealth, ePrescribe)

Titik awal hitungan: **dokter membuka aplikasi di sela pasien → konsultasi dimulai / resep ditulis**.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://support.simplepractice.com/hc/en-us/articles/360002997731-Preparing-for-telehealth-appointments | Help center resmi | 5 langkah start video; banner browser lama; chime join/leave "no way to turn this sound off" | 2026-10-01 |
| S2 | https://support.simplepractice.com/hc/en-us/articles/42022903873677-Telehealth-waiting-room | Help center resmi | Notifikasi klien masuk → Admit; **Admit all** (massal) | 2026-10-01 |
| S3 | https://support.simplepractice.com/hc/en-us/articles/45206963207053-Using-Session-Sidekick | Help center resmi (10 Sep 2026) | Layar transisi pasca-sesi: sisa jadwal + Start/Prepare; ePrescribe web-only | 2026-10-01 |
| S4 | https://support.simplepractice.com/hc/en-us/articles/42065448433037-Managing-client-prescriptions | Help center resmi | Alur New Rx berurut; **Favorites** obat; loading DrFirst; alert email "required and can't be disabled" | 2026-10-01 |
| S5 | https://support.simplepractice.com/hc/en-us/articles/27883962412557-Adding-ePrescribe-to-your-account | Help center resmi | ePrescribe = add-on **$49/bln per klinisi + setup $89**, mitra DrFirst | 2026-10-01 |
| S6 | https://support.simplepractice.com/hc/en-us/articles/41995508032269-Navigating-the-appointment-page | Help center resmi (19 Agu 2026) | View **Day/Week**, ikon note, riwayat dokumentasi | 2026-10-01 |
| S7 | https://apps.apple.com/us/app/simplepractice-for-clinicians/id738207604 | Store listing | v10.4.2; aksesibilitas: VoiceOver, Larger Text, Dark Interface; sinkron instan mobile-web | 2026-10-01 |
| S8 | https://www.medesk.net/en/blog/simple-practice-review | Tinjauan pihak ketiga | "SimplePractice Review 2026", diperbarui 5 Jun 2026 — kontra: tanpa split-screen note saat sesi | 2026-10-01 |
| S9 | https://www.simplepractice.com/ dan https://www.simplepracticestatus.com/ | Situs resmi + status page | © 2026; status all-operational 1 Okt 2026 | 2026-10-01 |
| S10 | https://www.businesswire.com/news/home/20240801843539/en/SimplePractice-Expands-Into-the-Psychiatry-Space-with-the-Launch-of-ePrescribe | Siaran pers independen (wire) | Peluncuran ePrescribe (akuisisi aset Luminello), 1 Agu 2024 | 2026-10-01 |

**Verifikasi 2026:** aktif — help center diperbarui Sep 2026 (S3), store v10.4.2 (S7), © 2026 (S9).

## Langkah terlihat (fakta)

1. **Kalender**: tampilan **Day atau Week**; klik janji → appointment flyout → **Start video appointment** membuka tab baru [S1][S6].
2. **5 langkah start telehealth terdokumentasi**: buat janji (Location = Telehealth: Video Office) → pilih janji di kalender → **Start video appointment** → isi nama → **Start video appointment** [S1].
3. **Antrean**: klien masuk → notifikasi klinisi → **Admit**; beberapa klien → **View all → Admit all** [S2].
4. **Dokumen/catatan**: progress & psychotherapy notes di tab **Note** pada appointment; tombol **Rx** mengisi otomatis obat aktif ke note [S3][S4].
5. **Tulis resep (berurut, terdokumentasi)**: Overview → Medications → Manage medications → Continue (buka DrFirst) → **Create New Rx** → cari obat → pilih → Strength/Pkg → Patient Directions → Quantity → Review → Save Pending Rx → **Signature Password** → Send [S4]. Ada **Favorites** untuk obat sering diresepkan [S4].
6. **Layar pasca-sesi**: rangkuman sisa janji hari itu; bila janji berikutnya <10 menit → tombol **Start video appointment** langsung; selainnya → **Prepare** [S3].

## Hitungan

Dari **dokter membuka aplikasi** → **konsultasi dimulai**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 2 (kalender → tab telehealth) | S1 |
| Ketukan | 5 (pilih janji, Start, nama, Start, Admit) | S1 |
| Field | 1 (nama) | S1 |

Dari **konsultasi → resep ditulis**: alur New Rx **±11 langkah terdokumentasi** + Signature Password; ePrescribe hanya di **web** [S3][S4]; butuh add-on berbayar [S5].

## State terlihat

- **Loading**: "DrFirst ePrescribe window… may take a moment to load" [S4].
- **Error**: banner peringatan browser lama yang bisa ditutup; klaim scrub error [S1].
- **Konfirmasi anti-salah**: "Get a confirmation message to prevent ending a session unintentionally" (telehealth) [S8].
- **Kosong/offline in-product**: **TIDAK TERVERIFIKASI**; status layanan via status page [S9].

## Red flag

- **NOTIFIKASI OVERLOAD (red flag rubrik/tugas)**: bunyi join/leave — "There's no way to turn this sound off" [S1]; alert email ePrescribe — "required and can't be disabled" [S4]. Karena red flag, SimplePractice **tetap dinilai di skor tetapi TIDAK BOLEH jadi sumber pola untuk langkah mana pun** dalam `patterns/F13.md`; hanya dipakai sebagai fakta pembanding (hitungan, alur ePrescribe).
- **Upsell**: ePrescribe add-on $49/bln + $89 setup [S5]; banner kredensial gratis [S9] — transparan, tidak menutupi aksi utama (start video tetap tersedia tanpa ePrescribe).
- Clinical Alerts wajib diakui sebelum kirim resep [S4] = pencegahan error yang baik (dipakai sebagai inspirasi gate).

## Yang TIDAK bisa diverifikasi

- Layout kalender/flyout sungguhan; jumlah ketukan total presisi.
- Semua state kosong/loading/offline aplikasi (di balik login).
- Kebenaran klaim "VoiceOver/Larger Text" pada aplikasi web (hanya listing iOS yang menyatakan) — web WCAG: pencarian help "WCAG" = 0 hasil → **TIDAK TERVERIFIKASI**.
- Shortcut keyboard (tidak ada artikel publiknya).

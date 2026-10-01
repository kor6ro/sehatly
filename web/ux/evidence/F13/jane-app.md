# F13 — Jane App (jadwal hari, telehealth, charting)

Titik awal hitungan: **dokter membuka aplikasi di sela pasien → konsultasi dimulai / resep ditulis**.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://jane.app/guide/how-to-book-1-1-online-appointments-and-start-them-for-practitioners | Panduan resmi (guide) | 6 langkah dokumentasi "Begin → Join Meeting" + "Let in" | 2026-10-01 |
| S2 | https://jane.app/guide/sign-and-lock-workflow | Panduan resmi | Day view = "daysheet", akses chart 1 klik | 2026-10-01 |
| S3 | https://jane.app/guide/sign-lock-charts-21773735-e622-4323-835e-29783ffc9945 | Panduan resmi | "sign & lock each chart individually… isn't a way to sign multiple draft charts at once" | 2026-10-01 |
| S4 | https://jane.app/guide/keyboard-shortcuts | Panduan resmi | Daftar shortcut jadwal (D, S, T, N, P, 1–7, W, L, Shift+P blur) | 2026-10-01 |
| S5 | https://jane.app/guide/accessibility-in-jane | Panduan resmi | Accessibility Mode, Schedule List View, error ikon+teks | 2026-10-01 |
| S6 | https://jane.app/guide/prescriptions | Panduan resmi | Resep = template chart + Integrated Fax; e-prescription = feature request | 2026-10-01 |
| S7 | https://jane.app/guide/medical-wellness-hub | Panduan resmi | "What's coming next: 🇺🇸 E-Prescriptions" (roadmap) | 2026-10-01 |
| S8 | https://accounts.janeapp.com/status/incidents | Halaman status resmi | "All Systems Operational" 1 Okt 2026 | 2026-10-01 |
| S9 | https://www.medesk.net/en/blog/jane-app-review | Tinjauan pihak ketiga | "Jane App Review (2026)", 1 Jun 2026 | 2026-10-01 |
| S10 | https://play.google.com/store/apps/details?hl=en_GB&id=app.jane.mobile | Store listing | "Jane for Clients", ulasan & balasan dev Jul–Agu 2026 (sisi klien; praktisi di web) | 2026-10-01 |
| S11 | https://jane.app/us/features | Situs resmi | Capterra 4,8 (450+ ulasan); © 2026 Jane Software Inc. | 2026-10-01 |

**Verifikasi 2026:** aktif — panduan resmi, status page 1 Okt 2026 (S8), © 2026 (S11), ulasan store aktif 2026 (S10). Sisi praktisi berjalan di **web**; app store = aplikasi klien.

## Langkah terlihat (fakta)

1. **Mulai telehealth (6 langkah terdokumentasi)**: klik janji di Schedule → Appointment Panel → **Begin** (muncul 1 jam sebelum, hilang 1 jam sesudah) → **Request Permissions** → **Run Test** atau **Skip** → atur kamera/mikrofon → **Join Meeting** [S1].
2. **Admit pasien (1 ketukan)**: klien bergabung → bunyi → tombol **Let in** menyala biru → klik; timer mulai [S1].
3. **Hari kerja**: "Day" view = daysheet satu hari, **single click access** ke chart pasien; ikon gunci menandai status chart [S2].
4. **Charting**: buat entri dari Day view → tombol **Sign** → konfirmasi; "sign & lock each chart individually… isn't a way to sign multiple draft charts at once" [S3].
5. **Shortcut jadwal terdokumentasi**: `D` double-booking, `S` cari pasien, `T` hari ini, `N`/`P` next/prev, `1–7` hari berikutnya, `W` wait list, `Shift+P` mode privasi (blur nama), `Shift+↑/↓` zoom [S4].
6. **Resep**: TIDAK ada e-prescribing live — hanya template chart "Prescriptions" + **Integrated Outbound Fax** HIPAA [S6]; e-prescription masih roadmap [S7].
7. **Tampilan daftar**: Accessibility Mode (warna jadwal ramah buta warna) + **Schedule List View** (tombol tabel) sebagai alternatif kalender [S5].

## Hitungan

Dari **dokter membuka aplikasi** → **konsultasi dimulai**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 2 (Schedule → Appointment Panel/telehealth window) | S1 |
| Ketukan | 7 (Begin, Run Test/Skip, Join Meeting, Let in + buka janji) | S1 |
| Field | 0 (izin browser & tes perangkat = dialog OS/browser, bukan field aplikasi) | S1 |

Dari **Day view → chart terbuka**: 1 ketukan [S2]. **Tulis resep: TIDAK mungkin** (tidak ada e-prescribing; jalurnya template + fax) [S6].

## State terlihat

- **Error input**: "borders colour turn red… error icon and message" (ikon + pesan, bukan warna saja) [S5].
- **Status chart**: draft = gunci terbuka, signed = gunci tertutup, "Pending Signature" untuk supervisee [S2][S3].
- **Menunggu klien**: lencana hijau pada janji + tombol Let in biru [S1].
- **Loading / kosong / offline**: **TIDAK TERVERIFIKASI** di panduan publik.

## Red flag

- **Add-on berbayar**: Group Telehealth $15/praktisi/bulan; Payroll waitlist [jane.app/guide/getting-started-with-online-appointments…, akses 2026-10-01] — transparan di panduan, tidak menutupi aksi utama join meeting (yang gratis) → tidak didiskualifikasi.
- Tidak ditemukan hitung mundur palsu, tombol tersembunyi, izin tanpa konteks pada sumber resmi.
- `Shift+P` blur nama pasien = pola privasi POSITIF (dipakai sebagai aturan, bukan ditiru buta).

## Yang TIDAK bisa diverifikasi

- Layout Schedule/Day view sesungguhnya, semua empty/loading/offline state, jumlah ketukan total "login → resep" (resep tidak ada).
- Daftar shortcut in-product lengkap (sebagian di balik menu username).
- Konfirmasi WCAG/VPAT: panduan aksesibilitas ada (S5) tetapi **tidak ada klaim kepatuhan** → level = TIDAK TERVERIFIKASI.
- Status peta jalan e-prescription (Canny di balik login).

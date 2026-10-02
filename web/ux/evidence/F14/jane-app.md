# F14 — Jane App (akses staf, Manage Shifts, laporan & export)

Titik awal hitungan: **pemilik/admin klinik membuka Schedule/Reports → tugas inti selesai** (buat/perbarui jadwal shift staf / atur akses / export laporan).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://jane.app/guide/staff-access-levels | Panduan resmi | Level akses staf: **Active, Access Billing, Access Charts, Manage Shifts** + peran (Practitioner, Front Desk, Administrative/All Billing, Full Access); hanya **satu Account Owner**; owner dapat authorize bulk export/transfer data; praktisi hanya melihat jadwal/pasiennya sendiri (atau yang disupervisi) | 2026-10-02 |
| S2 | https://jane.app/guide/setting-up-shifts | Panduan resmi | **Manage Shifts**: pilih rentang tanggal (start/end date picker), atur jam per hari, **copy ke hari lain** (tombol Copy Times), pilih **lokasi & beberapa staf sekaligus** (checkbox), **opsi booking online** (Not Bookable Online / Contact us / Bookable Online), Notes/Tags/Rooms, hapus shift dengan X, **peringatan: shift lama dalam rentang tanggal akan di-override** | 2026-10-02 |
| S3 | https://jane.app/guide/exporting-reports-and-customizing-them-in-excel | Panduan resmi | Export laporan: Reports → pilih report di sidebar → tiga titik → **Export to Excel** → halaman baru → **Download to XLSX**; beberapa laporan **tidak bisa** di-export (Ratings & Reviews, Phone Reminders Due, Return Visit Reminders Due); template tidak bisa di-export | 2026-10-02 |
| S4 | https://jane.app/guide/category/reporting | Panduan resmi | Kategori Reports: Hours Scheduled/Booked, Dashboard Permissions untuk staf, dsb. | 2026-10-02 |
| S5 | https://jane.app/guide/step-4-staff | Panduan resmi | Buat profil staf; **disiplin harus di-assign sebelum jadwal bisa dibuat**; kirim **Welcome Email** agar staf bisa set password; profil berisi "Permissions and Commissions" | 2026-10-02 |
| S6 | https://jane.app/guide/step-5-schedule | Panduan resmi | Manage Shifts = alat utama penjadwalan; shift menentukan ketersediaan admin + online booking; "Manage Shifts for each practitioner at a time" bila shift berbeda | 2026-10-02 |
| S7 | https://jane.app/guide/setting-up-shifts-faq | Panduan resmi | Print shift multi-staf: tidak ada cara langsung; alternatif lewat report Hours Scheduled/Booked, dan **tidak bisa memilih beberapa staf sekaligus** di versi export tersebut | 2026-10-02 |
| S8 | `web/ux/evidence/F13/jane-app.md` S5 | Panduan resmi | Accessibility Mode (ramah buta warna) + **Schedule List View**; error = ikon + teks; **tanpa klaim WCAG** | 2026-10-01 |
| S9 | `web/ux/evidence/F13/jane-app.md` S9, S10, S11 | Penerbit independen | Medesk review "Jane App Review (2026)" 1 Jun 2026; Play Store "Jane for Clients" ulasan 2026; Capterra 4,8 (450+ ulasan) | 2026-10-01 |

**Verifikasi 2026:** aktif — panduan resmi dapat diakses 2026-10-02; sumber independen 2026 (S9). Praktisi memakai web; app store = aplikasi klien.

## Langkah terlihat (fakta)

1. **Atur level akses staf** [S1]: profil staf → **Permissions and Commissions** → pilih level; kapabilitas bervariasi per level (mis. Front Desk hanya melihat billing-nya sendiri; Administrative/All Billing melihat semua laporan; hanya **Full Access** bisa membuat staf baru & mengubah setting klinik; hanya **Account Owner** bisa authorize bulk export).
2. **Manage Shifts (jadwal mingguan)** [S2]: Schedule → pilih staf → dropdown **Shifts → Manage Shifts** → pilih **rentang tanggal** → untuk tiap hari pilih **start/end time** → **Copy Times** ke hari lain → pilih **lokasi & staf** (checkbox, beberapa sekaligus) → atur **online booking** per shift → simpan.
3. **Override & hapus** [S2]: "any shifts previously created … over the same date range will be overridden by your new shift schedule, so just be sure … before proceeding"; menghapus shift = klik **X**; menyimpan jadwal kosong = menghapus semua shift pada rentang.
4. **Tanpa izin Manage Shifts** [S1]: tombol **Shifts hilang** dari Schedule dan staf tidak bisa menambah/mengubah shift, termasuk miliknya sendiri.
5. **Export laporan** [S3]: Reports → pilih report → tiga titik → **Export to Excel** → **Download to XLSX**; beberapa laporan tidak bisa export (daftar eksplisit); tidak ada export untuk template.
6. **Print/multi-staf** [S7]: Hours Scheduled/Booked report bisa membuka versi printable per staf, tetapi **tidak bisa memilih beberapa staf sekaligus**.
7. **Prasyarat staf** [S5]: staf harus punya **disiplin** sebelum jadwal dibuat; Welcome Email untuk aktivasi login.

## Hitungan

Dari **admin membuka Schedule** → **jadwal shift mingguan 1 staf selesai**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 1 modul (Manage Shifts) + date picker | S2 |
| Ketukan | ±6 (Shifts, Manage Shifts, isi jam, Copy Times, pilih hari, pilih staf, simpan) | S2 |
| Field | 2 date + jam per hari + opsi booking | S2 |

**Atur 1 level akses staf**: 2 layar (Staff → profil → Permissions), 1 keputusan utama [S1].
**Export 1 laporan**: 3 ketukan + halaman unduh kedua [S3]; **batas export dinyatakan eksplisit**.

## State terlihat

- **Peringatan destruktif eksplisit**: jadwal baru meng-override jadwal lama pada rentang tanggal [S2].
- **Penyembunyian aksi tanpa izin**: tombol Shifts hilang bila Manage Shifts nonaktif [S1].
- **Batasan yang diakui**: daftar laporan yang tidak bisa export & ketidakmampuan pilih multi-staf [S3,S7].
- **Error input**: ikon + pesan (bukan warna saja) [S8].
- **Loading/kosong/offline**: **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi.
- Catatan: **"Manage Shifts overrides existing shifts"** dilakukan dengan peringatan, bukan konfirmasi berlapis — batas aman karena rentang tanggal terlihat; tetap tidak diadopsi mentah (lihat pola F14 §3).
- Accessibility Mode & Schedule List View = pola positif (dipakai sebagai aturan) [S8].

## Yang TIDAK bisa diverifikasi

- Layout Manage Shifts & halaman Permissions, semua state loading/kosong/error/offline.
- Konfirmasi WCAG (panduan aksesibilitas ada, tanpa klaim kepatuhan) [S8].
- Audit trail perubahan jadwal/akses: tidak ada dokumentasi.
- Zona waktu: panduan tidak menyatakan penanganan lintas zona.
- Apakah shift bisa one-off (bukan mingguan) di luar rentang Manage Shifts — tidak dijelaskan di sumber yang diakses (dokumentasi menyebut shift per rentang tanggal).

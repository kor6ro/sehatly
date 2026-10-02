# F14 — Practo Ray (akses staf, tambah konsultan, laporan)

Titik awal hitungan: **pemilik/admin klinik membuka Settings → tugas inti selesai** (atur akses staf / tambah konsultan / lihat laporan).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://help.practo.com/practo-ray/settings/an-overview-of-access-control/ | Help center resmi | Access control: peran **Owner/Administrator, Doctor (Consultant), Practice Administrator, Receptionist, Front Desk**; tabel fitur per peran; contoh: resepsionis tidak boleh menyentuh pembayaran | 2026-10-02 |
| S2 | https://help.practo.com/practo-ray/settings/specifying-different-levels-of-access-for-your-staff/ | Help center resmi | Alur: ikon **Settings** (kanan atas) → **Settings** → **Practice Staff** → **Manage Staff** → **Edit** di nama staf → aktifkan **Admin login** → pilih satu dari 3 level: **Practice Administrator** (semua), **Receptionist** (accounting 1 bulan, tanpa analytics), **Front Desk** (semua kecuali accounting, analytics, settings, edit appointments/payments) | 2026-10-02 |
| S3 | https://help.practo.com/practo-ray/staff-management/how-do-i-add-a-consultant-to-my-practice/ | Help center resmi | Tambah konsultan: hanya **Owner/Administrator**; konsultan hanya melihat **kalender janji miliknya sendiri** + **EMR pasiennya sendiri**; **tidak** boleh Reports, Billing, EMR pasien lain, biaya prosedur | 2026-10-02 |
| S4 | https://help.practo.com/practo-ray/settings/access-settings-for-doctors/ | Help center resmi | Akses dokter diatur dari **Practice Staff → Manage Staff → Edit → Doctor admin access** | 2026-10-02 |
| S5 | https://help.practo.com/practo-ray/reports/reports-overview/ | Help center resmi | Reports: kategori laporan (default **Income**), **Summary grand total** di atas, Related Reports, tombol **Mail** dan **Print**; Ray v6 menggabungkan Accounting+Analytics jadi satu seksi Reports | 2026-10-02 |
| S6 | https://help.practo.com/practo-ray/settings/how-to-view-your-staff-details/ | Help center resmi | Manage Staff = daftar semua staf klinik | 2026-10-02 |
| S7 | https://help.practo.com/category/practo-ray/staff-management/ | Help center resmi | Kategori staff management; sebagian fitur "available only to Singapore/Philippines customers" | 2026-10-02 |
| S8 | `web/ux/evidence/F13/practo-ray.md` S8/S9 (URL di file itu) | Audit pihak ketiga + dokumen pengadilan | Audit aksesibilitas 2025: isu kritis di 7/9 layar iOS; perintah pengadilan CCPD 2022 | 2026-10-01 |
| S9 | `web/ux/evidence/F13/practo-ray.md` S11 | Berita independen | Times of India 17 Sep 2026 — Practo aktif; 700.000+ penyedia | 2026-10-01 |

**Verifikasi 2026:** aktif — berita Sep 2026 (S9), help center dapat diakses 2026-10-02. Tidak ada nomor versi publik; sebagian artikel menyebut "Ray v6".

## Langkah terlihat (fakta)

1. **Lihat semua staf** [S6,S2]: Settings → Practice Staff → **Manage Staff** (daftar staf + detail).
2. **Beri akses staf** [S2]: Edit di nama staf → aktifkan **Admin login** → pilih **satu level** dari 3 (Practice Administrator / Receptionist / Front Desk); masing-masing level mendefinisikan fitur yang boleh diakses.
3. **Batasan per peran (contoh terdokumentasi)** [S1,S2]:
   - Practice Administrator = akses penuh;
   - Receptionist = accounting 1 bulan, **tanpa analytics**;
   - Front Desk = semua **kecuali** accounting, analytics, settings, dan edit appointments/payments.
4. **Tambah dokter/konsultan** [S3]: Settings → Practice Staff → **Add Doctor** → isi detail → pilih **Consultant** → **Add Doctor** → aktifkan login → konsultan hanya melihat **kalender & EMR miliknya**; tidak boleh reports/billing/EMR pasien lain (dinyatakan **sebelum** menambah) [S3].
5. **Laporan** [S5]: menu **Reports** → pilih **Report Category** (dropdown; default Income) → **Summary grand total** di bagian atas → rincian/Related Reports → **Print** atau **Mail** ke konsultan.
6. **Batas ketersediaan fitur per negara** [S7]: sebagian alur konsultan "available only to Singapore/Philippines customers" — dokumentasi tidak menjamin paritas global.

## Hitungan

Dari **admin membuka Settings** → **ubah level akses 1 staf**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 3 (Settings → Practice Staff/Manage Staff → halaman Edit staf) | S2 |
| Ketukan | ±5 (Settings, Practice Staff, Edit, aktifkan Admin login, pilih level, simpan) | S2 |
| Field | 1 keputusan utama (level akses) + toggle login | S2 |

**Tambah konsultan**: 1 form + pilihan tipe akun **Consultant**; batas akses terlihat sebagai daftar sebelum simpan [S3].
**Laporan**: 2–3 ketukan (Reports → kategori → Print/Mail); angka `Summary` di atas rincian [S5].

## State terlihat

- **Efek samping izin dinyatakan di muka**: "They will not have access to: Reports, Billing, EMR of other patients, Cost of the procedures" [S3].
- **Bagian fitur tidak muncul** saat tidak diizinkan (mis. opsi tertentu hilang) — pola pembatasan akses per peran [S1,S2].
- **Loading/kosong/error/offline**: **TIDAK TERVERIFIKASI**.
- **Aksesibilitas**: bukti negatif — audit 2025 menemukan isu kritis di 7/9 layar iOS, dan pengadilan 2022 memerintahkan perbaikan [S8].

## Red flag

- **Aksesibilitas** buruk-terdokumentasi [S8] → bukan dark pattern, tetapi tidak layak jadi sumber pola aksesibilitas (skor K4 rendah).
- Nav dokter memuat cross-sell produk lain (Profiles/Feedback/Reach/Consult) [F13 practo-ray S6] → kebisingan.
- Tidak ditemukan hitung mundur palsu/upsell menutupi aksi utama pada halaman admin yang diakses.

## Yang TIDAK bisa diverifikasi

- Layout halaman Manage Staff/Edit, semua state kosong/error, dan jumlah klik presisi (langkah berbasis teks).
- Tampilan tabel authorization per peran (artikel menyebut "table below" tetapi isi tabel tidak terekspos di fetch).
- Audit trail perubahan akses staf: tidak ada dokumentasi.
- Aksi massal (bulk) untuk staf/jadwal: tidak ada dokumentasi.
- Pengelolaan jadwal dokter (tulis) dari sisi admin: tidak ditemukan artikel publik pada sesi ini.

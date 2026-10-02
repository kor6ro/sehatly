# F14 — Trustmedis (SIMKlinik/SIMRS): jadwal dokter, akun operator + autorisasi, laporan

Titik awal hitungan: **admin/operator membuka modul → tugas inti selesai** (tambah/ubah/hapus jadwal dokter / kelola akun operator / lihat laporan).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://kb.trustmedis.com/articles/menu-jadwal-dokter-21-4-2022 | Knowledge base resmi (fetch langsung; halaman **Updated 13/5/2026**) | Menu Jadwal Dokter: tambah/ubah/hapus; **hapus ditolak dengan notifikasi jika sudah ada pendaftaran**; tombol **Update Jadwal HFIS** (BPJS) setelah Simpan; proses **Generate Jadwal Dokter** sebelum tampil di Admisi/Booking Online | 2026-10-02 |
| S2 | https://kb.trustmedis.com/articles/menu-akun-operator-21-4-2022 | Knowledge base resmi | Akun Operator: username, password (min 8, kombinasi), karyawan, **Group** user, **Unit Kerja** (membatasi akses unit modul), jam masuk/pulang, status aktif; **jenis autorisasi** per user (Hapus, Layanan, Print Lab, Order Mutasi, Tutup Penerimaan, Print Rekening, Verifikasi Kas, Reset Jurnal, Laporan Filter); autorisasi bisa menuntut **persetujuan user level atas** (input username+password atasan) | 2026-10-02 |
| S3 | https://kb.trustmedis.com/llms.txt | Indeks KB resmi | Daftar artikel termasuk "laporan jasa dokter", "generate jadwal dokter manual/otomatis", "kuota pasien online vs onsite", "edit jadwal" | 2026-10-02 |
| S4 | https://trustmedis.com/dashboard-modul/ | Halaman produk resmi | Dashboard: statistik pendaftaran, pelayanan, pendapatan per unit, periode harian/bulanan/tahunan; laporan dapat dicetak | 2026-10-02 |
| S5 | https://trustmedis.com/harga/ | Halaman harga resmi (diperbarui 2026-01-08) | Paket menyebut "Manajemen Jadwal Dokter", "tanpa batas pengguna dan laporan" | 2026-10-02 |
| S6 | https://kb.trustmedis.com/articles/menu-booking-online-21-4-2022 | Knowledge base resmi | Booking online hanya menampilkan jadwal yang sudah **di-generate**; status "Jadwal Dokter Tersedia / Tidak Tersedia"; riwayat booking + batal booking | 2026-10-02 |
| S7 | https://trustmedis.com/blog/kelola-jadwal-dokter-di-rumah-sakit-lebih-cepat-dengan-trustmedis/ | Blog resmi | Modul Admisi mengelola jadwal + kuota + estimasi waktu layanan; pencarian pasien by RM/NIK/BPJS | 2026-10-02 |
| S8 | https://kb.trustmedis.com/articles/menu-generate-jadwal-dokter-21-4-2022 (via S3) | Knowledge base resmi | Generasi jadwal manual/otomatis | 2026-10-02 |

**Verifikasi 2026:** aktif — halaman KB "Updated 13/5/2026", footer "© 2026 Trustmedis", harga diperbarui Jan 2026, llms.txt terindeks. Versi: "Trustmedis Suite, Trustmedis Clinic" (tanpa nomor versi).

## Langkah terlihat (fakta)

1. **Tambah jadwal** [S1]: modul **Admisi → Back Office → Jadwal Dokter → Tambah** → isi **Poli/Unit/Ruang** (dropdown; kode BPJS muncul bila termapping) → **Nama Dokter** (dropdown; kode BPJS) → **Jenis Pasien** → **Add** → pilih **Hari, Jam Awal, Jam Akhir, Kuota Pasien, Estimasi pelayanan** → **Update** → ulangi untuk hari berikutnya → **Simpan** → (opsional) **Update Jadwal HFIS** untuk sinkron BPJS.
2. **Ubah jadwal** [S1]: pilih baris → **Edit** → **Tambah** baris baru / **Hapus** baris lama → **Update** → **Simpan**; ada tombol **Batal** untuk membatalkan perubahan.
3. **Hapus jadwal** [S1]: pilih jadwal → **Hapus**; **"Jika jadwal dokter yang dihapus sudah ada pendaftaran di menu Admisi atau Booking Online, sistem akan menampilkan notifikasi jika data tersebut tidak dapat dihapus."**
4. **Publish jadwal** [S1,S6]: setelah tambah/ubah/hapus, jalankan **Generate Jadwal Dokter** agar jadwal tampil di Admisi/Booking Online; sebelum generate, jadwal **tidak** tampil ke pasien.
5. **Akun operator** [S2]: Setting → Profil → **Akun Operator → tambah** → username, password, karyawan, **Group**, **Unit Kerja** (akses per unit modul, mis. APOTEK), jam kerja, status → atur **9 jenis autorisasi** (mis. Hapus = membatalkan pendaftaran RJ/RI/IGD; Layanan = mengedit tindakan setelah pasien pulang) → Simpan.
6. **Autorisasi bertingkat** [S2]: jenis autorisasi tertentu menuntut **persetujuan level di atas** dengan menginput username+password approver; tanpa autorisasi, opsi tidak muncul.
7. **Laporan** [S4,S3]: Dashboard menampilkan statistik pendaftaran/pelayanan/pendapatan per unit dalam periode harian/bulanan/tahunan, dapat dicetak; KB memuat cara mengetahui "Laporan Jasa Dokter" dan "laporan kerja dokter 1 bulan".

## Hitungan

Dari **operator membuka modul** → **tambah 1 jadwal dokter**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 2 (daftar Jadwal Dokter → form tambah dengan dialog Add) | S1 |
| Ketukan | ±8 (Tambah, isi 3 dropdown, Add, Update, Simpan) | S1 |
| Field | 7 (Poli, Dokter, Jenis Pasien, Hari, Jam Awal, Jam Akhir, Kuota, Estimasi) | S1 |

**Satu hari per pengulangan** ("ulangi untuk menambahkan jadwal di hari berikutnya") [S1]; **+1 langkah Generate** sebelum jadwal terlihat pasien [S1,S6].
**Kelola 1 akun operator**: ±20+ field/pilihan termasuk 9 toggle autorisasi [S2].

## State terlihat

- **Error/ditolak**: notifikasi "tidak dapat dihapus" saat jadwal punya pendaftaran [S1] — pencegahan konflik terverifikasi.
- **Draft/publish**: jadwal tersimpan tetapi belum tampil sampai Generate [S1,S6].
- **Status akun**: aktif/tidak aktif; tidak aktif tidak bisa login [S2].
- **Autorisasi tersembunyi**: opsi status order tidak muncul tanpa autorisasi [S2].
- **Loading/kosong/offline**: **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ditemukan dark pattern pada sumber resmi.
- Catatan: alur delete jadwal **tidak** memakai dialog konfirmasi di dokumentasi (hanya tombol Hapus lalu notifikasi bila gagal) — pola destruktif tanpa konfirmasi eksplisit; **tidak ditiru** (lihat `standards-admin-ux.md`).
- Hirarki menu dalam (Admisi → Back Office → Jadwal Dokter) dan beban field autorisasi tinggi; bukan red flag, tapi beban kognitif.

## Yang TIDAK bisa diverifikasi

- Layout, state loading/kosong/error, dan jumlah klik presisi (dokumen berbasis langkah, ada screenshot tetapi tidak dianalisis visual).
- Audit trail perubahan jadwal/akun: tidak ada dokumentasi.
- Aksi massal: tidak ada di dokumentasi.
- Zona waktu: tidak ada dokumentasi zona (jam awal/akhir diasumsikan waktu lokal faskes).
- Batas export laporan & format file.

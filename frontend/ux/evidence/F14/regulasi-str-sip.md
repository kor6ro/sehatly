# F14 — Regulasi kredensial dokter Indonesia (STR/SIP) — dasar kelayakan menampilkan masa berlaku

Sumber hukum/regulator untuk memutuskan **apa yang boleh dan harus ditampilkan** tentang kredensial dokter di layar admin. Bukan aplikasi; tidak dinilai rubrik.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://kemkes.go.id/id/tata-cara-penyelenggaraan-perizinan-tenaga-medis-dan-tenaga-kesehatan-dalam-uu-no-17-tahun-2023 | Situs resmi Kemenkes | UU 17/2023: SIP wajib; SIP terbit mengikuti masa berlaku STR (skema lama); SIP bisa 5 tahun; proses perizinan via Dinkes/PTSP | 2026-10-02 |
| S2 | https://kemkes.go.id/id/surat-izin-praktik-tenaga-medis-dan-tenaga-kesehatan-bisa-digunakan-sampai-masa-berlaku-habis | Situs resmi Kemenkes (2024-02-02) | Pasal 449 UU 17/2023: **STR menjadi seumur hidup**, tetapi STR/SIP yang sudah terbit **tetap berlaku sampai masa berlakunya habis**; "meskipun STR sudah berlaku seumur hidup, tidak perlu langsung memperbaharui SIP, kecuali masa berlakunya sudah berakhir" | 2026-10-02 |
| S3 | https://kemkes.go.id/id/%20str-dokter-seumur-hidup-syarat-pemenuhan-kompetensi-tetap-berlaku | Situs resmi Kemenkes | STR seumur hidup + pemenuhan kompetensi/SKP melekat pada perpanjangan **SIP setiap 5 tahun** | 2026-10-02 |
| S4 | https://skp.kemkes.go.id/SE%20No.%20HK.02.01-MENKES-1063-2024%20ttg%20Pemenuhan%20Satuan%20Kredit%20Profesi%20Penerbitan%20Perpanjangan%20Surat%20Izin%20Praktik%20Tenaga%20Medis%20Tenaga%20Kesehatan-signed.pdf | Surat Edaran resmi Kemenkes (2024) | Tenaga medis yang belum memenuhi SKP s.d. 31 Des 2024: **penonaktifan sementara STR** dan SIP dinyatakan tidak berlaku/dicabut oleh Dinkes | 2026-10-02 |
| S5 | https://dinkeskotabalam.com/pelayanan/REKOM_PERIZINAN.pdf (mirror SE HK.02.01/MENKES/6/2024) | Salinan SE Kemenkes | Syarat SIP: STR, tempat praktik, bukti kecukupan SKP; masa berlaku SIP 5 tahun; verifikasi lewat portal `sisdmk.kemkes.go.id` | 2026-10-02 |
| S6 | `database/migrations/2026_10_01_000031_dokter_table.php` + `app/Models/Dokter.php` (kode Sehatly) | Kode internal (read-only) | Kolom nyata: `nomor_str` UNIQUE NOT NULL, **`str_berlaku_sampai` DATE NOT NULL**, `nomor_sip` NULL, **`sip_berlaku_sampai` DATE NULL**, `file_str_url`, `file_sip_url`, `status_verifikasi` ENUM(`pending`,`terverifikasi`,`ditolak`) default `pending`, `status_aktif` default 1 | 2026-10-02 |

## Fakta yang mengikat desain F14

1. **STR sekarang seumur hidup untuk tenaga medis baru, tetapi STR lama masih punya tanggal berakhir** [S2,S3] — dan kolom Sehatly `str_berlaku_sampai` **NOT NULL** [S6], sehingga **tidak ada representasi "seumur hidup / tanpa kedaluwarsa"**. UI tidak boleh mengarang status "berlaku selamanya"; ini keputusan skema (lihat pattern §12).
2. **SIP adalah kredensial 5 tahun yang harus dipantau** (perpanjangan butuh SKP) [S3,S5]; `sip_berlaku_sampai` nullable [S6] → UI harus membedakan "belum diisi" dari "tidak wajib".
3. **STR/SIP bisa dinonaktifkan sementara/dicabut** oleh regulator [S4] → status kelayakan seorang dokter dapat berubah di luar aplikasi; admin butuh **tanggal berlaku + pengingat**, bukan hanya centang "terverifikasi".
4. **Verifikasi kredensial adalah keputusan per-dokter** (review dokumen STR/SIP), bukan aksi massal; `status_verifikasi` hanya 3 nilai tanpa kolom alasan penolakan [S6] → penolakan beralasan butuh kolom/kanal baru (pertanyaan terbuka pattern §12).
5. **Data kredensial = data pribadi pihak ketiga** (`nomor_str` dimasking di `AuditColumnPolicy`; file STR/SIP dilarang masuk audit log) → layar admin harus meminimalkan tampilan nomor dan mengontrol akses berkas (pattern §9).

## Yang TIDAK bisa diverifikasi / di luar lingkup

- Masa depan regulasi STR seumur hidup vs. SIP (aturan peralihan masih berjalan) — hanya kondisi 2024–2026 yang dibaca.
- Kewajiban hukum spesifik UU PDP untuk retensi audit log admin (angka tahun) — tidak diambil dari sumber ini; masuk pertanyaan terbuka.
- Kewajiban tampilan di aplikasi pihak ketiga (tidak diatur sumber yang diakses).

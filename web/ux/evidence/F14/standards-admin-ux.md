# F14 — Standar & riset: aksi massal, konfirmasi destruktif, tabel, audit log

Sumber pedoman untuk pola F14. Diambil sebagai **standar**, bukan aplikasi; tidak menyalin aset/teks.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://www.nngroup.com/videos/bulk-actions-design-guidelines/ | Riset independen NN/g (video, 2025-03-17) | 3 pedoman aksi massal: (1) sediakan **Select All**, (2) pakai **contextual action bar**, (3) beri **feedback jelas + opsi undo** | 2026-10-02 |
| S2 | https://www.nngroup.com/articles/confirmation-dialog/ | Riset independen NN/g (2018-02-18) | Konfirmasi hanya untuk konsekuensi serius/ireversibel; **spesifik** (sebut objek), bukan "Anda yakin?"; untuk operasi sangat berbahaya minta **tindakan nonstandar** (mis. ketik kata); jangan berlebihan; utamakan **undo**; contoh MailChimp "type DELETE" | 2026-10-02 |
| S3 | https://www.nngroup.com/articles/proximity-consequential-options/ | Riset independen NN/g (2021-02-14) | Aksi berbahaya vs jinak harus **dipisah posisi + sinyal visual berlebih**; pencegahan error > pemulihan | 2026-10-02 |
| S4 | https://www.nngroup.com/articles/user-control-and-freedom/ | Riset independen NN/g (2020-11-29) | Heuristik #3: exit yang jelas, Cancel, dan **Undo**; snackbar undo singkat punya masalah visibilitas/durasi | 2026-10-02 |
| S5 | https://csrc.nist.gov/pubs/sp/800/92/final | Standar independen NIST SP 800-92 (2006) | Log management: rotasi/retensi/preservasi; **log asli tidak boleh diubah untuk keperluan bukti** ("Ensuring that the original logs are not altered supports their use for evidentiary purposes"); arsip log dilindungi kerahasiaan & integritasnya; log dapat memuat data sensitif → batasi akses & review | 2026-10-02 |
| S6 | https://nvlpubs.nist.gov/nistpubs/Legacy/SP/nistspecialpublication800-92.pdf | Dokumen NIST penuh | Audit record memuat: event sukses/gagal auth, perubahan kebijakan keamanan, perubahan akun (pembuatan/penghapusan, pemberian privilege), penggunaan privilege | 2026-10-02 |
| S7 | `web/ux/evidence/F13/standards-a11y.md` (URL di dalamnya) | Standar WCAG 2.2 + Material 3 | Target sentuh minimum WCAG 2.5.8 = 24×24 px; **kebijakan Sehatly 44 px** (`web/AGENTS.md`); kontras ≥ 4,5:1 (WCAG 1.4.3); status tidak warna-saja | 2026-10-01 |
| S8 | `web/ux/evidence/F13/nng-baymard.md` (URL di dalamnya) | Riset independen NN/g + Baymard | Tabel data: kolom pertama = pengenal, urutan sesuai kepentingan, **header lengket**, baris ≤ 80 karakter, paragraf `max-width: 70ch` | 2026-10-01 |

## Fakta/Uji yang dipakai di pattern F14

1. **Aksi massal** wajib: Select All (dengan jumlah terpilih terlihat), action bar kontekstual, umpan balik hasil + opsi pemulihan [S1]. Karena Sehatly **tidak punya endpoint undo**, pemulihan diganti **konfirmasi destruktif + laporan hasil** dan hanya untuk aksi yang bisa dibalik dengan aksi lain (mis. nonaktifkan → aktifkan kembali).
2. **Dialog konfirmasi destruktif** harus spesifik: sebut **jumlah + nama objek + konsekuensi**; untuk blast radius besar (>5 baris), minta aksi nonstandar (**ketik kata kunci**) [S2]. Jangan pasang konfirmasi pada aksi jinak (menandai baca, memfilter) [S2,S4].
3. **Pemisahan visual** aksi destruktif (mis. "Nonaktifkan") dari aksi jinak ("Buka", "Ubah") — posisi terpisah + gaya `destructive` [S3]; implementasi shadcn: tombol `variant="destructive"` tidak berdampingan langsung dengan tombol utama di baris yang sama tanpa separator; di menu baris, aksi destruktif paling bawah.
4. **Audit log**: catat pembuatan/penghapusan/perubahan privilege [S6]; **tidak boleh ada jalur edit/hapus** dari UI; jangan menjanjikan retensi yang tidak ada; batasi akses viewer (Sehatly: `audit.lihat` hanya admin/superadmin) dan jangan tampilkan data mentah sensitif (data sudah diredaksi `AuditColumnPolicy`) [S5].
5. **Tabel/laporan padat**: header lengket, kolom pertama pengenal (tanggal), tanpa pemotongan data penting [S8].
6. **Aksesibilitas**: target ≥ 44 px, kontras ≥ 4,5:1, fokus terlihat, status teks+ikon+warna [S7].

## Yang TIDAK bisa diverifikasi

- Efek kuantitatif "undo vs konfirmasi" pada admin klinik Indonesia (tidak ada studi lokal yang diakses).
- Angka minimum "Select All" per halaman (NN/g tidak menyebut ambang; keputusan Sehatly = di dokumen pattern).
- Audit log retention yang "benar" untuk UU PDP (tidak ada angka resmi di sumber yang diakses) → jadi pertanyaan terbuka.

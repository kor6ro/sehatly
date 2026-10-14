# Evidence F08 — Apple (HIG + konvensi Messages) — sumber pedoman, bukan aplikasi yang diamati

Kandidat dari daftar benchmark: "Apple Messages / iMessage docs for chat conventions". Yang dapat diverifikasi di web publik adalah **dokumentasi desain Apple (Human Interface Guidelines)**, bukan UI Messages yang diamati langsung (butuh perangkat Apple; tidak tersedia di lingkungan riset ini).

## Sumber

| # | URL | Jenis sumber | Tanggal akses | Versi/platform |
|---|---|---|---|---|
| 1 | https://developer.apple.com/design/human-interface-guidelines/notifications | HIG resmi — privasi notifikasi ("Avoid including sensitive, personal, or confidential information in a notification"), foreground handling | 2026-10-01 | HIG (HTML butuh JS; diverifikasi lewat endpoint JSON resmi Apple `developer.apple.com/tutorials/data/design/human-interface-guidelines/notifications.json`) |
| 2 | https://developer.apple.com/design/human-interface-guidelines/accessibility | HIG resmi — Dynamic Type, ukuran kontrol, kontras | 2026-10-01 | Sama: JSON resmi Apple |
| 3 | https://developer.apple.com/design/tips/ | Panduan desain resmi — target sentuh ≥44×44 poin, teks ≥11 poin, kontras | 2026-10-01 | Web |
| 4 | https://developer.apple.com/design/human-interface-guidelines/layout | HIG Layout — "Be prepared for text-size changes", safe area | 2026-10-01 | Web (sebagian via render indeks) |
| 5 | https://developer.apple.com/design/human-interface-guidelines/lists-and-tables | HIG Lists and tables — daftar baris untuk teks yang mudah dipindai | 2026-10-01 | Web (sebagian via render indeks) |
| 6 | https://developer.apple.com/design/human-interface-guidelines/designing-for-ios | HIG — kenyamanan jangkauan satu tangan (tengah/bawah layar) | 2026-10-01 | Web (sebagian via render indeks) |

**Batas akses:** halaman HIG HTML membutuhkan JavaScript (fetch HTML langsung gagal) → dikonfirmasi lewat **endpoint JSON dokumentasi milik Apple sendiri** (isi identik dengan HIG). Tidak ada perangkat Apple, tidak ada akun, tidak ada tangkapan layar yang diambil sendiri.

## Langkah terlihat (fakta)

1. Notifikasi (#1): "Avoid including sensitive, personal, or confidential information in a notification. You can't predict what people will be doing when they receive a notification…" → dasar aturan privasi notifikasi Sehatly.
2. Notifikasi (#1): "Use an alert — not a notification — to display an error message"; saat app di foreground, sajikan halus (badge/sematkan data baru) alih-alih notifikasi menabrak.
3. Pratinjau generik (#1): sediakan teks generik saat pratinjau dinonaktifkan (mis. "Pesan baru"), tanpa isi medis.
4. Aksesibilitas (#2, #3): kontrol default 44×44 poin (min 28×28 di iOS), teks dapat diperbesar **≥200%**, teks minimum 11 poin, kontras hingga 17 pt = 4.5:1.
5. Hierarki (#4, #5): siap menghadapi perubahan ukuran teks; teks dalam daftar baris agar mudah dipindai; jangkauan satu tangan → aksi utama di area tengah/bawah (#6).
6. Konvensi Messages: **tidak ada halaman HIG khusus "Messaging"** → konvensi chat harus disintesis dari Notifications/Layout/Lists/Accessibility. Ini keterbatasan bukti, bukan berarti konvensi tidak ada.

## Hitungan (titik awal: membuka ruang konsultasi → tugas inti)

**N/V** — Apple HIG adalah pedoman platform, bukan alur aplikasi; tidak menghasilkan layar/ketukan/field. Tidak dipakai untuk K1.

## State terlihat

- Tidak ada state aplikasi yang teramati; yang terlihat adalah **aturan** (notifikasi, ukuran teks, kontras). Kegunaannya untuk K4 (aksesibilitas) dan K6 (privasi notifikasi) di `scores/F08.md`.

## Red flag

- Tidak ditemukan dark pattern dalam dokumen Apple.
- Justru menjadi acuan anti-red-flag: melarang data sensitif di notifikasi.

## Yang TIDAK bisa diverifikasi

- Tampilan nyata aplikasi Messages (layout bubble, status, pengaturan) — tidak diakses.
- Perilaku kirim/pesan/offline iMessage.
- Penerapan nyata terhadap aplikasi Apple di Indonesia (pedoman bersifat lintas konteks) → K5 dinilai N/V (tidak ada pedoman konteks Indonesia/jaringan lambat yang terlihat).
- **Rubrik: K1, K2, K3, K5 = N/V.**

# Sehatly Web: aturan UI/UX (wajib)

Letakkan file ini di `web/AGENTS.md`. Aturan di sini mengikat setiap perubahan UI.

## Stack (jangan tambah library UI baru tanpa izin)
- React 19, react-router, Vite, Tailwind v4 (konfigurasi CSS-first via `@theme`, tidak ada `tailwind.config.js`).
- Komponen: shadcn/ui di atas Radix, `class-variance-authority`, `clsx`, `tailwind-merge`, `tw-animate-css`.
- Ikon: hanya `lucide-react`. Toast: hanya `sonner`. Tanggal: `@daypicker/react`. OTP: `input-otp`.
- Form: `react-hook-form` + `zod` resolver. Data: `@tanstack/react-query` + `ky`. Realtime: `laravel-echo` (Reverb).
- Sebelum memakai API library, cek dokumentasi terbaru lewat Context7. Jangan mengandalkan ingatan.

## Sebelum menulis UI
1. Baca `web/src/styles/app.css` — itu **satu-satunya sumber token** (`@theme` + `:root` + `.dark`). Semua warna, font, spacing, radius wajib memakai token; dilarang hardcode hex/rgb/oklch di komponen. `web/design-tokens.md` sengaja **tidak ada**; bila file itu muncul lagi, anggap usang — `app.css` yang menang.
2. Load skill `frontend-design`.
3. Cari komponen shadcn yang sudah ada (shadcn MCP) sebelum membuat komponen sendiri.
4. Status (appointment, konsultasi, resep) diambil dari enum hasil `php artisan sehatly:enums`. Jangan menulis string status manual.
5. Baca `web/ux/patterns/<flow-id>.md` untuk flow yang dikerjakan (daftar flow di `web/ux/flows.md`) dan `web/ux/patterns/_global.md`. Jika pattern untuk flow itu belum ada, BERHENTI dan minta benchmark dijalankan dulu (`web/ux/BENCHMARK_PROMPT.md`). Jangan merancang flow dari nol atau dari ingatan.

## Audit token `app.css` (lapor selisih saja, jangan ubah)
- Token Sehatly hanya ada di `web/src/styles/app.css`. Sebelum mengubah UI, auditi file itu terhadap aturan di dokumen ini: font bukan Inter/Roboto/Arial; hanya satu warna aksen (`--primary`); warna lain hanya untuk status (`--success`, `--warning`, `--destructive`); tanpa gradient/glassmorphism/glow; radius memakai token.
- Laporkan **selisihnya saja** (token yang melanggar, aksen tambahan, kontras yang perlu diukur). Jangan mengubah `app.css` atau menambah token tanpa persetujuan pemilik produk.

## Larangan
- Gradient (terutama ungu/biru), glassmorphism, glow, blob dekoratif.
- Emoji sebagai ikon atau dekorasi.
- Font Inter/Roboto/Arial default. Pakai font di token.
- Shadow tebal, `rounded-2xl` di semua tempat, card di dalam card, border + shadow bersamaan.
- Hero generik ("Revolusi kesehatan digital", "Solusi terbaik untuk Anda") dan copy marketing kosong.
- Animasi tanpa fungsi. Transisi hanya untuk umpan balik (hover, fokus, buka/tutup), maksimal 150-200 ms.
- Lorem ipsum. Pakai data realistis: nama dokter Indonesia, spesialisasi, jadwal, nama obat, dosis.
- Warna aksen kedua. Hanya ada satu warna aksen (primary). Warna lain hanya untuk status.

## Wajib di setiap layar
- Bahasa Indonesia, nada tenang dan jelas. Tanggal `id-ID`, tampil di zona waktu pengguna (WIB/WITA/WIT), tulis label zona waktunya pada jadwal.
- Tiga state selalu ada: loading (skeleton, bukan spinner layar penuh), kosong (dengan tindakan lanjut), error (dengan tombol coba lagi).
- Status ditampilkan dengan teks + ikon + warna, jangan warna saja.
- Target sentuh minimal 44 px, kontras teks minimal 4.5:1, fokus keyboard terlihat, label pada semua input.
- Mobile-first. Desain di 390 px lebih dulu, lalu 768 px dan 1280 px.
- Data medis (nama obat, dosis, aturan pakai, waktu konsultasi) selalu memakai ukuran teks yang mudah dibaca dan tidak dipotong dengan ellipsis.
- Jangan menampilkan data sensitif di toast, judul tab, atau URL.

## Pola UX berbasis benchmark
- `web/ux/patterns/*.md` adalah sumber kebenaran untuk alur, urutan langkah, state, dan copy. Jika ada konflik dengan selera pribadi agent, pattern yang menang.
- Penuhi semua kriteria penerimaan (AC) di pattern. Di laporan akhir, centang AC satu per satu dengan bukti (screenshot atau hasil tes).
- Jangan menyalin aset, ilustrasi, ikon khas, nama merek, atau teks dari aplikasi acuan. Pola interaksi boleh diadaptasi, tampilan dan copy harus milik Sehatly.
- Jangan membuat akun, login, atau mengirim data ke aplikasi/situs pihak ketiga untuk keperluan riset. Jangan memasukkan data kesehatan nyata ke mana pun.
- Jika menemukan pola yang lebih baik atau pattern yang keliru saat implementasi, catat di bagian "Pertanyaan terbuka" pada file pattern dan tanyakan, jangan menyimpang diam-diam.
- Folder `web/ux/refs/` dan file gambar di `web/ux/evidence/` tidak boleh di-commit.

## Loop verifikasi visual (wajib setelah membuat/mengubah halaman)
1. Jalankan `npm run dev` (di `web/`), buka halaman dengan Playwright (MCP atau harness di `tests/e2e`).
2. Screenshot di 390 px dan 1280 px.
3. Kritik hasil terhadap `web/src/styles/app.css` dan larangan di atas. Tulis daftar masalah singkat.
4. Perbaiki, ulangi maksimal 2 kali.
5. Jalankan `npm run types:check` dan `npm run test:unit`. Untuk alur kritis jalankan juga `npm run test:e2e`.

## Cara melapor
Setelah selesai, laporkan: file yang berubah, token yang dipakai, screenshot yang diambil, dan hal yang belum bisa diverifikasi.

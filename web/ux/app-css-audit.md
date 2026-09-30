# Audit `web/src/styles/app.css` terhadap aturan `web/AGENTS.md`

Tanggal: 2026-10-01 · Sifat: **laporan selisih saja — tidak ada perubahan pada `app.css` atau token apa pun.**
Sumber token: `web/src/styles/app.css` (`@theme` + `:root` + `.dark`). `web/design-tokens.md` tidak ada
dan tidak dibuat.

## Ringkasan

| Aturan `AGENTS.md` | Status | Selisih |
|---|---|---|
| Font bukan Inter/Roboto/Arial | **Patuh** | `--font-sans: 'Instrument Sans', ui-sans-serif, system-ui, …` |
| Tanpa gradient / glassmorphism / glow / blob | **Patuh** | Tidak ada `gradient`, `blur`, `backdrop-filter`, atau glow di `app.css` |
| Hanya satu warna aksen (`--primary`); warna lain hanya untuk status | **Patuh dengan catatan** | `--primary` satu-satunya aksen. `--success`/`--warning`/`--destructive` = hue status. `--chart-1..5` memuat hue non-status (lihat D-3). |
| Radius memakai token | **Patuh** | `--radius: 0.625rem`; turunan `--radius-lg/md/sm`. `.dark` tidak mendefinisikan ulang (dapat diterima). |
| Kontras teks ≥ 4,5:1 | **Selisih** | `--destructive-foreground` identik dengan `--destructive` di mode terang (lihat D-1); `--success-foreground` perlu diukur (D-2). |
| Spacing memakai token | **Selisih aturan** | Tidak ada token `--spacing-*`; skala spacing sepenuhnya default Tailwind v4 (D-4). |

## Selisih yang dilaporkan (jangan diubah tanpa persetujuan)

**D-1 (P1, cacat pasti) — `--destructive-foreground` = `--destructive` di `:root`.**
`app.css:87-88` menetapkan keduanya `oklch(0.577 0.245 27.325)`. Teks ber-`--destructive-foreground`
di atas latar `--destructive` menghasilkan rasio kontras **1,0:1**, jauh di bawah 4,5:1 yang diwajibkan
`AGENTS.md`. Di `.dark` keduanya berbeda (`0.396` vs `0.637`), jadi cacat ini khusus mode terang.
Dampak: setiap teks di dalam tombol/badge `destructive` berpotensi tidak terbaca. Perlu keputusan
nilai pengganti (mis. foreground terang), bukan perubahan diam-diam.

**D-2 (P1, perlu diukur) — kontras `--success-foreground` pada `--success`.**
`:root` memakai `--success: oklch(0.596 0.118 163.224)` dengan teks hampir putih
`--success-foreground: oklch(0.985 0 0)`. Hijau sedang dengan teks putih umumnya berada di bawah
4,5:1 untuk teks normal. Belum diukur secara numerik di sesi ini; **wajib diukur** sebelum dipakai
untuk teks kecil (badge status). `--warning` (kuning gelap + teks hampir hitam) tampak aman.

**D-3 (P2, catatan) — palet `--chart-1..5` memuat hue non-status.**
`--chart-1: oklch(0.646 0.222 41.116)` … `--chart-5` termasuk ungu `oklch(0.488 0.243 264.376)`
(di `.dark`). Ini bukan pelanggaran selama hanya dipakai untuk data-viz (grafik), tetapi tidak boleh
bocor ke UI umum — aturan "hanya satu warna aksen (primary)" akan jebol bila salah satu hue chart
dipakai sebagai aksen. Catat sebagai batas pemakaian.

**D-4 (P2, selisih aturan vs kenyataan) — tidak ada token spacing/ukuran teks.**
`AGENTS.md` berbunyi "spacing wajib memakai token", tetapi `app.css` tidak mendefinisikan
`--spacing-*` maupun `--font-size-*`; skala spacing/teks berasal dari default Tailwind v4. Ini sudah
dicatat sebagai usulan di `web/ux/patterns/_global.md` §6. Pilih: tambah token, atau perlonggar
aturan di `AGENTS.md`.

## Yang TIDAK diukur di sesi ini

- Rasio kontras numerik untuk **semua** pasangan warna (hanya D-1 yang pasti dari nilai identik;
  D-2 perlu alat). Tidak ada perubahan yang dibuat untuk "memperbaiki" tanpa angka.
- Kontras mode gelap secara keseluruhan.
- Apakah `--chart-*` benar-benar hanya dipakai di grafik (perlu grep pemakaian di komponen).

## Tindakan yang diminta

1. Putuskan nilai `--destructive-foreground` mode terang (D-1) — cacat pasti.
2. Ukur D-2 dan putuskan bila gagal.
3. Putuskan D-4 (token vs perlonggar aturan).
4. `AGENTS.md` sudah diarahkan ke `app.css`; tidak ada berkas token baru yang dibuat.

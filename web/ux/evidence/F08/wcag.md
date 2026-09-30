# Evidence F08 — WCAG 2.2 (standar aksesibilitas)

**Jenis:** standar internasional (bukan aplikasi). Dipakai untuk kriteria aksesibilitas pattern F08 dan untuk AC yang dapat diuji. Bukan objek penilaian skor aplikasi.

## Sumber

| # | URL | Jenis sumber | Tanggal akses |
|---|---|---|---|
| 1 | https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html | Dokumen pemahaman resmi W3C — SC 2.5.8 Target Size (Minimum) | 2026-10-01 |
| 2 | https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html | Dokumen pemahaman resmi W3C — SC 4.1.3 Status Messages | 2026-10-01 |
| 3 | https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html | Dokumen pemahaman resmi W3C — SC 1.4.3 Contrast (Minimum) | 2026-10-01 |

## Fakta terverifikasi (kutipan pendek)

1. **2.5.8 Target Size (Minimum) — Tingkat AA**: ukuran target sentuh setidaknya **24 × 24 CSS px** (kecuali pengecualian: spacing, inline, pengendali yang dikendalikan agen, dsb.). Dasar minimum standar; kebijakan Sehatly lebih ketat (**≥44 px**, lihat `web/AGENTS.md`).
2. **4.1.3 Status Messages — Tingkat AA**: "The purpose of each user interface component that presents a status message can be programmatically determined through **role or properties**" → pesan status (konektivitas, "pesan terkirim", "menyambungkan ulang") harus diumumkan pembaca layar **tanpa memindahkan fokus**. Dasar `role="status"` / `aria-live="polite"` pada strip koneksi dan pengumuman pesan baru.
3. **1.4.3 Contrast (Minimum) — Tingkat AA**: teks biasa rasio kontras **≥4,5:1**; teks besar (≥18 poin atau 14 poin tebal — kira-kira 24 px / 18,5 px) **≥3:1**; komponen nonaktif dikecualikan. Rasioalasan terkait usia: "20/40 is commonly reported as typical visual acuity of elders at roughly age 80" → relevan untuk pengguna lansia Sehatly.

## Hitungan / State / Red flag

- **Hitungan:** N/V (standar, bukan aplikasi).
- **State terlihat:** tidak ada.
- **Red flag:** tidak ada.
- **TIDAK bisa diverifikasi:** WCAG tidak menentukan pola produk chat (urutan pesan, read receipt, dsb.); hanya membatasi presentasi dan pengumuman.

## Batasan

- Angka 24 px WCAG lebih rendah dari kebijakan Sehatly (44 px) → pattern F08 memakai 44 px.
- Dokumen Understanding W3C yang dipakai, bukan teks spesifikasi penuh; kedua bentuk berasal dari sumber resmi yang sama (W3C).

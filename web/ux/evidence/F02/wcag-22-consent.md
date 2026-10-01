# F02 — WCAG 2.2 untuk UI persetujuan (bukan aplikasi: standar aksesibilitas)

Format: `[URL] [akses 2026-10-01]`. Tidak dinilai sebagai aplikasi; dasar kriteria 4 pada
`web/ux/scores/F02.md` dan bagian Aksesibilitas pada `web/ux/patterns/F02.md`.

## Sumber

| # | Sumber | Jenis | Versi terlihat |
|---|---|---|---|
| S1 | https://www.w3.org/TR/WCAG22/ [akses 2026-10-01] | Spesifikasi W3C Recommendation | 12 December 2024 (versi `/TR/2024/REC-WCAG22-20241212/`) |
| S2 | https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html [akses 2026-10-01] | Dokumen Understanding W3C | diperbarui 2026 |
| S3 | https://www.w3.org/WAI/WCAG22/Understanding/non-text-contrast.html [akses 2026-10-01] | Understanding W3C | diperbarui 2025–2026 |
| S4 | https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html [akses 2026-10-01] | Understanding W3C | diperbarui 2026 |
| S5 | https://www.w3.org/WAI/WCAG22/Understanding/target-size-enhanced.html [akses 2026-10-01] | Understanding W3C | diperbarui 2025 |
| S6 | https://www.w3.org/WAI/WCAG22/Understanding/labels-or-instructions.html [akses 2026-10-01] | Understanding W3C | diperbarui 2026 |
| S7 | https://www.w3.org/WAI/WCAG22/Understanding/redundant-entry.html [akses 2026-10-01] | Understanding W3C | diperbarui 2026 |
| S8 | https://www.w3.org/WAI/WCAG22/Understanding/name-role-value.html [akses 2026-10-01] | Understanding W3C | diperbarui 2025 |
| S9 | https://www.w3.org/WAI/WCAG22/Understanding/focus-visible.html [akses 2026-10-01] | Understanding W3C | diperbarui 2026 |
| S10 | https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html [akses 2026-10-01] | Understanding W3C | diperbarui 2026 |
| S11 | https://www.w3.org/WAI/WCAG22/Understanding/parsing.html [akses 2026-10-01] | Understanding W3C | penghapusan 4.1.1 |

## Langkah terlihat (kriteria yang relevan dengan UI persetujuan, angka diverifikasi)

| Kriteria | Level | Isi terverifikasi | Relevansi ke F02 |
|---|---|---|---|
| 1.4.3 Contrast (Minimum) [S2] | **AA** | teks normal ≥ **4,5:1**; teks besar ≥ 3:1 | label slot, status, tombol |
| 1.4.11 Non-text Contrast [S3] | **AA** | komponen UI & statusnya ≥ **3:1** | kotak pilihan/border tombol terpilih |
| 2.5.8 Target Size (Minimum) [S4] | **AA** | ≥ **24×24 CSS px** (ada pengecualian jarak/selubung) | seluruh kontrol persetujuan |
| 2.5.5 Target Size (Enhanced) [S5] | **AAA** | ≥ **44×44 CSS px** | dipakai Sehatly sebagai ambang (AGENTS.md) |
| 3.3.2 Labels or Instructions [S6] | **A** | label/petunjuk untuk kontrol | label tiap slot + pilihan |
| 3.3.7 Redundant Entry [S7] | **A** | jangan minta ulang data yang sudah diberikan | jawaban tidak boleh diulang |
| 4.1.2 Name, Role, Value [S8] | **A** | nama/nilai kontrol tersedia untuk AT | status "Disetujui/Tidak/ belum" |
| 2.4.7 Focus Visible [S9] | **AA** | fokus keyboard terlihat | navigasi checklist |
| 1.4.4 Resize Text [S10] | **AA** | perbesar **200%** tanpa kehilangan isi | lansia |
| 4.1.1 Parsing [S11] | **dihapus** | "no longer has utility and is removed" | jangan dipakai sebagai syarat |

## Hitungan

Bukan aplikasi — tidak dihitung.

## State terlihat

Tidak berlaku (dokumen standar). WCAG menuntut status dapat diakses secara programatis
(4.1.2 Name/Role/Value) sehingga state "belum dijawab" harus punya nama dan nilai, bukan
hanya warna [S8].

## Red flag

Tidak ada. Catatan: WCAG adalah *subset* otomatis (axe) — pemeriksaan keyboard dan
screen-reader manual tetap diperlukan (argumen yang sama dengan `web/tests/e2e/a11y.ts`).

## Yang TIDAK bisa diverifikasi

- Penerapan pada aplikasi acuan mana pun (WCAG bukan aplikasi).
- Status WCAG 2.3/2.4 yang lebih baru (tidak diminta, tidak di-fetch) → **TIDAK
  TERVERIFIKASI**.

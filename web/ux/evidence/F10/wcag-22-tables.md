# F10 — WCAG 2.2 / WAI: daftar & tabel data yang bisa diakses (domain lain)

Standar untuk langkah **menampilkan daftar/tabel riwayat dan pesan status** di Sehatly.
Format: `[URL] [akses 2026-10-01] [jenis sumber]`.

## Sumber

| # | Sumber | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.w3.org/TR/WCAG22/ [akses 2026-10-01] | Spesifikasi W3C (Recommendation 12 Des 2024) | WCAG 2.2; fetch terpotong → teks SC spesifik dari halaman Understanding |
| S2 | https://www.w3.org/WAI/WCAG22/Understanding/info-and-relationships.html [akses 2026-10-01] | Understanding SC 1.3.1 (Level A), diperbarui 9 Mar 2026 | Hubungan struktur harus programatis |
| S3 | https://www.w3.org/WAI/WCAG22/Understanding/contrast-minimum.html [akses 2026-10-01] | Understanding SC 1.4.3 (AA), diperbarui 6 Sep 2026 | 4,5:1 teks normal; 3:1 teks besar |
| S4 | https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html [akses 2026-10-01] | Understanding SC 2.5.8 (AA, BARU di 2.2), diperbarui 11 Mei 2026 | **24 × 24 CSS pixel** |
| S5 | https://www.w3.org/WAI/WCAG22/Understanding/headings-and-labels.html [akses 2026-10-01] | Understanding SC 2.4.6 (AA), diperbarui 9 Mar 2026 | Heading & label deskriptif |
| S6 | https://www.w3.org/WAI/WCAG22/Understanding/status-messages.html [akses 2026-10-01] | Understanding SC 4.1.3 (AA), diperbarui 11 Mei 2026 | Pesan status tanpa ambil fokus |
| S7 | https://www.w3.org/WAI/WCAG22/Understanding/timing-adjustable.html [akses 2026-10-01] | Understanding SC 2.2.1 (A), diperbarui 10 Agu 2026 | Batas waktu bisa dimatikan/disesuaikan/diperpanjang |
| S8 | https://www.w3.org/WAI/tutorials/tables/ [akses 2026-10-01] | Tutorial resmi W3C/WAI (Updated 16 Feb 2023) | `<th>`/`<td>`/`scope`/`<caption>` |
| S9 | https://www.w3.org/WAI/ARIA/apg/patterns/grid/ [akses 2026-10-01] | Pola resmi W3C/WAI APG | Grid vs tabel statis |
| S10 | https://www.w3.org/WAI/WCAG22/Techniques/html/H51 [akses 2026-10-01] | Teknik resmi W3C (diperbarui 12 Jan 2026) | "Using table markup to present tabular information" |
| S11 | https://www.w3.org/WAI/WCAG22/Techniques/general/G1 [akses 2026-10-01] | Teknik resmi W3C (diperbarui 9 Mar 2026) | **G1 = bypass blocks (SC 2.4.1), BUKAN tabel** |
| S12 | https://www.w3.org/WAI/WCAG22/Techniques/general/G63 [akses 2026-10-01] | Teknik resmi W3C (diperbarui 15 Jul 2025) | **G63 = site map (SC 2.4.5/2.4.8), BUKAN tabel** |
| S13 | https://www.w3.org/WAI/WCAG22/Techniques/general/G61 [akses 2026-10-01] | Teknik resmi W3C (diperbarui 15 Jul 2025) | **G61 = urutan komponen berulang (SC 3.2.3), BUKAN tabel** |

## Langkah terlihat (fakta � sumber pedoman, bukan aplikasi)

1. **Klarifikasi penting:** teknik **G1/G63/G61 bukan teknik tabel** [S11][S12][S13]. Teknik tabel yang benar: **H51** (markup tabel, relasi SC 1.3.1) [S10], plus **H39** (caption), **H63** (scope), **H43** (id/headers); daftar → **H48** (`ol`/`ul`/`dl`); heading → **H42** [S2].
2. **SC 1.3.1 (A)** [S2]: hubungan informasi/struktur harus bisa ditentukan secara programatis atau tersedia dalam teks; contoh resmi = tabel dengan header yang benar.
3. **SC 1.4.3 (AA)** [S3]: **4,5:1** teks normal; **3:1** teks besar (≥18pt atau ≥14pt bold ≈ 24px/18,5px CSS); ambang tidak dibulatkan ("4.499:1 tidak lolos"); berlaku juga untuk placeholder & teks hover/fokus.
4. **SC 2.5.8 (AA, baru di 2.2)** [S4]: target pointer **minimal 24 × 24 CSS pixel**; 5 pengecualian (Spacing, Equivalent, Inline, User Agent Control, Essential); **independen dari zoom**; best practice tetap penuhi ukuran minimum.
5. **SC 2.4.6 (AA)** [S5]: heading/label harus **mendeskripsikan topik/tujuan**; tidak mewajibkan adanya heading, tetapi yang ada harus akurat; teknik G130/G131.
6. **SC 4.1.3 (AA)** [S6]: pesan status harus bisa ditentukan programatis via role/properti **tanpa menerima fokus**; **"18 results returned" dan "No results returned" ADALAH status message** — daftar hasil pencarian sendiri BUKAN; teknik: ARIA22 `role=status`, ARIA19 `role=alert`, ARIA23 `role=log`; failure F103.
7. **SC 2.2.1 (A)** [S7]: setiap batas waktu wajib minimal satu dari: Turn off, Adjust (≥10× durasi default), Extend (peringatan + ≥20 detik, ≥10× perpanjangan), atau pengecualian; **sesi yang kedaluwarsa otomatis termasuk batas waktu yang ditetapkan konten**.
8. **Tabel data** [S8]: `<th>` untuk header, `<td>` untuk data; `scope="col|row"`; `<caption>` mengidentifikasi topik tabel; tabel layout bukan cakupan.
9. **Grid vs tabel** [S9]: `grid` = composite widget dengan manajemen fokus manual (satu Tab stop + panah); untuk **data tabular statis gunakan `table`**; jangan menumpuk role ARIA di atas `<table>` HTML yang sudah punya semantics implisit; `aria-sort` untuk header yang bisa diurutkan.

## Hitungan

Tidak berlaku (standar, bukan aplikasi).

## State terlihat

- Pesan status dinamis (hasil pencarian, jumlah baris, "tidak ada data") wajib `role=status`/live region [S6].

## Red flag

- Tidak ada.

## Yang TIDAK bisa diverifikasi

- Konfirmasi otomatis (axe) hanya subset WCAG; pemeriksaan keyboard/pembaca layar manual tetap diperlukan.
- Nilai kontras token Sehatly tidak diukur di file ini (diukur saat implementasi; lihat `web/ux/app-css-audit.md`).

# F13 — Standar aksesibilitas & sentuh (WCAG, Material 3, Apple HIG)

**Jenis:** standar/pedoman platform (bukan aplikasi). Dasar kritikia 4 dan AC terukur. Bukan objek penilaian skor.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html | Dokumen pemahaman resmi W3C | SC 2.5.8 Target Size (Minimum): **24×24 CSS px** + 5 pengecualian; "Updated 11 May 2026"; WCAG 2.2 = Rec 12 Des 2024 | 2026-10-01 |
| S2 | https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html | Dokumen pemahaman resmi W3C | SC 1.4.3: teks biasa **≥4,5:1**, teks besar (≥18pt/≥14pt tebal) ≥3:1; "Updated 06 Sep 2026"; pengecualian insidental/logo | 2026-10-01 |
| S3 | https://m3.material.io/foundations/designing/structure | Docs Material 3 (teks terindeks; render halaman butuh JS) | Target sentuh **≥48×48 dp**; pointer target minimum **44×44 dp**; pemisahan **8 dp** demi "balanced information density"; iOS disebut rekomendasi 44×44 | 2026-10-01 |
| S4 | https://m3.material.io/foundations/layout/grids-spacing/density | Docs Material 3 (JS-gated render) | Kepadatan & penskalaan: **orang harus opt-in** ke layout padat; kontrol kepadatan wajib pertahankan 48×48; jangan skala di bawah 48×48 default; hati-hati menaikkan kepadatan | 2026-10-01 |
| S5 | https://m3.material.io/foundations/designing/flow | Docs Material 3 (JS-gated render) | Keyboard: shortcut ≥2 tombol; sediakan halaman daftar/tutorial semua shortcut kustom; definisikan fokus awal + urutan tab stop | 2026-10-01 |
| S6 | https://developer.apple.com/design/human-interface-guidelines/layout | Apple HIG (**render halaman butuh JS**) | Terindeks: dukung Dynamic Type/text-size changes, safe area. **Angka 44×44 pt TIDAK TERVERIFIKASI di sumber primer** (hanya sumber sekunder: adrianroselli.com, humanstandards.org) | 2026-10-01 |
| S7 | https://webaim.org/resources/contrastchecker/ (alat) + WCAG 1.4.3 | Alat/standar | Kontras dihitung dari relatif luminance | 2026-10-01 |

## Fakta terverifikasi (kutipan pendek)

1. **WCAG 2.2 SC 2.5.8 (AA)**: target pointer ≥ **24×24 CSS px**, pengecualian: Spacing (lingkaran 24px tidak boleh tumpang tindih), Equivalent, Inline, User Agent, Essential [S1]. Poin: kebijakan Sehatly (**≥44 px**, `web/AGENTS.md`) lebih ketat dari standar — pertahankan 44.
2. **WCAG 2.1 SC 1.4.3 (AA)**: **≥4,5:1**; teks besar ≥3:1; rasio **tidak dibulatkan** (4,499 gagal) [S2].
3. **Material 3**: 48×48 dp default, 44×44 dp pointer minimum, 8 dp pemisahan [S3]; **layout padat = opt-in pengguna + kontrol kepadatan**, jangan paksa [S4]; shortcut keyboard ≥2 tombol + halaman daftar shortcut [S5].
4. **Apple HIG 44×44 pt**: **TIDAK TERVERIFIKASI di sumber primer** (halaman JS-gated); hanya sumber sekunder yang menyebutnya → jangan dikutip sebagai fakta Apple; angka 44 Sehatly tetap berlaku via kebijakan internal + Material 44dp [S3][S6].
5. Halaman HIG/Layout Apple dan m3.material.io dirender via JS — teks dikonfirmasi lewat indeks pencarian URL yang sama; dicatat sebagai batasan verifikasi.

## Hitungan / State / Red flag

- Hitungan: N/A (standar). State: N/A. Red flag: tidak ada.

## Yang TIDAK bisa diverifikasi

- Kutipan langsung HIG Apple (termasuk 44×44 pt) dari halaman primer.
- Penerapan 24/44 px di aplikasi benchmark mana pun (tidak diuji otomatis).

## Batasan

- SC 2.5.8 = minimum AA 24px; Sehatly memakai ≥44 px (kebijakan + Material). SC 1.4.3 memakai 4,5:1 persis.

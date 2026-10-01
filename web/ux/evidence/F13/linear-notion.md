# F13 — Linear dan Notion (efisiensi pengguna berulang: keyboard-first, tampilan padat)

**Jenis:** sumber domain lain (bukan medtech) — dipakai khusus untuk kritikia 5 pengganti "Efisiensi pengguna berulang". Bukan objek penilaian skor aplikasi medis.

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://linear.app/enablement/guide/navigating-linear | Panduan resmi Linear | Cmd/Ctrl+K command menu, `/` cari, `G then I` inbox, `?` daftar shortcut | 2026-10-01 |
| S2 | https://linear.app/changelog/2021-03-25-keyboard-shortcuts-help | Changelog resmi (25 Mar 2021) | Layar shortcut **bisa dicari**; buka dengan `?` | 2026-10-01 |
| S3 | https://linear.app/docs/board-layout | Docs resmi | `Cmd/Ctrl+B` list↔board; `X` pilih, `Shift+X` multi; `Alt+Shift+↑/↓` pindah tanpa drag; `T` tutup swimlane | 2026-10-01 |
| S4 | https://linear.app/docs/display-options | Docs resmi | `Shift+V` display options; group/order; **Display properties** = pilih properti tampil per baris (kontrol kepadatan) | 2026-10-01 |
| S5 | https://www.notion.com/help/keyboard-shortcuts | Help resmi Notion | `Cmd/Ctrl+K/P` cari-lompat; `Cmd/Ctrl+F` di halaman; multi-select baris → `Cmd/Ctrl+/` **edit massal** | 2026-10-01 |
| S6 | https://www.notion.so/help/views-filters-and-sorts | Help resmi Notion | Table/Board; filter **AND/OR bersarang 3 lapis**; filter bisa disimpan bersama atau pribadi; sub-group | 2026-10-01 |
| S7 | https://www.notion.com/help/category/database-views | Help resmi Notion | Daftar tipe view (Table, Board, Timeline, dll.) | 2026-10-01 |

**Verifikasi:** semua halaman terbaca gratis tanpa login pada 2026-10-01; Linear/Notion aktif (dokumen resmi terindeks). **`linear.app/docs/keyboard-shortcuts` = 404**; daftar resmi ada di dalam aplikasi (`?`) → daftar lengkap **TIDAK TERVERIFIKASI**.

## Fakta terverifikasi (ringkas, parafrase)

**Linear (S1–S4):**
1. **Command menu** `Cmd/Ctrl+K` = semua aksi halaman tanpa lepas keyboard; `/` cari lintas issue/project/dokumen + lompat by id (`LIN-123`).
2. Navigasi berurutan: `G` lalu `I` = Inbox, `G` lalu `M` = My Issues; `?` = daftar shortcut lengkap (changelog: layar shortcut dibuat **searchable** [S2]).
3. Pilihan keyboard menggantikan drag: pindah issue ke kolom tanpa drag (`Alt+Shift+↑/↓`) [S3].
4. **Kepadatan terkontrol pengguna**: `Shift+V` → Display options: grouping, ordering, dan **Display properties** (pilih properti mana yang tampil di tiap baris) [S4] → kepadatan = preferensi, bukan tetap.
5. `linear.app/docs/keyboard-shortcuts` → 404; canonical di dalam app.

**Notion (S5–S7):**
1. Multi-select baris/kartu database → `Cmd/Ctrl+/` **edit massal** (banyak sel/kelompok blok sekaligus) [S5].
2. `Cmd/Ctrl+R` isi kanan, `Cmd/Ctrl+D` isi bawah untuk sel tabel [S5].
3. View Table (baris = halaman, kolom = properti) vs Board (kelompokkan per properti) [S6].
4. Filter canggih **AND/OR bersarang maksimal 3 lapis**; filter dapat **disimpan untuk semua orang atau pribadi** [S6] — konsistensi tim + kontrol pengguna.
5. Sub-grouping = lapis kedua (mis. kelompok status, sub-kelompok prioritas) [S6].

## Hitungan (efisiensi berulang)

- N/A sebagai aplikasi yang ditiru langkahnya; yang dipetakan adalah **mekanisme**: 1 pintasan = 1 aksi (Linear); edit massal multi-select (Notion). Tidak ada angka layar/ketukan yang dibandingkan — bukan alur tugas medis.

## State terlihat

- Tidak didokumentasikan state loading/kosong/error di halaman ini → **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ditemukan pada sumber ini. Kedua produk = alat kerja internal (bukan vertikal kesehatan) — pola yang diambil hanya mekanisme keyboard/kepadatan, **bukan** copy/brand.

## Yang TIDAK bisa diverifikasi

- Daftar shortcut Linear yang lengkap (di dalam app).
- Kepadatan baris/tinggi row yang direkomendasikan Linear (tidak ada dokumen publik) → **TIDAK TERVERIFIKASI**.
- Jumlah pemakaian shortcut aktual (adopsi nyata) — klaim "power users control most functionality" [S2] = klaim vendor.
- Guideline kepadatan Baymard per komponen (berbayar).

## Catatan adaptasi

Untuk Sehatly (browser, tanpa `ui/command`): command palette `Cmd+K` **tidak bisa ditiru utuh** (butuh komponen command; tidak ada di 27 shadcn primitives). Yang boleh diambil: pintasan halaman sederhana (`?` bantuan shortcut, navigasi `G`-prefiks opsional via keydown biasa) dan **kontrol kepadatan per pengguna** (pilih kolom/properti tampil) yang bisa dibangun dengan `toggle-group`/`checkbox` yang ada.

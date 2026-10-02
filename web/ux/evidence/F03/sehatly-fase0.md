# Evidence F03 — Sehatly Fase 0 (inventaris kode nyata: cari dokter & filter)

Tanggal inventaris: **2026-10-02**. Read-only terhadap kode. Semua klaim berasal dari file yang dibaca langsung (path + baris). Ini **baseline fakta**, bukan penilaian UX.

## 1. Endpoint pencarian & filter

| Method + path | Nama | Guard | File:baris |
|---|---|---|---|
| `GET /api/v1/dokter` | `dokter.index` | publik | `routes/api.php:323-324` |
| `GET /api/v1/dokter/{dokter}` | `dokter.show` | publik, `whereNumber` | `routes/api.php:326-328` |
| `GET /api/v1/dokter/{dokter}/jadwal` | `dokter.jadwal` | publik, `whereNumber` | `routes/api.php:353-355` |
| `GET /api/v1/dokter/{dokter}/slot` | `dokter.slot` | publik, `whereNumber` | `routes/api.php:357-359` |
| `GET /api/v1/master-spesialisasi` | `master-spesialisasi.index` | publik | `routes/api.php:361-362` |
| `GET /api/v1/referensi/spesialisasi` | referensi loop | publik, `?q=` | `ReferensiEndpoint.php:147`; `routes/api.php:846-849` |

**Tidak ada** route `/referensi/faskes`, `/referensi/dokter`, atau `/referensi/poliklinik` (daftar tertutup 13 slug, `ReferensiEndpoint.php:132-155`).

### Parameter `GET /dokter` (`IndexDokterRequest.php:72-94`)
```php
'spesialisasi'         => nullable, string, max:10, exists master_spesialisasi (kode atau id),
'tipe'                 => nullable, in DokterDirectoryService::TIPE_DOKTER,
'search'               => nullable, string, max:150,
'tersedia_telemedisin' => nullable, boolean,
'page'                 => nullable, integer, min:1,
'per_page'             => nullable, integer, min:1, max:100,
```
**Tidak ada parameter `sort`/`order_by`** — pencarian `sort|order_by|orderBy` di `app/Http/Requests/Dokter` = 0 hasil. **Tidak ada filter `faskes`/kota/rating/biaya.**

`GET /dokter/{dokter}/slot` → `IndexSlotDokterRequest.php:89-94`: `tanggal` wajib `date_format:Y-m-d`. `GET /dokter/{dokter}/jadwal` tidak memakai FormRequest (`DokterController.php:208`).

### OpenAPI
`docs/openapi.yaml`: `/api/v1/dokter` (:1242-1264), `/dokter/{dokter}` (:1302-1326), `/dokter/{dokter}/jadwal` (:1327-1351), `/dokter/{dokter}/slot` (:1352-1379), `/master-spesialisasi` (:1943-1962), `/referensi/spesialisasi` (:3178-3200). **Parameter query tidak dipublikasikan** di dokumen generated (tidak ada blok `parameters:` untuk filter), hanya path param `{dokter}`.

## 2. Implementasi backend (`DokterDirectoryService.php`)

- **Filter**: `tipe` (:455-460), `spesialisasi` (:478-505; `EXISTS` join `dokter_spesialisasi`+`master_spesialisasi`, cocok `kode` atau `id`), `search` (:520-537), `tersedia_telemedisin` (:557-571). Tidak ada filter lain.
- **Kolom pencarian**: hanya `v_dokter_katalog.nama_lengkap` (= `users.nama_lengkap`), `LIKE '%...%'`, escape `%`/`_` via `addcslashes` (:508-536). Bukan spesialisasi/bio.
- **Urutan default (tetap, tidak dapat dipilih pengguna)**: `rating_rata_rata DESC, jumlah_konsultasi DESC, dokter_id ASC` (:274-278) + `withQueryString()`.
- **Paginasi**: `per_page` default **15** (:211), maks **100** (:220); meta `ApiResponse::pageMeta()` → `{current_page,last_page,per_page,total,from,to}` (`DokterController.php:78-93`).
- **Kriteria kelayakan tampil**: view `v_dokter_katalog` mensyaratkan `status_verifikasi='terverifikasi' AND status_aktif=1 AND tersedia_telemedisin=1` (:25-33); STR belum kedaluwarsa `str_berlaku_sampai >= today(Asia/Jakarta)` (:405-408); `users.dihapus_at IS NULL` (:374). Semua ketidaklayakan → satu body 404 `Resource not found.` (`DokterController.php:155`).
- **Agregat rating**: `rating_rata_rata`/`jumlah_ulasan` adalah **kolom tersimpan** di `dokter` (`migration ...dokter_table.php:106-107`), dikembalikan apa adanya; **tidak dihitung dari `ulasan_dokter`** (grep `UlasanDokter` di `app/Http` = 0).
- Pesan sukses: `Daftar dokter berhasil dimuat.` (:126); `Detail dokter berhasil dimuat.` (:160); `Daftar spesialisasi berhasil dimuat.` (:342).

## 3. Field resource

`DokterResource` (list, `DokterResource.php:74-83`) — 8 field: `id, nama_lengkap, tipe, spesialisasi` (string hasil GROUP_CONCAT, **bukan array**; :36-49), `biaya_konsultasi_online, rating_rata_rata, jumlah_konsultasi, status_verifikasi` (konstanta `'terverifikasi'` :82). **Tanpa `jumlah_ulasan`, `foto_profil`, `bio`, `durasi_default_menit`.**

`DokterDetailResource` (`DokterDetailResource.php:78-111`) menambah: `foto_profil, pengalaman_tahun, bio, biaya_luar_jam, durasi_default_menit, jumlah_ulasan, tersedia_telemedisin`, dan array `spesialisasi[]` (:123-134), `pendidikan[]` (:97-105), `faskes[]` (:155-169). Field lisensi/kontak **sengaja tidak dipublikasikan** (:29-39; tes `DokterDirectoryTest.php:871-917`).

## 4. UI direktori (`web/src/pages/doctor-directory-page.tsx`)

- **Kotak cari**: controlled `useState` (:49), **tanpa debounce**; baru dikirim saat form submit (Enter atau tombol `Terapkan`) (:108-114, :168-172).
- **Filter**: `FieldSelect` spesialisasi dari `GET /master-spesialisasi` (:127-147, opsi `Semua spesialisasi` :137); `FieldSelect` tipe (`Semua tipe` :157); tombol toggle `Telemedisin saja` dengan `aria-pressed` (:174-184); `Reset filter` muncul bila ada filter (:186-194).
- **Kontrol sort: TIDAK ADA.**
- **Kartu hasil** (`DoctorCard` :293-355): nama `Link` ke `/dokter/{id}` (:319-324); label tipe (:327); spesialisasi atau `Spesialisasi belum dicatat` (:331-335); badge `formatRupiah` (:338); badge bintang + rating 2 desimal (:340-344); badge `{n} konsultasi` (:346-350). **Tidak menampilkan `status_verifikasi`.**
- **Paginasi**: komponen `Pagination` berbasis halaman (:286), `per_page: 12` (:64) — bukan infinite/load-more.
- **State**: loading `SkeletonRows rows={5}` (:221-222); error `ErrorState` + retry (:223-229); kosong `EmptyState` dua varian (:230-249); halaman melewati batas (:250-263).
- **Copy persis**: judul `Direktori dokter`, deskripsi `Daftar dokter yang memenuhi syarat: terverifikasi, aktif, tersedia untuk telemedisin, dan STR masih berlaku.` (:96-97); label `Cari nama` + placeholder `Nama dokter` (:117, :123); kosong `Tidak ada dokter yang cocok` / `Direktori kosong` (:233-236); error spesialisasi `Daftar spesialisasi gagal dimuat, filter spesialisasi dinonaktifkan.` (:207); 401 override `Direktori dokter seharusnya dapat diakses tanpa masuk. Pesan ini menunjukkan ada masalah pada konfigurasi akses.` (:362-367).
- API client `web/src/lib/api/dokter.ts:67-84` mengirim `page, per_page, [spesialisasi], [tipe], [search], [tersedia_telemedisin]` — **tanpa sort**.

## 5. Router & tes

- `/dokter` dan `/dokter/:id` di luar `RequireAuth`/`AppShell` (`router.tsx:118-127`; komentar :117).
- Pest: `tests/Feature/Dokter/DokterDirectoryTest.php` (~28 tes) menguji kelayakan, STR boundary, 404 uniform, filter `tipe/spesialisasi/search/tersedia_telemedisin`, validasi 422 per-field, urutan default (:656-718), paginasi (:720-798), payload detail + redaksi (:806-917), `master-spesialisasi` (:925-939).
- **Tidak ada tes frontend khusus direktori/detail.** `pdp-f02.spec.ts:286-289` memakai `/dokter` sebagai pintu masuk (mock pesan `Direktori dokter berhasil dimuat.` — berbeda dari pesan API nyata); `app-shell-robustness.spec.ts:61,77` memastikan `/dokter` publik tanpa sidebar.

## 6. Yang TIDAK ada (bukti pencarian)

| Tidak ada | Bukti |
|---|---|
| Sort dipilih pengguna | grep `sort/order_by/orderBy` di `app/Http/Requests/Dokter` = 0; `dokter.ts` = 0; halaman tanpa kontrol sort |
| Filter faskes/geografi | `IndexDokterRequest.php:74-94` tanpa key `faskes`/`kota`; service tanpa filter faskes |
| Filter rating/biaya/tipe layanan | tidak ada key terkait |
| Endpoint ulasan | grep `ulasan/review` di `routes/` = hanya komentar; `openapi.yaml` = 0 path |
| Jumlah ulasan di kartu hasil | `DokterResource.php:74-83` tidak memuat `jumlah_ulasan` |
| `poliklinik` | grep `poliklinik` di `database/` = 0 |
| Query param terdokumentasi di OpenAPI | `openapi.yaml:1242-1264`, `:1352-1379` tanpa `parameters:` filter |

## 7. Konsekuensi untuk F03 (ringkas)

1. **Urutan hasil tidak dapat dikontrol pengguna** — rating/konsultasi/id dipatok server; tidak ada "paling murah", "paling cepat", "pengalaman".
2. **Pencarian hanya nama dokter** — tidak mencari spesialisasi, keluhan, atau fasilitas; pengguna yang mengetik "jantung" mendapat nol hasil walau ada Sp.JP.
3. **Kotak cari tanpa debounce** — aman untuk jaringan lambat (satu permintaan per submit), tetapi perlu tombol yang jelas; sekarang justru submit via Enter/`Terapkan`.
4. **Filter tidak menampilkan jumlah hasil aktif** dan tidak ada chip filter aktif; `Reset filter` hanya muncul bila ada filter.
5. **Nol hasil** punya pemulihan teks (`Longgarkan filter...`) tetapi tidak ada aksi cepat "hapus filter X".
6. **Tidak ada filter fasilitas/geografi** meski `faskes` + lat/long ada di skema.
7. **Kartu hasil tidak menampilkan bukti kepercayaan** (verifikasi/STR) yang justru dipakai kriteria kelayakan.

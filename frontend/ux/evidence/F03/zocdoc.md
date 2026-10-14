# Evidence F03 — Zocdoc (cari dokter & filter) — diblokir, bukti dari dokumentasi resmi

Tanggal akses semua sumber: **2026-10-02**. Homepage **HTTP 403** (bot protection), tidak ada bypass. Fakta dari dokumentasi resmi Zocdoc (help center, halaman about, blog, repo resmi).

## Sumber

| # | URL | Jenis | Catatan |
|---|---|---|---|
| S1 | https://www.zocdoc.com/ | Web | **HTTP 403** |
| S2 | https://www.zocdoc.com/patient-help/en/articles/8724654-how-does-zocdoc-work | Help center resmi | Via indeks |
| S3 | https://www.zocdoc.com/about/how-search-works/ | Halaman resmi (2022-04-14) | Via indeks |
| S4 | https://www.zocdoc.com/blog/facts/guided-search-transparency/ | Blog resmi (31 Mar 2026) | Via indeks |
| S5 | https://github.com/Zocdoc/zocdoc-agent-skill/blob/main/zocdoc-doctor-finder/references/url-patterns.md | Repo resmi Zocdoc | Parameter URL |
| S6 | https://play.google.com/store/apps/details?id=com.zocdoc.android | Play listing | 4,8★; ~50 rb ulasan; 1 jt+ |

## Fakta terlihat (dokumentasi resmi)

- **Pencarian:** berdasarkan gejala/alasan kunjungan, spesialisasi, atau nama dokter + lokasi + asuransi. **"Patient-Powered Search"** menerjemahkan bahasa sehari-hari ("gyno" → "obstetrician-gynecologist"). **Guided Search** mengajukan beberapa pertanyaan lalu mengembalikan daftar yang sadar-asuransi (S2, S3).
- **Filter (S3/S5):** ketersediaan; gender dokter; menerima pasien <18; bahasa; **carrier/plan asuransi**; tipe kunjungan; parameter URL resmi: `day_filter` = AnyDay | Today | Tomorrow | NextWeek; `after_5pm`; `before_10am`; `visitType` = inPersonAndVirtualVisits | virtualVisits | inPersonVisits; `gender`; `language`; `sees_children`; lanjutan `sp_top_rated` ("highly recommended") dan `sp_wait_times` ("Excellent wait time").
- **Sort (S5/S4):** `sort_type` = **Default | Distance**; blog menyebut dapat "sort by availability, distance and reviews".
- **Pagination (S5):** `offset` (0-indexed), **~20 hasil per halaman**.
- **Kartu hasil (S3):** ketersediaan real-time, kualifikasi, foto kantor, **ulasan terverifikasi**; ikon video ungu menandai telehealth.
- **Jumlah hasil / zero-result / drawer mobile:** **TIDAK TERVERIFIKASI** (live diblokir).
- **Sponsor (S4, eksplisit):** *"Providers cannot pay to appear at the top of organic search results. Sponsored placements are clearly labeled 'Sponsored' and must still meet your insurance, location, and availability filters."*

## Hitungan

| Besaran | Nilai |
|---|---|
| Grup filter (dokumentasi) | ≥8 (ketersediaan, gender, anak, bahasa, asuransi, tipe kunjungan, rating, wait time) |
| Opsi sort | 2–3 (Default, Distance, + reviews) |
| Hasil per halaman | ~20 |

## Red flag

Tidak ada dari dokumentasi sendiri. Justru contoh terbaik **komitmen pelabelan sponsor** yang terverifikasi di dokumen resmi; namun rendering label di SERP live **TIDAK TERVERIFIKASI** karena 403.

## Yang TIDAK bisa diverifikasi

Rendering chip filter; visibilitas filter aktif; teks jumlah hasil; pemulihan zero-result; perilaku drawer mobile; rendering label "Sponsored".

## Independensi sumber

S2–S6 = **satu penerbit: Zocdoc**. Tidak memenuhi syarat pemenang; dipakai sebagai sumber pola **filter sadar-konteks (asuransi/lokasi/ketersediaan)**, **komitmen pelabelan sponsor**, dan sort Default/Distance.

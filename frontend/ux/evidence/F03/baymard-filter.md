# Evidence F03 — Baymard (filter, filter aktif, sort, zero-result)

Tanggal akses semua sumber: **2026-10-02**. Jenis: riset usability e-commerce publik. Catatan penting: basis bukti **e-commerce**, bukan kesehatan — diterapkan hati-hati dan ditandai di pattern.

## Sumber

| # | URL | Catatan |
|---|---|---|
| S1 | https://baymard.com/blog/ecommerce-filter-ui | "What Is an Ecommerce Filter? UI Best Practices" — diperbarui 17 Apr 2026 |
| S2 | https://baymard.com/research-articles/how-to-design-applied-filters | "Display 'Applied Filters' in an Overview (28% Don't)" — diperbarui 13 Mei 2026 |
| S3 | https://baymard.com/research-articles/essential-sort-types | "4 Essential Sort Types (64% Don't Allow All 4)" |
| S4 | https://baymard.com/blog/default-sort-type | "Always Sort Product Lists by Diversity-Based 'Relevance' (24% Don't)" |
| S5 | https://baymard.com/research-articles/no-results | "Search UX: 5 Proven Strategies for Improving 'No Results' Pages" — diperbarui 18 Feb 2025 |
| S6 | https://baymard.com/blog/current-state-product-list-and-filtering | "Product List UX 2025: 8 Common Pitfalls (80% Have Serious Issues)" — 9 Sep 2025 |
| S7 | https://baymard.com/research-articles/promoting-product-filters | "Consider Promoting Important Filters (61% Don't)" |
| S8 | https://baymard.com/research-articles/allow-applying-of-multiple-filter-values | "Combining Filter Options (15% of Sites Don't)" |
| S9 | https://baymard.com/mcommerce-usability/benchmark/mobile-page-types/filtering-options | Benchmark "Mobile Filtering Options" |
| S10 | https://baymard.com/research-articles/sort-by-customer-ratings | "Sort with Both Ratings Average and Number of Ratings (64%)" — 30 Apr 2024 |

## Temuan persis

- **S1:** *"It's important to keep applied filters visible during the user's browsing experience, yet 20% of ecommerce sites fail to do so."* Desktop: sidebar kiri persisten. Mobile: *"Use a full-screen or bottom-sheet drawer… Filters should occupy their own layer… triggered by a clearly labeled button."* *"Make the trigger button sticky."* *"Always include a prominent 'Show X Results' button."* *"always surface active filter chips in the main results view."*
- **S2:** *"28% of sites across our UX benchmarks don't display an overview at all."* Mobile: chip horizontal scroll (petunjuk truncation; Amazon menampilkan jumlah "4") atau baris bertumpuk. *"Avoid simply displaying the number of applied filters."*
- **S3:** empat sort esensial: **Price, User Rating, Best-Selling, Newest**; *"Price… is the only essential sort type that should always be bidirectional."* Hindari "Alphabetical" dan nilai "Sort By" itu sendiri; pertahankan label "Sort By" yang jelas.
- **S4:** default sort harus menunjukkan keberagaman; *"Price," "Alphabetical," "Best Selling"* saja bisa menyesatkan; tampilkan tipe utama *"within the first 20 or so products on desktop (and within the first 10 or so on mobile)."*
- **S5:** *"nearly 50% of sites fail to provide users with effective ways to recover from a search that yields no results."* Lima strategi: kategori terkait, pencarian alternatif (pratinjau 3–5 teratas), rekomendasi personal, tautan telepon/chat/help, produk/kategori populer. *"search tips aren't enough."*
- **S6:** 5 tipe filter esensial (**51% tidak punya**); ringkasan filter aktif (**20% tidak punya**); 4 sort esensial (**69% tidak punya** di mobile); gabungkan nilai filter sejenis (**14% tidak)**.
- **S7:** filter penting sebaiknya **dipromosikan** di mobile; *"promoted filters should appear twice: once in their promoted location and once in their 'regular' location."*
- **S8:** *"'AND' logic for filter types and 'OR' logic for filter options"*; *"style filter values as checkboxes."*
- **S9:** *"mobile filters often are contained in a separate interface… the most common mobile issues encompassed: the necessity for multiple trips back-and-forth between product lists and separate filtering interfaces, difficulties with overlong lists of filter options, and poorly designed interfaces that obscured or skewed the user's perception of the filters."*
- **S10:** sort berdasarkan rating harus membobot rata-rata **dan** jumlah ulasan.

## Hitungan benchmark (e-commerce)

20% situs tanpa visibilitas filter aktif; 28% tanpa ringkasan; 51% tanpa 5 tipe filter esensial; 69% mobile tanpa 4 sort esensial; 14% tidak bisa menggabungkan nilai sejenis; 50% pemulihan zero-result buruk.

## Red flag

Tidak ada dark pattern. Peringatan: angka ini untuk e-commerce; jangan dipindahkan sebagai klaim untuk aplikasi kesehatan tanpa uji.

## Yang TIDAK bisa diverifikasi

Tidak ada panduan OTP/kesehatan spesifik dari Baymard; `baymard.com/blog/product-list-sorting` hanya stub JS.

## Independensi sumber

Satu penerbit (Baymard), independen dari aplikasi kandidat. Bersama NN/g → **2 penerbit independen** untuk pola filter aktif, sort, dan zero-result.

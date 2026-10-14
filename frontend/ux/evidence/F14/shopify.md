# F14 — Shopify Admin (izin staf granular, bulk edit, activity log, export)

Titik awal hitungan: **pemilik/admin toko membuka admin → tugas inti selesai** (ubah banyak item sekaligus / atur izin staf / audit aktivitas / export).

## Sumber

| # | Sumber | Jenis | Catatan | Akses |
|---|---|---|---|---|
| S1 | https://help.shopify.com/en/manual/your-account/staff-accounts/staff-permissions/staff-permissions-descriptions | Help center resmi | Izin per seksi dipisah halus: Products = **View / View cost / Create and edit / Edit cost / Edit price / Export / Delete**; Reports = view & create reports (tidak bisa pilih report tertentu); Users/roles = organisasi vs toko; personal-data permissions (Erase/Request data) | 2026-10-02 |
| S2 | https://help.shopify.com/en/manual/shopify-admin/activity-logs | Help center resmi | Store activity log: **view-only**, tidak bisa di-expand, **tidak bisa di-export**, maksimum **250 hasil**, tidak ada rentang hari tetap; butuh izin **Home + Store settings > Manage settings**; ada "page activity" untuk melihat siapa sedang melihat/mengedit halaman yang sama (mencegah konflik); event bulk menyebut "processed by background jobs that log as Shopify" | 2026-10-02 |
| S3 | https://help.shopify.com/en/manual/your-account/users/csv-exports | Help center resmi | Export **user, roles, groups, dan user activity logs** ke CSV (audit keamanan); link CSV dikirim ke email, wajib login, hanya **organization owner/administrator**; kolom log: RESOURCE_TYPE, RESOURCE_NAME, EVENT, EVENT_TYPE, CREATED_AT, ACTOR, EVENT_DETAIL; export kedaluwarsa **7 hari**; import CSV untuk create/suspend/reactivate user massal | 2026-10-02 |
| S4 | https://help.shopify.com/en/manual/shopify-admin/productivity-tools/bulk-editing | Help center resmi | Bulk edit: checkbox item → **Bulk edit** → tabel properti (kolom bisa dipilih/ditambah) → edit nilai → **Save**; error "Update the invalid values, then save again" memblokir seluruh save; error bisa berada di kolom yang tidak tampil; CSV untuk jumlah besar (email konfirmasi) | 2026-10-02 |
| S5 | https://help.shopify.com/en/manual/products/import-export/export-products | Help center resmi | Export produk: pilih **current page / all / selected / matching filters**; format CSV Excel atau plain CSV; file besar dikirim lewat email; error timeout + saran pecah export via filter | 2026-10-02 |
| S6 | https://help.shopify.com/en/manual/your-account/staff-accounts/create-staff-accounts | Help center resmi | Undang staf → email; **undangan kedaluwarsa 7 hari**; role + groups; ringkasan izin ditinjau sebelum assign; hanya owner/administrator tertentu bisa mengelola user | 2026-10-02 |
| S7 | https://help.shopify.com/en/manual/your-account/staff-accounts/staff-permissions/staff-permissions-examples | Help center resmi | Level izin: organization-level vs store-level vs POS app-level; sebagian tugas user-management bukan "permission" yang bisa dicentang | 2026-10-02 |
| S8 | https://changelog.shopify.com/posts/csv-exports-for-users-roles-groups-and-user-activity-logs | Changelog resmi (2025-10-07) | Export bulk user/roles/groups/activity logs tersedia untuk owner & administrator semua paket | 2026-10-02 |

**Verifikasi 2026:** aktif — help center + changelog dapat diakses 2026-10-02. Versi: Shopify admin (SaaS; tidak ada nomor versi).

## Langkah terlihat (fakta)

1. **Bulk edit** [S4]: dari Products/Collections/Inventory/Customers → centang item → **Bulk edit** → tabel menampilkan item + properti terpilih (kolom dapat ditambah lewat **Columns**) → ubah nilai di sel → **Save** → bila ada nilai tidak valid: pesan "Update the invalid values, then save again", **tidak ada yang tersimpan**; untuk volume besar pakai CSV.
2. **Export dengan cakupan eksplisit** [S5]: Products → **Export** → dialog memilih cakupan (**current page / all / selected / matching search & filters**) + format → Export; bila bukan halaman kecil, file dikirim via email.
3. **Atur izin staf** [S1,S6]: buat user → assign role(s)/groups → **ringkasan izin** ditinjau sebelum assign; izin granular per aksi (View/Edit/Export/Delete pada seksi yang sama); undangan **kedaluwarsa 7 hari**.
4. **Audit aktivitas** [S2]: store activity log menampilkan **tanggal/waktu + nama orang/app/channel** pelaku; view-only; **maks 250 hasil**; **tidak bisa export**; akses butuh izin Manage settings; log bisa menampilkan perubahan bulk sebagai "Shopify" (background job).
5. **Export user & activity log untuk audit** [S3]: Settings → Users → Security → User activity logs → **View** → **Export** → dialog konfirmasi → CSV via email (kedaluwarsa 7 hari); kolom memuat ACTOR, EVENT, EVENT_TYPE, CREATED_AT, EVENT_DETAIL.
6. **Mencegah konflik edit** [S2]: **page activity** memberi tahu bila staf lain sedang melihat/mengedit halaman yang sama.
7. **Import massal** [S3]: create/suspend/reactivate user lewat CSV.

## Hitungan

Dari **admin membuka daftar** → **ubah 10 produk sekaligus**:

| Besaran | Nilai | Dasar |
|---|---|---|
| Layar | 3 (daftar → tabel bulk edit → dialog/email konfirmasi) | S4 |
| Ketukan | ±6 (pilih item, Bulk edit, tambah kolom, edit sel, Save) | S4 |
| Field | 1 sel per properti per item (kolom bisa dipilih) | S4 |

**Export dengan filter**: 4 ketukan (filter → Export → pilih cakupan → Export) [S5]; **export activity log**: 5 langkah termasuk konfirmasi dialog + email [S3].

## State terlihat

- **Error bulk memblokir seluruh save** + instruksi perbaikan [S4].
- **Error export timeout** + workaround memecah filter [S5].
- **Batas audit yang jujur**: 250 hasil, tidak bisa export di store log; solusi audit = export terpisah dengan kontrol akses owner/admin + link kedaluwarsa 7 hari [S2,S3].
- **Perubahan otomatis diberi label** ("Shopify" untuk background job) alih-alih pelaku palsu [S2].
- **Loading/kosong/offline**: **TIDAK TERVERIFIKASI**.

## Red flag

- Tidak ditemukan dark pattern. Sebaliknya: **tidak ada bulk destroy tanpa guard** — bulk edit divalidasi menyeluruh sebelum commit [S4], dan akses export dibatasi [S3].
- Catatan: store activity log **tidak** dapat di-export [S2] — keterbatasan, bukan red flag; ditiru dengan "jangan menjanjikan ekspor" pada UI Sehatly.

## Yang TIDAK bisa diverifikasi

- Layout tabel bulk edit, state loading/kosong/error, dan jumlah klik presisi.
- Aksesibilitas: tidak ada dokumen aksesibilitas resmi yang diakses.
- Apakah bulk edit >1 halaman mempertahankan seleksi lintas halaman (tidak dinyatakan).
- Audit trail perubahan izin staf secara rinci (store log view-only; detail ada di user activity log export).

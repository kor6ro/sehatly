# Rencana Arsitektur Multi-Portal (Pilihan A)

## Konsep

Setiap role punya portal (subdomain) sendiri yang terpisah secara URL,
tapi tetap satu codebase Laravel API dan satu codebase React SPA.

```
sehatly.com              → Portal Pasien   (publik)
admin.sehatly.com        → Portal Admin & Superadmin
dokter.sehatly.com       → Portal Dokter
apotek.sehatly.com       → Portal Apoteker
kurir.sehatly.com        → Portal Kurir
marketing.sehatly.com    → Portal Marketing
```

---

## Prinsip Dasar

- **Satu API** — semua portal hit endpoint yang sama di `api.sehatly.com`
  atau `sehatly.com/api/v1`
- **Satu SPA** — satu build React, tapi render konten berbeda berdasarkan
  subdomain yang dibuka
- **Satu token** — Sanctum bearer token tetap sama, tidak perlu auth ulang
  per subdomain
- **Login terpisah per portal** — setiap subdomain punya halaman login
  sendiri, tidak ada dialog publik untuk admin/dokter/dll

---

## Arsitektur

```
Browser
  │
  ├── sehatly.com          → React SPA (portal pasien)
  ├── admin.sehatly.com    → React SPA (portal admin)
  ├── dokter.sehatly.com   → React SPA (portal dokter)
  ├── apotek.sehatly.com   → React SPA (portal apoteker)
  ├── kurir.sehatly.com    → React SPA (portal kurir)
  └── marketing.sehatly.com → React SPA (portal marketing)
         │
         └── semua hit → sehatly.com/api/v1  (Laravel API)
```

---

## Bagian 1 — Perubahan Backend (Laravel)

### 1.1 CORS — tambah semua subdomain

File: `config/cors.php`

```php
'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
    // Development
    'http://localhost:5173',
    'http://localhost:5174',
    'http://127.0.0.1:5173',
    'http://127.0.0.1:5174',
    // Development subdomain (pakai /etc/hosts atau Laragon)
    'http://admin.sehatly.test',
    'http://dokter.sehatly.test',
    'http://apotek.sehatly.test',
    'http://kurir.sehatly.test',
    'http://marketing.sehatly.test',
    // Production
    'https://sehatly.com',
    'https://admin.sehatly.com',
    'https://dokter.sehatly.com',
    'https://apotek.sehatly.com',
    'https://kurir.sehatly.com',
    'https://marketing.sehatly.com',
]))),
```

### 1.2 Sanctum — tambah stateful domains

File: `config/sanctum.php`

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS',
    'localhost,localhost:3000,127.0.0.1,' .
    'sehatly.test,admin.sehatly.test,dokter.sehatly.test,' .
    'apotek.sehatly.test,kurir.sehatly.test,marketing.sehatly.test,' .
    'sehatly.com,admin.sehatly.com,dokter.sehatly.com,' .
    'apotek.sehatly.com,kurir.sehatly.com,marketing.sehatly.com'
)),
```

### 1.3 Tambah role baru ke RbacCatalog

File: `app/Support/Rbac/RbacCatalog.php`

> **Perhatian — constraint `RbacCatalogTest`:**
> `RbacCatalogTest` mengecek dua hal secara ketat:
> 1. `USER_TYPES` harus byte-identical dengan nilai ENUM di `telemedicine_test.sql:139`.
> 2. Setiap entry di `ROLES` harus merupakan subset dari `USER_TYPES`.
>
> Karena `telemedicine_test.sql` tidak boleh diedit, urutan wajib:
> **migrasi dulu** (Step 1) → **update `USER_TYPES`** (Step 2) → **update `ROLES`** (Step 3).
> Setelah migrasi dijalankan, `telemedicine_test.sql` tetap tidak berubah, tapi
> `RbacCatalogTest` membaca DDL dari file itu — artinya test ini **akan tetap gagal**
> sampai `telemedicine_test.sql` diupdate secara manual untuk mencerminkan ENUM baru.
> Ini adalah trade-off yang disadari: file DDL adalah referensi read-only, tapi
> penambahan `marketing` ke ENUM adalah perubahan schema yang sah lewat migrasi.
> **Solusi**: update `telemedicine_test.sql` baris 139 secara manual setelah migrasi,
> karena ini bukan "edit" yang mengubah logika — ini sinkronisasi referensi DDL.

**`kurir` sudah ada di `USER_TYPES` (dari DDL), tapi belum punya role.**
Ini disengaja di codebase awal (lihat docblock `RbacCatalog.php`). Sekarang kita
tambahkan rolenya.

**Tambah `marketing` ke `USER_TYPES`** (setelah migrasi ENUM dijalankan):
```php
public const USER_TYPES = [
    'pasien',
    'dokter',
    'perawat',
    'apoteker',
    'kurir',
    'admin',
    'superadmin',
    'marketing', // tambah — harus sinkron dengan migrasi ENUM
];
```

**Tambah `kurir` dan `marketing` ke `ROLES`:**
```php
public const ROLES = [
    'pasien',
    'dokter',
    'apoteker',
    'kurir',      // tambah — sudah ada di USER_TYPES sejak DDL awal
    'marketing',  // tambah — baru setelah migrasi ENUM
    'admin',
    'superadmin',
];
```

**Tambah deskripsi ke `ROLE_DESCRIPTIONS`:**
```php
'kurir'     => 'Akun kurir: mengambil dan mengantarkan pesanan obat.',
'marketing' => 'Akun marketing: mengelola konten artikel dan promosi.',
```

**Tambah permission baru ke `PERMISSIONS`** (append di akhir, jangan sisipkan di tengah
agar seeded `permissions.id` yang sudah ada tidak bergeser):
```php
// Kurir — append setelah 'hero.kelola'
'pesanan.antar'  => 'Antar Pesanan',
'pesanan.update' => 'Update Pesanan',

// Marketing
'artikel.kelola'    => 'Kelola Artikel',
'promo.kelola'      => 'Kelola Promo',
'laporan.marketing' => 'Laporan Marketing',
```

> **Catatan nama**: `displayNameFor()` menurunkan nama dari kode secara mekanis.
> Pastikan setiap nama di atas sama persis dengan hasil `displayNameFor()` dari kodenya,
> karena `RbacCatalogTest` mengecek ini. Contoh: `'pesanan.antar'` → `'Antar Pesanan'` ✅.

**Tambah grant ke `ROLE_PERMISSIONS`:**
```php
'kurir' => [
    'pesanan.lihat',
    'pesanan.antar',
    'pesanan.update',
    'notifikasi.lihat',
],

'marketing' => [
    'artikel.kelola',
    'promo.kelola',
    'laporan.marketing',
    'notifikasi.lihat',
    'dokter.lihat',
],
```

**Tambah semua permission baru ke `superadmin`** (append di akhir list, setelah
`'hero.kelola'`, agar urutan tetap sama dengan `permissionCodes()`):
```php
// append ke superadmin:
'pesanan.antar',
'pesanan.update',
'artikel.kelola',
'promo.kelola',
'laporan.marketing',
```

### 1.4 Migrasi — tambah `marketing` ke ENUM users.tipe

Migrasi terakhir saat ini adalah `000088` (`create_hero_slides_table`), jadi nomor
berikutnya adalah `000089`.

Buat file: `database/migrations/2026_10_01_000089_add_marketing_to_users_tipe.php`

```php
public function up(): void
{
    DB::statement("ALTER TABLE users MODIFY tipe ENUM(
        'pasien','dokter','perawat','apoteker',
        'kurir','admin','superadmin','marketing'
    ) NOT NULL DEFAULT 'pasien'");
}

public function down(): void
{
    DB::statement("ALTER TABLE users MODIFY tipe ENUM(
        'pasien','dokter','perawat','apoteker',
        'kurir','admin','superadmin'
    ) NOT NULL DEFAULT 'pasien'");
}
```

### 1.5 Update `telemedicine_test.sql` baris 139

Setelah migrasi dijalankan, update baris 139 di `telemedicine_test.sql` secara manual
untuk mencerminkan ENUM baru:

```sql
-- Sebelum:
tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin') NOT NULL DEFAULT 'pasien'

-- Sesudah:
tipe ENUM('pasien','dokter','perawat','apoteker','kurir','admin','superadmin','marketing') NOT NULL DEFAULT 'pasien'
```

Ini adalah satu-satunya edit yang diizinkan ke file ini: sinkronisasi referensi DDL
setelah migrasi yang sah. Jangan ubah urutan baris lain, jangan reformat.

### 1.6 Jalankan setelah perubahan backend

```console
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan migrate
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan db:seed --class=RbacSeeder
C:\laragon\bin\php\php-8.4.17-nts-Win32-vs17-x64\php.exe artisan test --filter=RbacCatalogTest
```

---

## Bagian 2 — Perubahan Frontend (React SPA)

### 2.1 Deteksi subdomain

Buat file: `frontend/src/lib/portal.ts`

```ts
export type Portal =
    | 'pasien'
    | 'admin'
    | 'dokter'
    | 'apotek'
    | 'kurir'
    | 'marketing';

/**
 * Deteksi portal dari hostname saat ini.
 * Development: admin.sehatly.test → 'admin'
 * Production:  admin.sehatly.com  → 'admin'
 * Fallback:    localhost / sehatly.com → 'pasien'
 */
export function detectPortal(): Portal {
    const host = window.location.hostname;

    if (host.startsWith('admin.'))     return 'admin';
    if (host.startsWith('dokter.'))    return 'dokter';
    if (host.startsWith('apotek.'))    return 'apotek';
    if (host.startsWith('kurir.'))     return 'kurir';
    if (host.startsWith('marketing.')) return 'marketing';

    return 'pasien';
}

/** Tipe akun yang boleh masuk ke portal ini. */
export const PORTAL_TIPE: Record<Portal, string[]> = {
    pasien:    ['pasien'],
    admin:     ['admin', 'superadmin'],
    dokter:    ['dokter'],
    apotek:    ['apoteker'],
    kurir:     ['kurir'],
    marketing: ['marketing'],
};

/** Halaman utama setelah login per portal. */
export const PORTAL_HOME: Record<Portal, string> = {
    pasien:    '/',
    admin:     '/admin/dokter',
    dokter:    '/dokter/dashboard',
    apotek:    '/apotek/resep',
    kurir:     '/kurir/pesanan',
    marketing: '/marketing/dashboard',
};
```

### 2.2 Router — render berdasarkan portal

File: `frontend/src/app/router.tsx`

Ubah `createBrowserRouter` menjadi conditional berdasarkan portal:

```ts
import { detectPortal } from '@/lib/portal';

const portal = detectPortal();

export const router = createBrowserRouter(
    portal === 'admin'     ? adminRoutes :
    portal === 'dokter'    ? dokterRoutes :
    portal === 'apotek'    ? apotekRoutes :
    portal === 'kurir'     ? kurirRoutes :
    portal === 'marketing' ? marketingRoutes :
    pasienRoutes
);
```

### 2.3 Route per portal

Folder `frontend/src/app/routes/` belum ada — buat dulu foldernya, lalu buat file-filenya.

Buat file: `frontend/src/app/routes/`

```
frontend/src/app/routes/
├── pasien-routes.tsx      → route yang sudah ada sekarang
├── admin-routes.tsx       → /login, /dokter, /laporan, /audit-log, dll
├── dokter-routes.tsx      → /login, /dashboard, /booking, /konsultasi, dll
├── apotek-routes.tsx      → /login, /resep, /pesanan, dll
├── kurir-routes.tsx       → /login, /pesanan, dll
└── marketing-routes.tsx   → /login, /dashboard, /artikel, /promo, dll
```

**Contoh `admin-routes.tsx`:**
```tsx
export const adminRoutes = [
    {
        element: <RootLayout />,
        errorElement: <RouteErrorBoundary />,
        children: [
            {
                path: '/login',
                element: <AdminLoginPage />,
                errorElement: <RouteErrorBoundary />,
            },
            {
                element: <RequirePortalAuth portal="admin" />,
                children: [
                    {
                        element: <AppShell />,
                        children: [
                            { path: '/admin/dokter', element: <AdminDokterPage /> },
                            { path: '/admin/laporan', element: <AdminLaporanPage /> },
                            { path: '/admin/audit-log', element: <AdminAuditLogPage /> },
                            { path: '/admin/hero', element: <AdminHeroPage /> },
                            // dst...
                        ],
                    },
                ],
            },
            { path: '*', element: <NotFoundPage /> },
        ],
    },
];
```

### 2.4 Guard per portal

Buat file: `frontend/src/app/guards.tsx` — tambah `RequirePortalAuth`:

```tsx
export function RequirePortalAuth({ portal }: { portal: Portal }) {
    const location = useLocation();
    const me = useQuery(meOptions());

    if (getAccessToken() === null) {
        return <Navigate to="/login" replace state={{ from: location.pathname }} />;
    }

    if (me.isPending) return <SkeletonRows rows={4} />;

    if (me.isError) return <ErrorState error={me.error} />;

    const tipe = me.data?.data.user?.tipe;
    const boleh = PORTAL_TIPE[portal];

    if (!boleh.includes(tipe ?? '')) {
        return (
            <ForbiddenState
                title="Akses ditolak"
                detail={`Portal ini hanya untuk: ${boleh.join(', ')}.`}
            />
        );
    }

    return <Outlet />;
}
```

### 2.5 Halaman login per portal

Setiap portal punya halaman `/login` sendiri. Bedanya dengan login pasien:

| Portal | Field | OTP |
|---|---|---|
| Pasien | No HP saja | Ya (wajib) |
| Admin | No HP + Password | Ya (2FA) |
| Dokter | No HP + Password | Ya (2FA) |
| Apotek | No HP + Password | Ya (2FA) |
| Kurir | No HP + Password | Opsional |
| Marketing | No HP + Password | Ya (2FA) |

Buat file per portal:
- `frontend/src/pages/admin-login-page.tsx`
- `frontend/src/pages/dokter-login-page.tsx`
- `frontend/src/pages/apotek-login-page.tsx`
- `frontend/src/pages/kurir-login-page.tsx`
- `frontend/src/pages/marketing-login-page.tsx`

Setelah login sukses, redirect ke `PORTAL_HOME[portal]`.

### 2.6 Vite config — proxy per subdomain (development)

File: `frontend/vite.config.ts`

```ts
server: {
    proxy: {
        '/api': {
            target: apiTarget,
            changeOrigin: true,
        },
    },
},
```

Untuk development subdomain, tambah di `/etc/hosts` (Windows:
`C:\Windows\System32\drivers\etc\hosts`):

```
127.0.0.1  sehatly.test
127.0.0.1  admin.sehatly.test
127.0.0.1  dokter.sehatly.test
127.0.0.1  apotek.sehatly.test
127.0.0.1  kurir.sehatly.test
127.0.0.1  marketing.sehatly.test
```

Laragon sudah otomatis handle `*.test` domain, jadi ini mungkin tidak perlu
diubah manual.

---

## Bagian 3 — Setup Development (Laragon)

Karena project ini pakai Laragon, subdomain bisa disetup dengan cara:

### 3.1 Laragon virtual host

Laragon otomatis buat virtual host untuk folder di `C:\laragon\www\`.
Untuk subdomain, tambah manual di Apache config Laragon:

File: `C:\laragon\etc\apache2\sites-enabled\sehatly.test.conf`

```apache
<VirtualHost *:80>
    ServerName sehatly.test
    ServerAlias *.sehatly.test
    DocumentRoot "C:/laragon/www/sehatly_web/public"
    <Directory "C:/laragon/www/sehatly_web/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`ServerAlias *.sehatly.test` membuat semua subdomain mengarah ke folder
yang sama, dan React SPA yang mendeteksi subdomain lewat `detectPortal()`.

### 3.2 Vite dev server — jalankan di port berbeda per portal (opsional)

Alternatif lebih simpel untuk development: tetap pakai `localhost:5173` tapi
tambah query param `?portal=admin` untuk testing:

```ts
export function detectPortal(): Portal {
    // Development override via query param
    const param = new URLSearchParams(window.location.search).get('portal');
    if (param && isValidPortal(param)) return param as Portal;

    // Production: deteksi dari hostname
    const host = window.location.hostname;
    if (host.startsWith('admin.'))     return 'admin';
    // dst...
    return 'pasien';
}
```

Jadi untuk testing: `http://localhost:5173/?portal=admin`

---

## Bagian 4 — Halaman yang Perlu Dibuat

### Portal Admin (`admin.sehatly.com`)
| Halaman | Route | Status |
|---|---|---|
| Login admin | `/login` | Baru |
| Daftar dokter | `/admin/dokter` | Sudah ada ✅ |
| Detail dokter | `/admin/dokter/:id` | Sudah ada ✅ |
| Laporan | `/admin/laporan` | Sudah ada ✅ |
| Audit log | `/admin/audit-log` | Sudah ada ✅ |
| Persetujuan PDP | `/admin/persetujuan-pdp` | Sudah ada ✅ |
| Hero carousel | `/admin/hero` | Sudah ada ✅ |
| **Buat akun dokter** | `/admin/dokter/buat` | **Baru** |

### Portal Dokter (`dokter.sehatly.com`)
| Halaman | Route | Status |
|---|---|---|
| Login dokter | `/login` | Baru |
| Dashboard | `/dokter/dashboard` | Sudah ada ✅ |
| Booking masuk | `/dokter/booking` | Sudah ada ✅ |
| Konsultasi | `/konsultasi/:id` | Sudah ada ✅ |
| Tulis resep | `/konsultasi/:id/resep` | Sudah ada ✅ |

### Portal Apoteker (`apotek.sehatly.com`)
| Halaman | Route | Status |
|---|---|---|
| Login apoteker | `/login` | Baru |
| Antrean resep | `/apotek/resep` | Sudah ada ✅ |
| Dashboard apotek | `/apotek/dashboard` | Baru |

> `/apotek/dashboard` tidak ada di urutan pengerjaan di bawah — tambahkan di Step 12
> atau buat bersamaan dengan halaman portal apoteker lainnya.

### Portal Kurir (`kurir.sehatly.com`)
| Halaman | Route | Status |
|---|---|---|
| Login kurir | `/login` | Baru |
| Daftar pesanan | `/kurir/pesanan` | Baru |
| Detail pesanan | `/kurir/pesanan/:id` | Baru |
| Dashboard kurir | `/kurir/dashboard` | Baru |

### Portal Marketing (`marketing.sehatly.com`)
| Halaman | Route | Status |
|---|---|---|
| Login marketing | `/login` | Baru |
| Dashboard | `/marketing/dashboard` | Baru |
| Kelola artikel | `/marketing/artikel` | Baru |
| Kelola promo | `/marketing/promo` | Baru |

---

## Urutan Pengerjaan

```
Step 1  — Backend: buat migrasi 000089 (tambah 'marketing' ke users.tipe ENUM)
Step 2  — Backend: jalankan migrasi: php artisan migrate
Step 3  — Backend: update telemedicine_test.sql baris 139 (sinkronisasi referensi DDL)
Step 4  — Backend: update RbacCatalog.php
            - tambah 'marketing' ke USER_TYPES
            - tambah 'kurir' dan 'marketing' ke ROLES
            - tambah deskripsi ke ROLE_DESCRIPTIONS
            - append permission baru ke PERMISSIONS
            - tambah ROLE_PERMISSIONS untuk kurir dan marketing
            - append permission baru ke superadmin
Step 5  — Backend: update config/cors.php (tambah semua subdomain)
Step 6  — Backend: update config/sanctum.php (tambah stateful domains)
Step 7  — Backend: php artisan db:seed --class=RbacSeeder
Step 8  — Backend: php artisan test --filter=RbacCatalogTest (pastikan hijau)

Step 9  — Frontend: buat lib/portal.ts (detectPortal, PORTAL_TIPE, PORTAL_HOME)
Step 10 — Frontend: buat folder app/routes/ lalu buat semua file route per portal
            - pasien-routes.tsx (pindahkan route yang sudah ada di router.tsx)
            - admin-routes.tsx
            - dokter-routes.tsx
            - apotek-routes.tsx
            - kurir-routes.tsx
            - marketing-routes.tsx
Step 11 — Frontend: update app/router.tsx (conditional per portal)
Step 12 — Frontend: tambah RequirePortalAuth di guards.tsx
Step 13 — Frontend: buat halaman login per portal
            - admin-login-page.tsx
            - dokter-login-page.tsx
            - apotek-login-page.tsx
            - kurir-login-page.tsx
            - marketing-login-page.tsx
Step 14 — Frontend: buat halaman portal apoteker (apotek-dashboard-page.tsx)
Step 15 — Frontend: buat halaman portal kurir
            - kurir-dashboard-page.tsx
            - kurir-pesanan-page.tsx
            - kurir-pesanan-detail-page.tsx
Step 16 — Frontend: buat halaman portal marketing
            - marketing-dashboard-page.tsx
            - marketing-artikel-page.tsx
            - marketing-promo-page.tsx

Step 17 — Server: setup Laragon virtual host untuk *.sehatly.test
Step 18 — Testing: test semua portal di development
```

---

## Risiko & Mitigasi

| Risiko | Mitigasi |
|---|---|
| Token tidak bisa dishare antar subdomain | Pakai `sessionStorage` (sudah dipakai), bukan cookie — token tidak terikat domain |
| CORS reject request dari subdomain baru | Pastikan semua subdomain masuk `CORS_ALLOWED_ORIGINS` di `.env` production |
| `RbacCatalogTest` gagal karena `USER_TYPES` tidak sinkron dengan DDL | Update `telemedicine_test.sql` baris 139 setelah migrasi (Step 3) sebelum update `RbacCatalog.php` |
| `RbacCatalogTest` gagal karena nama permission tidak sesuai `displayNameFor()` | Verifikasi setiap nama di `PERMISSIONS` dengan menjalankan `displayNameFor()` secara manual sebelum commit |
| `superadmin` di `ROLE_PERMISSIONS` tidak lengkap | Append semua permission baru ke list `superadmin` — `RbacCatalogTest` mengecek bahwa superadmin memegang seluruh katalog |
| `telemedicine_test.sql` tidak sinkron | Update baris 139 secara manual setelah migrasi — ini satu-satunya edit yang diizinkan |
| Laragon tidak handle subdomain | Tambah `ServerAlias *.sehatly.test` di Apache config Laragon |
| Build SPA satu file untuk semua portal | Semua portal pakai build yang sama, `detectPortal()` yang memisahkan — tidak perlu build terpisah |
| `app/routes/` folder belum ada | Buat folder dulu sebelum membuat file route (Step 10) |

---

## Catatan Penting

- **`telemedicine_test.sql` tidak boleh diedit** — semua perubahan schema
  lewat migrasi Laravel
- **Token Sanctum** sudah pakai `sessionStorage` bukan cookie, jadi tidak
  ada masalah cross-subdomain untuk token
- **Satu build SPA** untuk semua portal — lebih mudah deploy, `detectPortal()`
  yang handle perbedaan per subdomain
- **Development shortcut** — pakai `?portal=admin` di localhost untuk testing
  tanpa perlu setup subdomain

---

## Bagian 5 — Yang Belum Ada dan Perlu Disetup Nanti

Bagian ini mencatat hal-hal yang **sengaja tidak dikerjakan sekarang** karena
membutuhkan keputusan eksternal, kredensial pihak ketiga, atau scope tersendiri.
Dicatat di sini agar tidak hilang.

### 5.1 Session Expired — Redirect ke Portal yang Benar

**Masalah:** `endSession()` di `frontend/src/lib/http.ts` memanggil semua
`sessionExpiredListeners`. Listener ini (di `app.tsx`) saat ini navigate ke
`/?masuk=1` — halaman landing pasien. Untuk portal admin/dokter/dll, expired
session harus redirect ke `/login` di subdomain masing-masing, bukan ke
`/?masuk=1`.

**Yang perlu dilakukan:**
- Update listener di `app.tsx` agar tahu portal aktif saat ini
- Redirect ke `/login` (bukan `/?masuk=1`) untuk portal non-pasien
- `RequireAuth` di `guards.tsx` juga redirect ke `/?masuk=1` — perlu diganti
  dengan `RequirePortalAuth` yang redirect ke `/login`

**Prioritas:** Harus dikerjakan bersamaan dengan Step 12 (tambah
`RequirePortalAuth`), bukan setelah.

### 5.2 Endpoint API untuk Kurir — Belum Ada

**Masalah:** Halaman portal kurir (`/kurir/pesanan`, `/kurir/pesanan/:id`,
`/kurir/dashboard`) butuh data dari backend. Dari `routes/api.php`, endpoint
yang ada untuk pesanan obat:

| Endpoint | Guard | Bisa dipakai kurir? |
|---|---|---|
| `GET /pesanan-obat/{id}` | `permission:pesanan.lihat` | Tidak — kurir tidak punya `pesanan.lihat` |
| `POST /resep/{id}/checkout` | `permission:pesanan.buat` | Tidak |
| `GET /obat/{id}/stok` | `auth:sanctum` | Ya, tapi bukan data pesanan |

Kurir butuh endpoint baru:
- `GET /kurir/pesanan` — daftar pesanan yang perlu diantar
- `GET /kurir/pesanan/{id}` — detail pesanan
- `PUT /kurir/pesanan/{id}/ambil` — kurir mengambil pesanan
- `PUT /kurir/pesanan/{id}/antar` — kurir mengkonfirmasi pengiriman

**Yang perlu dilakukan (backend):**
- Buat `KurirController`
- Tambah routes di `routes/api.php` dengan guard `tipe:kurir` +
  `permission:pesanan.antar`
- Buat `FormRequest` untuk setiap write endpoint (wajib per DoD)
- Buat `KurirPesananResource` untuk response shape

**Prioritas:** Backend harus selesai sebelum Step 15 (buat halaman portal kurir).

### 5.3 Endpoint API untuk Marketing — Belum Ada

**Masalah:** Tabel `artikel` dan `artikel_kategori` sudah ada di DDL
(`telemedicine_test.sql`), tapi tidak ada satu pun route di `routes/api.php`
yang menyentuhnya. Sama untuk promo — `master_promo` ada, tapi endpoint
kelola promo untuk marketing belum ada.

**Yang perlu dilakukan (backend):**
- Buat `MarketingArtikelController` — CRUD artikel
- Buat `MarketingPromoController` — CRUD promo
- Tambah routes dengan guard `tipe:marketing` + permission yang sesuai
- Buat `FormRequest` untuk setiap write
- Buat Resource classes untuk response

**Catatan:** `promo.validasi` sudah ada tapi itu untuk pasien memvalidasi kode
promo saat checkout — bukan untuk marketing mengelola promo. Perlu permission
baru `promo.kelola` (sudah direncanakan di Step 4).

**Prioritas:** Backend harus selesai sebelum Step 16 (buat halaman portal
marketing).

### 5.4 Payment Gateway — Konfigurasi Kredensial

**Masalah:** `routes/api.php` sudah punya `POST /webhook/payment/{gateway}`
dengan constraint ke 4 gateway: `midtrans`, `xendit`, `doku`, `flip`.
Verifikasi HMAC-SHA256 sudah ada di `PaymentGatewayService`. Tapi kredensial
belum dikonfigurasi.

**Yang perlu dilakukan:**
- Daftar akun merchant di gateway yang dipilih (Midtrans direkomendasikan untuk
  Indonesia karena support QRIS, VA, dan kartu kredit)
- Isi di `.env`:
  ```
  MIDTRANS_SERVER_KEY=<server_key>
  MIDTRANS_CLIENT_KEY=<client_key>
  MIDTRANS_IS_PRODUCTION=false
  ```
- Setup webhook URL di dashboard Midtrans:
  `https://sehatly.com/api/v1/webhook/payment/midtrans`
- Test dengan Midtrans Sandbox sebelum production

**Yang TIDAK perlu diubah di kode:** Route, HMAC verification, dan
`PembayaranGateway` enum sudah siap. Ini murni konfigurasi kredensial.

**Prioritas:** Bisa dikerjakan kapan saja secara independen dari multi-portal.
Development bisa jalan tanpa ini (payment akan gagal tapi flow lain tidak
terganggu).

### 5.5 Firebase Push Notification untuk Mobile

**Masalah:** Notifikasi saat ini dikirim lewat Reverb (WebSocket) untuk SPA.
Untuk mobile client (Android/iOS), perlu Firebase Cloud Messaging (FCM) agar
notifikasi bisa diterima saat app di-background atau ditutup.

**Yang perlu dilakukan (backend):**
- Daftar project di [Firebase Console](https://console.firebase.google.com)
- Install package: `composer require kreait/laravel-firebase`
- Tambah ke `.env`:
  ```
  FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json
  ```
- Tambah kolom `fcm_token` ke `user_devices` lewat migrasi baru
- Update `POST /auth/devices` untuk menerima dan menyimpan `fcm_token`
- Buat `FcmNotificationService` yang dipanggil bersamaan dengan
  `NotificationService` yang sudah ada
- Kirim FCM hanya jika `fcm_token` ada di device row

**Yang perlu dilakukan (mobile client):**
- Inisialisasi Firebase SDK di app Flutter/React Native
- Request permission notifikasi dari user
- Kirim `fcm_token` ke `POST /auth/devices` setelah login
- Handle notifikasi di foreground dan background

**Yang TIDAK perlu diubah:** `NotificationService` yang sudah ada tetap
berjalan untuk SPA via Reverb. FCM adalah lapisan tambahan, bukan pengganti.

**Prioritas:** Dikerjakan setelah mobile client mulai development. Tidak
memblokir multi-portal SPA sama sekali.

### 5.6 Error Handling Multi-Portal — Perbaikan Setelah Setup

Beberapa error handling yang perlu diperbaiki setelah multi-portal berjalan:

**`ForbiddenState` untuk portal yang salah:**
Saat ini `RequirePortalAuth` akan render `ForbiddenState` jika user login
dengan tipe yang salah (misal dokter buka `admin.sehatly.com`). Perlu
ditambahkan tombol "Pergi ke portal yang benar" yang redirect ke
`PORTAL_HOME[tipe_user]`.

**Token expired di portal non-pasien:**
Seperti dijelaskan di 5.1 — redirect harus ke `/login` bukan `/?masuk=1`.

**`profil_lengkap: false` untuk akun non-pasien:**
`verifyOtp` di `auth.ts` mengembalikan `profil_lengkap`. Untuk pasien yang
baru daftar, `false` berarti redirect ke `/profil/edit/:id?sign_up=true`.
Untuk dokter/admin yang dibuat oleh admin (bukan self-register),
`profil_lengkap` kemungkinan selalu `true` — tapi perlu dikonfirmasi.
Jika ada kasus `false` untuk non-pasien, halaman login per portal harus
handle redirect yang berbeda.

**Network error vs API error:**
`ApiError` dengan `status === 0` adalah network error (tidak bisa reach
server). Semua portal pakai transport yang sama (`http.ts`), jadi ini sudah
terhandle. Tidak perlu perubahan.

**Prioritas:** Dikerjakan setelah semua portal bisa login dan basic flow
berjalan. Bukan blocker untuk setup awal.

# DESIGN.md — WS-7 Halaman Portal FE: Marketing

**Proyek:** Sehatly (Laravel API + React SPA / Vite)
**Workstream:** WS-7 — Halaman portal FE — Marketing
**Tanggal:** 2026-10-18
**Status:** DESIGN (Phase 1) — siap untuk implementasi Phase 2 oleh `@frontend-nuxt` / `@frontend-react`
**Branch:** `feat/multi-portal-v2`

---

## 1. Ringkasan singkat

WS-7 membangun 3 halaman portal marketing yang masih stub (`EmptyState`) menjadi layar nyata yang membaca data dari API:

1. `MarketingDashboardPage` — 2 kartu (artikel, promo) dengan data nyata, status loading/empty/error
2. `MarketingArtikelPage` — daftar artikel (tabel), buat baru, detail, edit, arsip
3. `MarketingPromoPage` — daftar promo (tabel), buat baru, detail, edit, nonaktifkan

Plus API client baru: `frontend/src/lib/api/marketing.ts` mengikuti pola `lib/api/kurir.ts` (TanStack React Query + `ky` + `queryClient`).

Semua desain berpegang pada token di `frontend/src/styles/app.css` (`@theme`, `:root`, `.dark`). Dilarang hardcode hex/rgb/oklch di komponen.

---

## 2. Design tokens yang dipakai (dari app.css)

Semua token berikut diambil langsung dari `frontend/src/styles/app.css`. Komponen **tidak boleh** menambahkan token baru atau hardcode warna.

| Token | Light (`:root`) | Dark (`.dark`) | Digunakan untuk |
|---|---|---|---|
| `--primary` | oklch(0.48 0.133 249.8) | oklch(0.709 0.121 245.7) | Aksen tunggal, tombol primer, link, ring |
| `--primary-foreground` | oklch(0.985 0 0) | oklch(0.195 0.026 247) | Teks di atas primary |
| `--success` | oklch(0.52 0.11 160) | oklch(0.696 0.17 162.48) | Status sukses / terbit |
| `--success-subtle` | oklch(0.96 0.03 160) | oklch(0.27 0.05 160) | Latar badge sukses |
| `--success-subtle-foreground` | oklch(0.4 0.09 160) | oklch(0.85 0.1 160) | Teks badge sukses |
| `--warning` | oklch(0.681 0.162 76.834) | oklch(0.828 0.189 84.429) | Peringatan |
| `--warning-subtle` | oklch(0.965 0.04 85) | oklch(0.3 0.05 80) | Latar badge warning |
| `--warning-subtle-foreground` | oklch(0.42 0.09 70) | oklch(0.88 0.11 85) | Teks badge warning |
| `--destructive` | oklch(0.577 0.245 27.325) | oklch(0.704 0.191 22.216) | Hapus / arsip / nonaktifkan |
| `--destructive-subtle` | oklch(0.965 0.02 25) | oklch(0.29 0.07 27) | Latar badge destructive |
| `--destructive-subtle-foreground` | oklch(0.45 0.17 27) | oklch(0.86 0.08 25) | Teks badge destructive |
| `--background` | oklch(0.985 0.004 247) | oklch(0.195 0.026 247) | Latar halaman |
| `--foreground` | oklch(0.247 0.029 249) | oklch(0.97 0.005 247) | Teks utama |
| `--card` | oklch(1 0 0) | oklch(0.246 0.036 248) | Kartu, tabel baris |
| `--card-foreground` | oklch(0.247 0.029 249) | oklch(0.97 0.005 247) | Teks di atas kartu |
| `--muted` | oklch(0.965 0.008 247) | oklch(0.29 0.035 249) | Latar sebentar, skeleton |
| `--muted-foreground` | oklch(0.5 0.032 248.4) | oklch(0.72 0.02 248) | Teks sekunder, deskripsi |
| `--border` | oklch(0.922 0.012 247) | oklch(0.32 0.03 249) | Garis pemisah, tabel border |
| `--input` | oklch(0.62 0.025 248) | oklch(0.55 0.03 248) | Batas input |
| `--ring` | oklch(0.48 0.133 249.8) | oklch(0.709 0.121 245.7) | Fokus keyboard |
| `--radius` | 0.625rem | 0.625rem | Radius dasar kartu |
| `--radius-md` | calc(var(--radius) - 2px) | sama | Tombol, input |
| `--radius-sm` | calc(var(--radius) - 4px) | sama | Badge, elemen kecil |
| `--font-sans` | 'Instrument Sans', ... | sama | Semua teks UI |

**Larangan keras (dari AGENTS.md):**
- Tidak ada gradient, glassmorphism, glow, `backdrop-filter`, `backdrop-blur-*`.
- Tidak ada kelas palet Tailwind langsung (`bg-blue-500`, `text-sky-600`, dst.). Pakai token (`bg-primary`, `text-muted-foreground`, `border-border`).
- Tidak ada biru logo `#4297DB` sebagai warna UI.
- Tidak ada card di dalam card. Tidak ada border + shadow bersamaan.
- Tidak ada `rounded-2xl` di semua tempat. Kartu maksimal `--radius` (12–16px setara).
- Animasi hanya untuk umpan balik (hover, fokus, buka/tutup), maksimal 150–200 ms.
- `prefers-reduced-motion` harus dihormati.

---

## 3. Komponen map (shadcn yang sudah ada — tidak perlu buat baru)

Komponen berikut sudah tersedia di `frontend/src/components/ui/` dan harus dipakai:

| Komponen | Path | Diguna untuk |
|---|---|---|
| `Button` | `components/ui/button.tsx` | Aksi utama, sekunder, destructive, outline |
| `Card`, `CardHeader`, `CardTitle`, `CardContent` | `components/ui/card.tsx` | Kontainer kartu dashboard, detail |
| `Badge` (dengan varian `successSubtle`, `warningSubtle`, `destructiveSubtle`) | `components/ui/badge.tsx` | Status artikel / promo |
| `Input` | `components/ui/input.tsx` | Input teks form |
| `Textarea` | `components/ui/textarea.tsx` | Isi artikel, deskripsi promo |
| `Select` | `components/ui/select.tsx` | Dropdown status, kategori |
| `Dialog`, `DialogTrigger`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter` | `components/ui/dialog.tsx` | Konfirmasi arsip/nonaktifkan, form create/edit dalam modal |
| `Alert`, `AlertTitle`, `AlertDescription` | `components/ui/alert.tsx` | Error state, pesan validasi |
| `Skeleton`, `SkeletonRows` | `components/ui/states/loading-state.tsx` | Loading state |
| `EmptyState` | `components/ui/states/empty-state.tsx` | Kosong dengan tindakan lanjut |
| `ErrorState` | `components/ui/states/error-state.tsx` | Error dengan tombol coba lagi |
| `PageHeader` | `components/layout/page-header.tsx` | Judul setiap layar |
| `sonner` toast | `components/ui/sonner.tsx` | Konfirmasi sukses singkat |
| `lucide-react` ikon | — | `FileText`, `Tag`, `Edit`, `Trash2`, `Archive`, `RefreshCw`, `AlertCircle`, `SearchX`, `ShieldAlert`, `Plus`, `ChevronLeft`, dll. |

**Tidak ada `table.tsx` di `components/ui/`**. Tabel harus dibangun dengan elemen HTML semantik (`<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`) dengan styling token, atau komponen tabel lokal baru jika diperlukan (catat di Pertanyaan terbuka).

---

## 4. Halaman & rute

| Halaman | Path | Portal | Guard |
|---|---|---|---|
| `MarketingDashboardPage` | `/marketing/dashboard` | marketing | `tipe:marketing` + `permission:artikel.kelola` |
| `MarketingArtikelPage` | `/marketing/artikel` | marketing | `tipe:marketing` + `permission:artikel.kelola` |
| `MarketingPromoPage` | `/marketing/promo` | marketing | `tipe:marketing` + `permission:promo.kelola` |

Guard sudah siap di `marketing-routes.tsx` + `RequirePortalAuth portal="marketing"`.

---

## 5. API client — `lib/api/marketing.ts`

Path: `frontend/src/lib/api/marketing.ts`

Template: `frontend/src/lib/api/kurir.ts`

Base path (relative, tanpa `/api/v1` karena `api()` sudah menambahkannya): `marketing/artikel` dan `marketing/promo`.

### 5.1 Type shapes (dtranskripsi dari resource backend — perlu diverifikasi)

**Artikel** (`artikel.status` ENUM: `draft`, `review`, `terbit`, `arsip`):

```ts
export type ArtikelStatus = 'draft' | 'review' | 'terbit' | 'arsip';

export type Artikel = {
    id: number;
    judul: string;
    slug: string | null;
    ringkasan: string | null;
    isi: string | null;
    status: ArtikelStatus;
    penulis_id: number | null;
    penulis_nama: string | null;
    kategori_id: number | null;
    kategori_nama: string | null;
    tampil_di_halaman_umum: boolean;
    dibuat_at: string | null;
    diubah_at: string | null;
    dipublikasikan_at: string | null;
};
```

**Artikel input (write):**

```ts
export type ArtikelInput = {
    judul: string;
    slug?: string | null;
    ringkasan?: string | null;
    isi?: string | null;
    status: ArtikelStatus;
    penulis_id?: number | null;
    kategori_id?: number | null;
    tampil_di_halaman_umum?: boolean;
};
```

**Promo** (`status_aktif` TINYINT(1)):

```ts
export type Promo = {
    id: number;
    kode: string;
    judul: string;
    deskripsi: string | null;
    tipe_diskon: string | null;  // dari master_promo.tipe_diskon ENUM
    nilai_diskon: number | string | null;  // Decimal
    kuota: number | null;
    kuota_terpakai: number | null;
    berlaku_dari: string | null;  // Tanggal
    berlaku_sampai: string | null;  // Tanggal
    status_aktif: boolean;
    dibuat_at: string | null;
    diubah_at: string | null;
};
```

**Promo input (write):**

```ts
export type PromoInput = {
    kode: string;
    judul: string;
    deskripsi?: string | null;
    tipe_diskon?: string | null;
    nilai_diskon?: number | string | null;
    kuota?: number | null;
    berlaku_dari?: string | null;
    berlaku_sampai?: string | null;
    status_aktif?: boolean;
};
```

> **Pertanyaan terbuka:** Field eksak dari `MarketingArtikelResource` dan `MarketingPromoResource` belum dikonfirmasi dari resource aktual. Type di atas adalah asumsi pola. Frontend harus menyesuaikan dengan resource aktual saat WS-4 selesai.

### 5.2 Query & mutation options

Ikuti pola `kurir.ts`:
- `queryOptions({ queryKey, queryFn, ... })` untuk read
- `mutationOptions({ mutationFn, onSuccess })` untuk write, dengan `queryClient.invalidateQueries` pada success

Query keys:
- Artikel list: `['v1', 'marketing', 'artikel']`
- Artikel detail: `['v1', 'marketing', 'artikel', id]`
- Promo list: `['v1', 'marketing', 'promo']`
- Promo detail: `['v1', 'marketing', 'promo', id]`

Invalidasi setelah write: invalidasi list query terkait (dan detail jika relevan) agar layar refleksikan status baru tanpa refresh manual.

**Soft delete (tidak ada hard delete):**
- Artikel `DELETE` → backend set `status = 'arsip'`. UI menampilkan arsip sebagai status, bukan menghapus baris dari tabel.
- Promo `DELETE` → backend set `status_aktif = 0`. UI menampilkan sebagai nonaktif.

---

## 6. Aturan tampilan & state

### 6.1 Tiga state wajib di setiap layar (dari AGENTS.md & _global.md §8)

1. **Loading** — skeleton (`SkeletonRows` / `Skeleton`), bukan spinner layar penuh. `role="status"`, `aria-live="polite"`.
2. **Kosong** — `EmptyState` dengan judul, deskripsi, dan **tindakan lanjut** (tombol buat baru / tautan kelola).
3. **Error** — `ErrorState` dengan pesan spesifik + tombol "Coba lagi". 403/401/404 tidak ada tombol coba lagi (bukan retryable).

### 6.2 Badge status

Pola wajib: latar `*-subtle` + teks `*-subtle-foreground` + ikon `lucide-react` + label teks. Nilai dari enum, jangan string manual.

| Status | Varian Badge | Ikon | Label |
|---|---|---|---|
| `draft` | `--muted` / netral | `Edit` | Draft |
| `review` | `--warning-subtle` | `AlertCircle` | Review |
| `terbit` | `--success-subtle` | `CheckCircle` | Terbit |
| `arsip` | `--destructive-subtle` | `Archive` | Arsip |
| promo `status_aktif=true` | `--success-subtle` | `CheckCircle` | Aktif |
| promo `status_aktif=false` | `--destructive-subtle` | `Ban` | Nonaktif |

### 6.3 Tanggal & zona waktu

- Format tanggal: `04 Okt 2026` (`id-ID`)
- Format jam: `09.30` (titik pemisah gaya Indonesia)
- Selalu berlabel zona: `WIB` / `WITA` / `WIT`
- Pakai `@daypicker/react` untuk parsing/format

### 6.4 Bahasa Indonesia, nada tenang dan jelas

- Semua label, tombol, pesan error, toast dalam Bahasa Indonesia
- Huruf awal kapital pada status, bukan KAPITAL SEMUA
- Tidak ada lorem ipsum, data realistis (nama dokter/obat Indonesia jika perlu)
- Tidak ada data sensitif di toast, judul tab, atau URL

### 6.5 Mobile-first

- Desain di 390 px lebih dulu, lalu 768 px dan 1280 px
- Target sentuh minimal 44 px
- Fokus keyboard terlihat (ring token)
- Semua input berlabel

### 6.6 Interaksi

- Aksi berisiko (arsip, nonaktifkan, hapus) → `Dialog` konfirmasi, judul menyebut akibat, tombol batal selalu terlihat
- Toast `sonner` hanya untuk sukses singkat (maks 1 baris), tidak ada data sensitif
- Error validasi (422) → inline di bawah field, bukan toast
- Transisi maksimal 150–200 ms, `prefers-reduced-motion` dihormati

---

## 7. Pertanyaan terbuka

1. Field eksak dari `MarketingArtikelResource` / `MarketingPromoResource` — perlu diverifikasi dari resource aktual saat WS-4 selesai. Type di atas adalah asumsi pola.
2. Tidak ada `table.tsx` di `components/ui/`. Apakah tabel dibangun dengan HTML semantik inline, atau perlu komponen tabel lokal baru?
3. Apakah form create/edit artikel/promo pakai `Dialog` (modal) atau halaman terpisah (`/marketing/artikel/create`, `/marketing/artikel/:id/edit`)? Pola `kurir` pakai detail page terpisah; untuk marketing perlu keputusan.
4. Apakah `slug` artikel di-generate otomatis dari `judul` di frontend, atau dibiarkan kosong dan diisi backend?
5. `tipe_diskon` dan `nilai_diskon` untuk promo — enum dan tipeDecimal perlu konfirmasi dari resource.

---

## 8. File spec terkait

| File | Isi |
|---|---|
| `./specs/api-marketing.md` | Spec lengkap `lib/api/marketing.ts` |
| `./specs/marketing-dashboard.md` | Spec `MarketingDashboardPage` |
| `./specs/marketing-artikel.md` | Spec `MarketingArtikelPage` |
| `./specs/marketing-promo.md` | Spec `MarketingPromoPage` |

---

## 9. Verifikasi (untuk Phase 2 & Phase 3 QA)

- Semua warna/font/spacing/radius dari token `app.css` — tidak ada hardcode hex/rgb/oklch
- Tiga state (loading/kosong/error) ada di setiap layar
- Badge status: teks + ikon + warna, tidak pernah warna saja
- Tidak ada gradient, glassmorphism, glow, `backdrop-filter`
- Target sentuh ≥ 44 px, kontras ≥ 4.5:1, fokus keyboard terlihat
- Uji di light dan dark pada 390 px dan 1280 px
- `npm run types:check` lolos
- Aksesibilitas otomatis dengan `@axe-core/playwright` + pemeriksaan keyboard/screen-reader manual

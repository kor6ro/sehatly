# Spec: `lib/api/marketing.ts` — API Client Artikel & Promo

**Proyek:** Sehatly — WS-7 Marketing  
**Path target:** `frontend/src/lib/api/marketing.ts`  
**Template:** `frontend/src/lib/api/kurir.ts`  
**Status:** DESIGN (Phase 1) — implementasi oleh frontend Phase 2

---

## 1. Tujuan

API client untuk resource marketing (`artikel` dan `promo`) di portal marketing. Mengikuti pola `kurir.ts` secara eksak: `mutationOptions`/`queryOptions` dari `@tanstack/react-query`, `request` dari `@lib/http`, `queryClient` dari `@lib/query-client`, type shapes ditranskripsi dari resource/backend, dan invalidasi query setelah write.

---

## 2. Base path & transport

- Transport: `request<T>(path, options)` dari `@/lib/http`
- Base URL (`ky.create`): `${origin}/api/v1/` — path dikirim **relatif, tanpa leading slash**, tanpa `/api/v1`
- Path artikel: `marketing/artikel` dan `marketing/artikel/{id}`
- Path promo: `marketing/promo` dan `marketing/promo/{id}`
- Middleware server: `tipe:marketing` + `permission:artikel.kelola` / `permission:promo.kelola`

---

## 3. Response shapes (dtranskripsi dari resource)

> **Pertanyaan terbuka:** Field eksak dari `MarketingArtikelResource` / `MarketingPromoResource` perlu diverifikasi dari resource aktual saat WS-4 selesai. Type di bawah adalah asumsi pola berdasarkan konvensi repo dan `docs/enums.json`.

### 3.1 Artikel

`artikel.status` ENUM (dari `docs/enums.json`): `draft`, `review`, `terbit`, `arsip`

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

Response envelope: `ApiResult<Artikel>` untuk show, `ApiResult<Artikel[]>` untuk index (dengan `meta` jika paginasi).

### 3.2 Artikel input (write)

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

### 3.3 Promo

`status_aktif` TINYINT(1) → `boolean`

```ts
export type Promo = {
    id: number;
    kode: string;
    judul: string;
    deskripsi: string | null;
    tipe_diskon: string | null;   // dari master_promo.tipe_diskon ENUM
    nilai_diskon: number | string | null; // Decimal → string di JSON
    kuota: number | null;
    kuota_terpakai: number | null;
    berlaku_dari: string | null;  // Tanggal (Y-m-d)
    berlaku_sampai: string | null; // Tanggal (Y-m-d)
    status_aktif: boolean;
    dibuat_at: string | null;
    diubah_at: string | null;
};
```

### 3.4 Promo input (write)

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

---

## 4. Fetchers

Setiap fungsi mengembalikan `Promise<ApiResult<T>>`. Tidak ada `try/cush` di dalam fetcher — `request()` sudah menangkap dan mengubah `HTTPError` menjadi `ApiError`.

```ts
export async function fetchArtikelIndex(): Promise<ApiResult<Artikel[]>> {
    return request<Artikel[]>('marketing/artikel');
}

export async function fetchArtikelShow(id: number): Promise<ApiResult<Artikel>> {
    return request<Artikel>(`marketing/artikel/${id}`);
}

export async function storeArtikel(input: ArtikelInput): Promise<ApiResult<Artikel>> {
    return request<Artikel>('marketing/artikel', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function updateArtikel(id: number, input: ArtikelInput): Promise<ApiResult<Artikel>> {
    return request<Artikel>(`marketing/artikel/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export async function destroyArtikel(id: number): Promise<ApiResult<unknown>> {
    return request<unknown>(`marketing/artikel/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}

export async function fetchPromoIndex(): Promise<ApiResult<Promo[]>> {
    return request<Promo[]>('marketing/promo');
}

export async function fetchPromoShow(id: number): Promise<ApiResult<Promo>> {
    return request<Promo>(`marketing/promo/${id}`);
}

export async function storePromo(input: PromoInput): Promise<ApiResult<Promo>> {
    return request<Promo>('marketing/promo', {
        method: 'POST',
        json: input,
        retry: 0,
    });
}

export async function updatePromo(id: number, input: PromoInput): Promise<ApiResult<Promo>> {
    return request<Promo>(`marketing/promo/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export async function destroyPromo(id: number): Promise<ApiResult<unknown>> {
    return request<unknown>(`marketing/promo/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}
```

---

## 5. Query keys

```ts
export const artikelListKey = ['v1', 'marketing', 'artikel'] as const;
export const artikelDetailKey = (id: number) => [...artikelListKey, id] as const;

export const promoListKey = ['v1', 'marketing', 'promo'] as const;
export const promoDetailKey = (id: number) => [...promoListKey, id] as const;
```

---

## 6. Query options (read)

```ts
export function artikelListOptions() {
    return queryOptions({
        queryKey: artikelListKey,
        queryFn: fetchArtikelIndex,
        staleTime: 30_000,
        refetchOnWindowFocus: false,
    });
}

export function artikelDetailOptions(id: number) {
    return queryOptions({
        queryKey: artikelDetailKey(id),
        queryFn: () => fetchArtikelShow(id),
        enabled: !!id,
    });
}

export function promoListOptions() {
    return queryOptions({
        queryKey: promoListKey,
        queryFn: fetchPromoIndex,
        staleTime: 30_000,
        refetchOnWindowFocus: false,
    });
}

export function promoDetailOptions(id: number) {
    return queryOptions({
        queryKey: promoDetailKey(id),
        queryFn: () => fetchPromoShow(id),
        enabled: !!id,
    });
}
```

---

## 7. Mutation options (write) + invalidasi

Setiap mutation: `retry: 0` (query client default), `onSuccess` invalidate queries terkait.

```ts
export function storeArtikelMutation() {
    return mutationOptions({
        mutationFn: storeArtikel,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: artikelListKey });
        },
    });
}

export function updateArtikelMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: ArtikelInput) => updateArtikel(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: artikelListKey });
            void queryClient.invalidateQueries({ queryKey: artikelDetailKey(id) });
        },
    });
}

export function destroyArtikelMutation(id: number) {
    return mutationOptions({
        mutationFn: () => destroyArtikel(id),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: artikelListKey });
            void queryClient.invalidateQueries({ queryKey: artikelDetailKey(id) });
        },
    });
}

export function storePromoMutation() {
    return mutationOptions({
        mutationFn: storePromo,
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: promoListKey });
        },
    });
}

export function updatePromoMutation(id: number) {
    return mutationOptions({
        mutationFn: (input: PromoInput) => updatePromo(id, input),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: promoListKey });
            void queryClient.invalidateQueries({ queryKey: promoDetailKey(id) });
        },
    });
}

export function destroyPromoMutation(id: number) {
    return mutationOptions({
        mutationFn: () => destroyPromo(id),
        onSuccess: () => {
            void queryClient.invalidateQueries({ queryKey: promoListKey });
            void queryClient.invalidateQueries({ queryKey: promoDetailKey(id) });
        },
    });
}
```

---

## 8. Soft delete (tidak ada hard delete)

- **Artikel `DELETE`** → backend set `status = 'arsip'`. UI **tidak** menghapus baris dari tabel, hanya memperbarui status badge menjadi `arsip` dan invalidate query.
- **Promo `DELETE`** → backend set `status_aktif = 0`. UI menampilkan sebagai nonaktif, tidak menghapus baris.

---

## 9. Error handling yang diharapkan dari komponen pemakai

- `422` → render inline per-field errors via `error.fieldErrors(field)` + `error.failedFields`
- `403` → `ForbiddenState` (tidak ada tombol coba lagi)
- `404` → `NotFoundState` (tidak ada tombol coba lagi)
- `401` → ditangani oleh `http.ts` (refresh single-flight → redirect `/masuk`)
- `0` / jaringan → `ErrorState` dengan tombol "Coba lagi"

---

## 10. Pertanyaan terbuka

1. Field eksak dari resource — `slug`, `kategori_id`, `tampil_di_halaman_umum`, `tipe_diskon`, `nilai_diskon`, `kuota`, `berlaku_dari/sampai` perlu konfirmasi dari `MarketingArtikelResource` / `MarketingPromoResource`.
2. Apakah index endpoint paginasi (`meta` disertakan) atau mengembalikan array mentah? `kurir.ts` index mengembalikan `{ pesanan: [...] }` tanpa meta. Marketing mungkin sama.
3. Apakah `destroy` memerlukan konfirmasi dialog di frontend sebelum dipanggil? (Disarankan ya, aksi berisiko.)

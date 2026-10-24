import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';

// ============================================================================
// Response shapes — transcribed from MarketingArtikelResource / PromoResource
// Backend wraps collection under `artikel` / `promo` keys.
// ============================================================================

export type ArtikelStatus = 'draft' | 'review' | 'terbit' | 'arsip';

export type ArtikelKategori = {
    id: number;
    nama: string;
    slug: string;
};

export type Artikel = {
    id: number;
    kategori_id: number;
    kategori: ArtikelKategori | null;
    penulis_user_id: number;
    judul: string;
    slug: string;
    ringkasan: string | null;
    konten: string;
    cover_url: string | null;
    status: ArtikelStatus;
    jumlah_view: number;
    published_at: string | null;
    dibuat_at: string | null;
    diubah_at: string | null;
};

export type ArtikelInput = {
    kategori_id: number;
    judul: string;
    slug: string;
    ringkasan?: string | null;
    konten: string;
    cover_url?: string | null;
    status: ArtikelStatus;
};

export type PromoTipeDiskon = 'persen' | 'nominal' | 'gratis_ongkir';

export type Promo = {
    id: number;
    kode: string;
    nama: string;
    tipe_diskon: PromoTipeDiskon;
    nilai: string; // Decimal → string di JSON
    min_transaksi: string | null; // Decimal → string di JSON
    maks_diskon: string | null; // Decimal → string di JSON
    kuota_total: number | null;
    kuota_per_user: number;
    mulai_at: string | null; // ISO
    selesai_at: string | null; // ISO
    status_aktif: boolean;
    dibuat_at: string | null;
    diubah_at: string | null;
};

export type PromoInput = {
    kode: string;
    nama: string;
    tipe_diskon: PromoTipeDiskon;
    nilai: number | string;
    min_transaksi?: number | string | null;
    maks_diskon?: number | string | null;
    kuota_total?: number | null;
    kuota_per_user?: number;
    mulai_at: string; // 'Y-m-d H:i:s'
    selesai_at: string; // 'Y-m-d H:i:s'
    status_aktif?: boolean;
};

// ============================================================================
// Fetchers
// Backend envelopes: index → { artikel: Artikel[] }, show → { artikel: Artikel }
// ============================================================================

export async function fetchArtikelIndex(): Promise<Artikel[]> {
    const result = await request<{ artikel: Artikel[] }>('marketing/artikel');
    return result.data.artikel;
}

export async function fetchArtikelShow(id: number): Promise<Artikel> {
    const result = await request<{ artikel: Artikel }>(`marketing/artikel/${id}`);
    return result.data.artikel;
}

export async function storeArtikel(input: ArtikelInput): Promise<Artikel> {
    const result = await request<{ artikel: Artikel }>('marketing/artikel', {
        method: 'POST',
        json: input,
        retry: 0,
    });
    return result.data.artikel;
}

export async function updateArtikel(id: number, input: ArtikelInput): Promise<Artikel> {
    const result = await request<{ artikel: Artikel }>(`marketing/artikel/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
    return result.data.artikel;
}

export async function destroyArtikel(id: number): Promise<void> {
    await request<unknown>(`marketing/artikel/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}

export async function fetchPromoIndex(): Promise<Promo[]> {
    const result = await request<{ promo: Promo[] }>('marketing/promo');
    return result.data.promo;
}

export async function fetchPromoShow(id: number): Promise<Promo> {
    const result = await request<{ promo: Promo }>(`marketing/promo/${id}`);
    return result.data.promo;
}

export async function storePromo(input: PromoInput): Promise<Promo> {
    const result = await request<{ promo: Promo }>('marketing/promo', {
        method: 'POST',
        json: input,
        retry: 0,
    });
    return result.data.promo;
}

export async function updatePromo(id: number, input: PromoInput): Promise<Promo> {
    const result = await request<{ promo: Promo }>(`marketing/promo/${id}`, {
        method: 'PUT',
        json: input,
        retry: 0,
    });
    return result.data.promo;
}

export async function destroyPromo(id: number): Promise<void> {
    await request<unknown>(`marketing/promo/${id}`, {
        method: 'DELETE',
        retry: 0,
    });
}

// ============================================================================
// Query keys
// ============================================================================

export const artikelListKey = ['v1', 'marketing', 'artikel'] as const;
export const artikelDetailKey = (id: number) => [...artikelListKey, id] as const;

export const promoListKey = ['v1', 'marketing', 'promo'] as const;
export const promoDetailKey = (id: number) => [...promoListKey, id] as const;

// ============================================================================
// Query options (read)
// ============================================================================

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

// ============================================================================
// Mutation options (write) + invalidasi
// ============================================================================

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

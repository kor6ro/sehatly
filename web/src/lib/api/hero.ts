import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';

/**
 * The landing carousel's data: the public read, and the `admin` surface behind
 * `hero.kelola`.
 *
 * ## One type, two audiences, and why they are the same shape
 *
 * {@link HeroSlide} is a transcription of what `HeroService::susun()` answers - the
 * admin list and the public list are the SAME row shape, because the only difference
 * between them is *which* rows are selected. That keeps the landing page's fallback
 * logic honest: a built-in slide is an object with the same keys a server row has, so
 * "use the server's" and "use ours" are one rendering path rather than two that can
 * drift apart in what they show.
 *
 * ## `gambar` is an absolute URL, or `null`, and `null` is a real state
 *
 * `null` means "this slide has no photograph", which is not a failure and not an
 * empty string: it is the signal the carousel uses to render its gradient. A slide
 * with no image is a legitimate, fully-formed slide - the copy is the slide and the
 * photograph is decoration on top of it.
 *
 * ## `tayang_aktif` is computed at read time and is not a column
 *
 * It is `status = 'tayang'` AND inside the publication window, evaluated against the
 * server clock when the row was read. The admin screen uses it to show which slides a
 * visitor can currently see; the public read only ever returns rows where it is
 * already true, so a client never has to re-derive a window it would get wrong.
 */

export type StatusHero = 'draf' | 'tayang';

export type HeroSlide = {
    id: number;
    urutan: number;
    eyebrow: string | null;
    judul: string;
    deskripsi: string;
    cta_label: string;
    /** An INTERNAL path (`/dokter`). Never an absolute URL - see `SimpanHeroRequest`. */
    cta_target: string;
    /** Absolute URL of the stored file, or `null` when the slide has no image. */
    gambar: string | null;
    gambar_alt: string | null;
    status: StatusHero;
    /** `Y-m-d H:i:s`, or `null` for "no bound" - three window shapes are legal. */
    mulai_tayang: string | null;
    selesai_tayang: string | null;
    tayang_aktif: boolean;
    dibuat_at: string;
    diubah_at: string;
};

/**
 * What `POST /admin/hero` and `PUT /admin/hero/{id}` accept.
 *
 * Only the copy and the window are writable through JSON: the image travels as
 * multipart through `unggahHeroGambar()` and leaves through `lepasHeroGambar()`, and
 * both fields are `prohibited` on the JSON requests so a client cannot believe
 * otherwise.
 */
export type InputHero = {
    urutan?: number;
    eyebrow?: string | null;
    judul: string;
    deskripsi: string;
    cta_label: string;
    cta_target: string;
    status?: StatusHero;
    mulai_tayang?: string | null;
    selesai_tayang?: string | null;
};

export type InputHeroUbah = Partial<InputHero>;

// ============================================================================
// Cache keys
// ============================================================================

export const heroQueryKey = ['v1', 'hero'] as const;

export const adminHeroQueryKey = ['v1', 'admin', 'hero'] as const;

// ============================================================================
// The public read
// ============================================================================

/** `GET /hero` - only the slides a visitor can see right now, in strip order. */
export async function fetchHero() {
    return request<{ hero: HeroSlide[] }>('hero');
}

/**
 * The landing page's query.
 *
 * `retry: false` is deliberate and is the opposite of the default: this endpoint is
 * reachable with no session, so a failure here is a network or server problem the
 * page must answer by rendering its built-in slides immediately. Three retries of a
 * down API would hold the fold blank while the visitor waits, and the fallback is
 * already on screen by then - nothing is gained and the paint is lost.
 *
 * `staleTime` keeps a returning visitor's carousel from re-fetching on every
 * navigation back to `/`, where a banner changes on the order of days.
 */
export function heroOptions() {
    return queryOptions({
        queryKey: heroQueryKey,
        queryFn: fetchHero,
        staleTime: 60_000,
        retry: false,
    });
}

// ============================================================================
// The admin surface
// ============================================================================

/** `GET /admin/hero` - every row, drafts first-class, no pagination. */
export async function fetchAdminHero() {
    return request<{ hero: HeroSlide[] }>('admin/hero');
}

export function adminHeroOptions() {
    return queryOptions({
        queryKey: adminHeroQueryKey,
        queryFn: fetchAdminHero,
    });
}

/** `POST /admin/hero` - 201, answered with the created row. */
export async function simpanHero(body: InputHero) {
    return request<{ hero: HeroSlide }>('admin/hero', {
        method: 'POST',
        json: body,
    });
}

/**
 * `PUT /admin/hero/{id}` - partial, and the publish switch.
 *
 * A body of `{ status: 'tayang' }` alone publishes: every rule on the server is
 * `sometimes`, so nothing else is read and nothing else moves. That is what lets the
 * list's Tayang/Draf toggle be one call instead of a re-submit of the whole form.
 */
export async function ubahHero(id: number, body: InputHeroUbah) {
    return request<{ hero: HeroSlide }>(`admin/hero/${id}`, {
        method: 'PUT',
        json: body,
    });
}

/** `DELETE /admin/hero/{id}` - the row and the file it owns. */
export async function hapusHero(id: number) {
    return request<null>(`admin/hero/${id}`, {
        method: 'DELETE',
    });
}

/**
 * `POST /admin/hero/{id}/gambar` - multipart, replaces any previous file.
 *
 * The alternative text goes in the SAME request as the file, because the server
 * requires it there: the migration cannot couple two columns together, so the upload
 * is the one moment where "image" and "the words a screen reader needs" are both
 * present and can both be checked.
 */
export async function unggahHeroGambar(id: number, gambar: File, gambar_alt: string) {
    const form = new FormData();

    form.append('gambar', gambar);
    form.append('gambar_alt', gambar_alt);

    return request<{ hero: HeroSlide }>(`admin/hero/${id}/gambar`, {
        method: 'POST',
        body: form,
        retry: 0,
    });
}

/** `DELETE /admin/hero/{id}/gambar` - image and alt text, cleared together. */
export async function lepasHeroGambar(id: number) {
    return request<{ hero: HeroSlide }>(`admin/hero/${id}/gambar`, {
        method: 'DELETE',
    });
}

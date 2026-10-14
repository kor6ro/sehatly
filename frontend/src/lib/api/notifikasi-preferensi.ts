import { mutationOptions, queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import { queryClient } from '@/lib/query-client';

/**
 * `GET|PUT /api/v1/profil/notifikasi` - F11's preference surface.
 *
 * ## The response shape is the service's own effective state
 *
 * `PreferensiNotifikasiController` answers `data.preferensi`, and that block is
 * `PreferensiNotifikasiService::efektif()`: the four produced types with absent rows
 * folded to `true`, quiet hours OFF by default (`setiap_hari`, `21:00`-`06:00`,
 * `Asia/Jakarta`). The client binds to it rather than to a local merge, so a save
 * answers the resulting state instead of assuming what the merge did.
 *
 * ## In-app delivery has no field, deliberately
 *
 * The only stored switch is `preferensi_notifikasi_tipe.push_aktif`. In-app delivery is
 * unconditional and the screen explains that rather than offering a control the server
 * would ignore - the matrix therefore has ONE writable column, `push`.
 *
 * ## The matrix is closed at the four PRODUCED types
 *
 * `lab`/`promo`/`sistem` are not offered: `UpdatePreferensiNotifikasiRequest` refuses
 * them with a 422 (`array:booking,pembayaran,resep,chat`), so rendering them as controls
 * would be a switch that can only fail.
 */

/** The four produced types, in the request's own whitelist order. */
export const TIPE_PREFERENSI = ['booking', 'pembayaran', 'resep', 'chat'] as const;

export type TipePreferensi = (typeof TIPE_PREFERENSI)[number];

/** `preferensi_notifikasi.jam_tenang_mode`, the three DDL values. */
export type JamTenangMode = 'setiap_hari' | 'hari_kerja' | 'kustom';

/**
 * The effective preferences, transcribed from `PreferensiNotifikasiService::efektif()`.
 *
 * `zona_waktu` is the IANA name (`Asia/Jakarta`), not the `WIB` label; the label comes
 * from `labelZona()` in `lib/waktu.ts`.
 */
export type PreferensiNotifikasi = {
    jam_tenang_aktif: boolean;
    jam_tenang_mode: JamTenangMode;
    /** `HH:MM`, 24-hour - the form the request accepts (`date_format:H:i`). */
    jam_tenang_mulai: string;
    jam_tenang_selesai: string;
    zona_waktu: string;
    push: Record<TipePreferensi, boolean>;
};

/**
 * A form PUT: every key is optional because the endpoint merges only what it is given.
 *
 * Sending the whole block is legal and is what this client does, so one save cannot
 * silently reset a field the user never touched.
 */
export type PreferensiNotifikasiInput = {
    jam_tenang_aktif?: boolean;
    jam_tenang_mode?: JamTenangMode;
    jam_tenang_mulai?: string;
    jam_tenang_selesai?: string;
    zona_waktu?: string;
    push?: Partial<Record<TipePreferensi, boolean>>;
};

export const LABEL_TIPE_PREFERENSI: Record<TipePreferensi, string> = {
    booking: 'Booking',
    pembayaran: 'Pembayaran',
    resep: 'Resep',
    chat: 'Konsultasi',
};

export const LABEL_JAM_TENANG_MODE: Record<JamTenangMode, string> = {
    setiap_hari: 'Setiap hari',
    hari_kerja: 'Hari kerja',
    kustom: 'Kustom',
};

/**
 * Half-hour wall clocks for the quiet-hours selects, `00:00` through `23:30`.
 *
 * `date_format:H:i` accepts any minute, but a reminder schedule lives on half hours in
 * practice and a 48-option list is far shorter than a free-text time field. The stored
 * value is echoed even if it is not on the half hour - the select renders it as an extra
 * option in that case - so opening the page never rewrites what another client saved.
 */
export function opsiJamTenang(): string[] {
    const opsi: string[] = [];

    for (let menit = 0; menit < 24 * 60; menit += 30) {
        const jam = String(Math.floor(menit / 60)).padStart(2, '0');
        const sisa = String(menit % 60).padStart(2, '0');

        opsi.push(`${jam}:${sisa}`);
    }

    return opsi;
}

export const preferensiNotifikasiQueryKey = ['v1', 'profil', 'notifikasi'] as const;

/**
 * No automatic retry: the screen owns one explicit "Coba lagi", so one tap is one
 * request and the retry is observable.
 */
export async function fetchPreferensiNotifikasi() {
    return request<{ preferensi: PreferensiNotifikasi }>('profil/notifikasi', {
        retry: 0,
    });
}

export async function simpanPreferensiNotifikasi(input: PreferensiNotifikasiInput) {
    return request<{ preferensi: PreferensiNotifikasi }>('profil/notifikasi', {
        method: 'PUT',
        json: input,
        retry: 0,
    });
}

export function preferensiNotifikasiOptions() {
    return queryOptions({
        queryKey: preferensiNotifikasiQueryKey,
        queryFn: fetchPreferensiNotifikasi,
        retry: false,
    });
}

/**
 * The PUT answer IS the new effective state, so it is written straight into the cache.
 *
 * That is what makes "save then reload shows the stored values" true without a second
 * round trip: the server's own answer is the cache entry the next mount reads.
 */
export function simpanPreferensiNotifikasiMutation() {
    return mutationOptions({
        mutationFn: simpanPreferensiNotifikasi,
        onSuccess: (result) => {
            queryClient.setQueryData(preferensiNotifikasiQueryKey, result);
        },
    });
}

import type { Iso, Tanggal } from '@/lib/api/types';
import { zonaPerangkat } from '@/lib/waktu';

/**
 * The pure half of F10's hub: month grouping and the client-side search.
 *
 * No transport is imported here, so `node --test` can reach these functions directly
 * (the same split `resep-peringatan.ts` makes for the prescription rules).
 */

export const BULAN_ID: ReadonlyArray<string> = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

/**
 * The `YYYY-MM` bucket an ISO instant falls in, read in `zona` (the device zone by
 * default). An invalid value returns `null` rather than throwing, because one malformed
 * row must not blank a whole timeline.
 */
export function kunciBulanInstan(
    value: Iso,
    zona: string = zonaPerangkat(),
): string | null {
    if (value === null || value === '') {
        return null;
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return null;
    }

    const bagian = new Intl.DateTimeFormat('en-CA', {
        timeZone: zona,
        year: 'numeric',
        month: '2-digit',
    }).formatToParts(parsed);

    const tahun = bagian.find((part) => part.type === 'year')?.value;
    const bulan = bagian.find((part) => part.type === 'month')?.value;

    if (tahun === undefined || bulan === undefined) {
        return null;
    }

    return `${tahun}-${bulan}`;
}

/**
 * The `YYYY-MM` bucket a wall-clock `Y-m-d` falls in.
 *
 * Read by splitting the string, never through `new Date()`: a bare date parses as UTC
 * midnight and would shift the month for a reader in a negative offset.
 */
export function kunciBulanTanggal(value: Tanggal): string | null {
    if (value === null || value === '') {
        return null;
    }

    const cocok = /^(\d{4})-(\d{2})-\d{2}$/.exec(value);

    if (cocok === null) {
        return null;
    }

    return `${cocok[1]}-${cocok[2]}`;
}

/** `2026-10` -> `Oktober 2026`, and an unparseable key back as-is. */
export function labelBulan(kunci: string): string {
    const cocok = /^(\d{4})-(\d{2})$/.exec(kunci);

    if (cocok === null) {
        return kunci;
    }

    const indeks = Number(cocok[2]) - 1;
    const nama = BULAN_ID[indeks];

    if (nama === undefined) {
        return kunci;
    }

    return `${nama} ${cocok[1]}`;
}

/**
 * Group rows into month buckets, preserving the caller's order - both between groups
 * and inside them. Callers sort newest-first before calling, so the timeline reads
 * newest month first without this function owning a second ordering rule.
 *
 * Rows whose key cannot be computed are dropped rather than put in a nameless bucket;
 * every row type in F10 carries a date, so the case only arises on malformed data.
 */
export function kelompokkanBulan<T>(
    baris: ReadonlyArray<T>,
    ambilKunci: (baris: T) => string | null,
): Array<{ kunci: string; label: string; baris: T[] }> {
    const peta = new Map<string, T[]>();

    for (const row of baris) {
        const kunci = ambilKunci(row);

        if (kunci === null) {
            continue;
        }

        const daftar = peta.get(kunci);

        if (daftar === undefined) {
            peta.set(kunci, [row]);
        } else {
            daftar.push(row);
        }
    }

    return [...peta.entries()].map(([kunci, rows]) => ({
        kunci,
        label: labelBulan(kunci),
        baris: rows,
    }));
}

/** Milliseconds of an ISO instant, or `0` for anything unparseable (sorts last). */
export function waktuMs(value: Iso): number {
    if (value === null || value === '') {
        return 0;
    }

    const parsed = new Date(value).getTime();

    return Number.isNaN(parsed) ? 0 : parsed;
}

/** A copy sorted newest-first by an ISO instant. */
export function urutkanInstanMenurun<T>(
    baris: ReadonlyArray<T>,
    ambilWaktu: (baris: T) => Iso,
): T[] {
    return [...baris].sort(
        (a, b) => waktuMs(ambilWaktu(b)) - waktuMs(ambilWaktu(a)),
    );
}

/** Case- and accent-insensitive enough for Indonesian: trim, lowercase, compare. */
export function normalisasiCari(q: string): string {
    return q.trim().toLocaleLowerCase('id-ID');
}

/**
 * Does the query match any of the row's searchable fields? An empty query matches
 * everything, which is what makes this safe to call unconditionally in a filter.
 */
export function cocokCari(
    q: string,
    ...teks: Array<string | null | undefined>
): boolean {
    const cari = normalisasiCari(q);

    if (cari === '') {
        return true;
    }

    return teks.some((t) => (t ?? '').toLocaleLowerCase('id-ID').includes(cari));
}

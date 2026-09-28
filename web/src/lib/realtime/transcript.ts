import type { KonsultasiPesan } from '@/lib/api/types';

/**
 * The rendered transcript: the REST history page and the live frames, merged by `id`.
 *
 * This merge is the half of the dedupe `MessageDedupe` cannot do. The dedupe set gates
 * what the realtime layer *delivers*; it cannot gate a row that arrived over REST,
 * because a REST page is not a delivery. So the two lists are folded through a `Map`
 * keyed on `konsultasi_chat.id` and the second arrival of a row is simply not
 * inserted.
 *
 * Without this, the sender's own message renders twice whenever the socket frame beats
 * the refetch that follows the POST - and renders once when the refetch wins. That is
 * the worst shape of defect: it looks correct on the runs it happens not to occur on,
 * while the counter the realtime layer already publishes reads zero with the duplicate
 * on screen.
 *
 * ## Why it is a leaf module and not a helper inside the hook
 *
 * The two arrival orders are mutually exclusive on any single run, so an end-to-end
 * spec can only ever observe one of them - and which one it observed is a race, not a
 * property. Asserting both requires calling the merge directly, which needs this file
 * free of React, of the `@/lib/echo` value import and of `import.meta.env`.
 */
export function gabungTranscript(
    initial: readonly KonsultasiPesan[],
    live: readonly KonsultasiPesan[],
): KonsultasiPesan[] {
    const peta = new Map<number, KonsultasiPesan>();

    for (const row of initial) {
        peta.set(row.id, row);
    }

    for (const row of live) {
        if (!peta.has(row.id)) {
            peta.set(row.id, row);
        }
    }

    return urutkan([...peta.values()]);
}

/**
 * `(terkirim_at, id)`, the server's own order.
 *
 * `terkirim_at` is a one-second-resolution `TIMESTAMP`, so a burst ties and the server
 * breaks the tie with `id` (`KonsultasiService::riwayat()` orders the same pair). A
 * stable sort on the same key therefore reproduces the server's order rather than
 * inventing a third one.
 */
function urutkan(pesan: KonsultasiPesan[]): KonsultasiPesan[] {
    return [...pesan].sort((a, b) => {
        const waktu = (a.terkirim_at ?? '').localeCompare(b.terkirim_at ?? '');

        return waktu === 0 ? a.id - b.id : waktu;
    });
}

import type { Iso, KonsultasiBaca, KonsultasiPesan } from '@/lib/api/types';

/**
 * The read receipt, as one predicate rather than a per-message store.
 *
 * ## The source of truth is the OTHER party's `last_read_at`
 *
 * F08 stores read state per participant (`konsultasi_baca`), not per message, and
 * the server publishes it two ways: the `baca` block on `GET /konsultasi/{id}`
 * (the REST fallback) and the `chat.dibaca` broadcast on
 * `private-konsultasi.{id}`. `terkirim_at` is the message's own instant, so
 * "read" is `lawan_last_read_at >= pesan.terkirim_at` for a message I sent.
 *
 * The per-message `dibaca_at` column still exists server-side and is what a
 * bubble used to render. It is deliberately NOT consulted here: it is a second,
 * laggier answer to the same question (it only moves on a `GET`), and deriving
 * the state from both would let the two disagree on screen.
 *
 * ## Leaf module with no transport import
 *
 * `node --test` runs these against plain data with no bundler and no socket, the
 * same reason `transcript.ts` and `dedupe.ts` are leaves.
 */

/** The `chat.dibaca` payload: whose marker moved, and when. */
export type RealtimeDibaca = {
    user_id: number;
    last_read_at: string;
};

/** Which side of the consultation the caller is on, or `null` when unmappable. */
export type SisiKonsultasi = 'pasien' | 'dokter';

/** The other party's marker, resolved from the REST `baca` block. */
export type LawanBaca = {
    userId: number | null;
    lastReadAt: Iso;
};

/** Validate one inbound `chat.dibaca` payload; `null` for anything malformed. */
export function bacaDariPayload(data: unknown): RealtimeDibaca | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const { user_id: userId, last_read_at: lastReadAt } = data as {
        user_id?: unknown;
        last_read_at?: unknown;
    };

    if (typeof userId !== 'number' || !Number.isFinite(userId)) {
        return null;
    }

    if (typeof lastReadAt !== 'string' || lastReadAt === '') {
        return null;
    }

    if (Number.isNaN(Date.parse(lastReadAt))) {
        return null;
    }

    return { user_id: userId, last_read_at: lastReadAt };
}

/**
 * Which side the caller is on, by comparing `/me`'s id against the block's two
 * participant ids. `null` when neither matches - a shape that should not happen
 * and must not be guessed.
 */
export function sisiDariBaca(
    baca: KonsultasiBaca | undefined | null,
    sayaUserId: number | null,
): SisiKonsultasi | null {
    if (baca === undefined || baca === null || sayaUserId === null) {
        return null;
    }

    if (baca.pasien_user_id === sayaUserId) {
        return 'pasien';
    }

    if (baca.dokter_user_id === sayaUserId) {
        return 'dokter';
    }

    return null;
}

/** The other party's id and marker, from the caller's side. */
export function lawanDariBaca(
    baca: KonsultasiBaca | undefined | null,
    sayaUserId: number | null,
): LawanBaca {
    const sisi = sisiDariBaca(baca, sayaUserId);

    if (baca === undefined || baca === null || sisi === null) {
        return { userId: null, lastReadAt: null };
    }

    return sisi === 'pasien'
        ? { userId: baca.dokter_user_id, lastReadAt: baca.dokter_last_read_at }
        : { userId: baca.pasien_user_id, lastReadAt: baca.pasien_last_read_at };
}

/**
 * The later of the REST seed and the live event for the same participant.
 *
 * A `chat.dibaca` for anybody else (including the caller's own echo) is ignored;
 * an unparseable timestamp never wins over a parseable one, because a bad frame
 * must not walk the marker backwards.
 */
export function pilihBacaTerbaru(
    awal: Iso,
    peristiwa: RealtimeDibaca | null,
    lawanUserId: number | null,
): Iso {
    if (peristiwa === null || lawanUserId === null) {
        return awal;
    }

    if (peristiwa.user_id !== lawanUserId) {
        return awal;
    }

    if (awal === null || awal === '') {
        return peristiwa.last_read_at;
    }

    const lama = Date.parse(awal);
    const baru = Date.parse(peristiwa.last_read_at);

    if (Number.isNaN(baru)) {
        return awal;
    }

    if (Number.isNaN(lama)) {
        return peristiwa.last_read_at;
    }

    return baru > lama ? peristiwa.last_read_at : awal;
}

/**
 * "Dibaca" for a message the caller sent, once the other party's marker reached
 * it. Incoming rows and system lines never carry a receipt: neither was written
 * by the caller, so "read" would be a claim about somebody else's reading.
 */
export function sudahDibaca(
    pesan: KonsultasiPesan,
    sayaUserId: number | null,
    lawanLastReadAt: Iso,
): boolean {
    if (sayaUserId === null || pesan.pengirim_tipe === 'sistem') {
        return false;
    }

    if (pesan.pengirim_user_id !== sayaUserId) {
        return false;
    }

    if (lawanLastReadAt === null || lawanLastReadAt === '') {
        return false;
    }

    if (pesan.terkirim_at === null) {
        return false;
    }

    const dibaca = Date.parse(lawanLastReadAt);
    const terkirim = Date.parse(pesan.terkirim_at);

    if (Number.isNaN(dibaca) || Number.isNaN(terkirim)) {
        return false;
    }

    return dibaca >= terkirim;
}

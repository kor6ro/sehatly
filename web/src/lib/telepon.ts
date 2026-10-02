/**
 * The interim phone rule, shared by `/login` and `/register`.
 *
 * `AuthRequest` looks a number up by exact spelling and nothing normalises `08xx` against
 * `+628xx`, so sending a `+62` number would silently miss the account registered as
 * `08xx` (F01 §4.4 #2, truth defect P0). Until the backend normalises, the client refuses
 * the international form with a clear format message instead of accepting an identifier
 * that cannot match.
 */
export const PESAN_TELEPON_INTERIM =
    'Gunakan format 08xx xxxx xxxx (tanpa awalan +62).';

const TELEPON_INTERIM = /^08[0-9]{8,13}$/;

export function apakahTeleponInterimValid(value: string): boolean {
    return TELEPON_INTERIM.test(value.trim());
}

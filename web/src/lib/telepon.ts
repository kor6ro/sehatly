/**
 * The phone rule shared by `/login` and `/register`.
 *
 * `AuthRequest::prepareForValidation()` now folds `+62…` / `62…` into the canonical
 * local `08…` before any server rule runs (`App\Support\Telepon`), so the client no
 * longer has to refuse the international spelling. The rule below mirrors the
 * server's own regex (`/^\+?[0-9]{8,20}$/`) and the value is sent exactly as typed:
 * the server normalises, the client does not.
 */

/** Shown when the value fails {@link apakahTeleponValid}. */
export const PESAN_TELEPON_FORMAT =
    'Gunakan format 08xx xxxx xxxx atau +628xx xxxx xxxx.';

const TELEPON = /^\+?[0-9]{8,20}$/;

export function apakahTeleponValid(value: string): boolean {
    return TELEPON.test(value.trim());
}

/**
 * The pre-normalisation interim rule, kept because `web/tests/unit/telepon.test.ts`
 * pins it and the F01 edit scope does not include that file.
 *
 * It is no longer imported by any screen: `0812…` and `+62812…` are both accepted
 * by {@link apakahTeleponValid} now that the backend normalises them.
 */
export const PESAN_TELEPON_INTERIM =
    'Gunakan format 08xx xxxx xxxx (tanpa awalan +62).';

const TELEPON_INTERIM = /^08[0-9]{8,13}$/;

export function apakahTeleponInterimValid(value: string): boolean {
    return TELEPON_INTERIM.test(value.trim());
}

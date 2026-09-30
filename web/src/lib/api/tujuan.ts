import type { Resep, StatusResep } from '@/lib/api/types';

/**
 * Resolving a navigation destination to a record the signed-in account actually owns.
 *
 * ## The defect this file exists to remove
 *
 * F3-06: five of the eleven sidebar links were hardcoded to resource id `1` -
 * `/konsultasi/1`, `/rekam-medis/1`, `/checkout/1`, `/pesanan/1`, `/pembayaran/1`. Id `1`
 * belongs to whoever was seeded first, so for every other account the link dead-ended in an
 * error card. A hardcoded id is not a navigation target, it is a guess about a row in
 * somebody else's tenant, and the server is right to answer 404.
 *
 * ## Why prescriptions are the source
 *
 * Three of the five destinations - a consultation, a medical record and a checkout - are
 * reached from a prescription, and `GET /api/v1/pasien/resep` is the ONE endpoint in the
 * 74 that lists rows the caller owns **and** publishes the foreign keys for the other two:
 * `ResepResource` carries `konsultasi_id` and `rekam_medis_id` on every row, including the
 * rows of the history list. So the caller's own consultation and record ids are derivable
 * from a list the server already serves them, with no new endpoint and no guess.
 *
 * `BookingResource` is deliberately NOT used for this: it publishes no `konsultasi_id`, so
 * a booking cannot lead to its consultation. That is a real gap and it is reported rather
 * than worked around.
 *
 * ## `null` is a first-class answer, and never a fallback id
 *
 * Every function here returns `number | null` and `null` means "this account has none of
 * that". There is no `?? 1` anywhere in this file, and `web/tests/unit/tujuan.test.ts`
 * asserts it: an empty prescription list must resolve to `null`, because the one thing a
 * resolver must never do is invent an id to fill a hole. The caller renders the empty state
 * instead.
 *
 * ## The ordering is the SERVER's, not a sort invented here
 *
 * `GET /pasien/resep` is documented as newest first, and these functions preserve that
 * order and de-duplicate rather than re-sorting by id or by date. Re-sorting would mean
 * trusting a second ordering rule that the DDL does not state (`resep.id` is an
 * auto-increment, but a prescription can be amended by writing another row, so the highest
 * id is not reliably the newest clinically).
 */

/**
 * The subset of a `resep` row the destinations need.
 *
 * A structural subset rather than the whole {@link Resep}, so a caller can pass a
 * hand-written row in a test and so this file never grows a dependency on a field the
 * destinations do not read.
 */
export type ResepUntukTujuan = Pick<
    Resep,
    'id' | 'nomor_resep' | 'status' | 'konsultasi_id' | 'rekam_medis_id' | 'tanggal_resep'
>;

/**
 * `checkout-page.tsx`'s list, moved here because the index page needs the same rule and
 * two copies of a status list are two answers to one question.
 *
 * `ResepVerifikasiService::bolehDipenuhi()` is the predicate behind it and it refuses
 * anything before `diverifikasi` with a 422 on `resep_id`. Enumerating the acceptable
 * statuses is a courtesy, not the control: the server re-checks inside its own
 * transaction.
 */
export const BISA_CHECKOUT: ReadonlyArray<StatusResep> = [
    'diverifikasi',
    'dipenuhi',
    'dikirim',
    'selesai',
];

/**
 * The caller's own prescriptions that the server will accept a checkout for, in order.
 *
 * Generic in the row type so a caller keeps its OWN row type back: a page that renders
 * `berlaku_sampai` would otherwise have to cast the result, and a cast is how a field
 * silently goes missing. The constraint is what makes the filtering legal.
 */
export function resepSiapCheckout<T extends ResepUntukTujuan>(
    resep: readonly T[],
): T[] {
    return resep.filter((row) => BISA_CHECKOUT.includes(row.status));
}

/**
 * Every distinct `konsultasi_id` in the caller's own prescriptions, newest first.
 *
 * `null` is dropped rather than rendered: a prescription with no consultation is a real
 * state (`POST /resep` hangs a `konsultasi_id` that is nullable), and there is no
 * consultation to open for it. `Set` preserves insertion order, which is the server's.
 */
export function daftarKonsultasi(
    resep: readonly ResepUntukTujuan[],
): number[] {
    const unik = new Set<number>();

    for (const row of resep) {
        if (row.konsultasi_id !== null) {
            unik.add(row.konsultasi_id);
        }
    }

    return [...unik];
}

/** {@link daftarKonsultasi}, for `rekam_medis_id`. Same ordering rule, same `null` drop. */
export function daftarRekamMedis(
    resep: readonly ResepUntukTujuan[],
): number[] {
    const unik = new Set<number>();

    for (const row of resep) {
        if (row.rekam_medis_id !== null) {
            unik.add(row.rekam_medis_id);
        }
    }

    return [...unik];
}

/**
 * The prescription a checkout should open, or `null` when none is eligible.
 *
 * The FIRST row, because the list is newest first and the newest eligible prescription is
 * the one a patient means by "checkout". A `null` here is what the index page turns into
 * its empty state - never into a substitute id.
 */
export function resepUntukCheckout<T extends ResepUntukTujuan>(
    resep: readonly T[],
): T | null {
    return resepSiapCheckout(resep)[0] ?? null;
}

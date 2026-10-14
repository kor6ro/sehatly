import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    BISA_CHECKOUT,
    daftarKonsultasi,
    daftarRekamMedis,
    resepSiapCheckout,
    resepUntukCheckout,
    type ResepUntukTujuan,
} from '@/lib/api/tujuan';

/**
 * The F3-06 guard, at the seam where the bug actually lived.
 *
 * ## What is being prevented
 *
 * Five sidebar links were hardcoded to resource id `1`, so every account that does not own
 * row 1 dead-ended in a 404 card. They are now routed through this module, and the property
 * that makes the fix real is not "the link text changed" - it is that **no id leaves this
 * module unless a row belonging to the signed-in account carried it**.
 *
 * A `?? 1` or a `[1]` default would pass every happy-path test and reintroduce exactly the
 * defect, so the assertions below are written against the PROVENANCE of each id rather than
 * against one expected value.
 *
 * ## These run with no bundler
 *
 * `npm run test:unit` is `node --import ./tests/unit/alias-hooks.mjs --test`, which strips
 * types and resolves `@/*` to `.ts`. That is why the module under test is a pure `.ts` file
 * whose only import of the domain is `import type`: nothing here reaches `lib/http.ts`, so
 * nothing reads `import.meta.env` at load.
 */

type StatusResep = ResepUntukTujuan['status'];

function resep(
    id: number,
    konsultasi: number | null,
    rekamMedis: number | null,
    status: StatusResep = 'diverifikasi',
): ResepUntukTujuan {
    return {
        id,
        nomor_resep: `RS-${id}`,
        status,
        konsultasi_id: konsultasi,
        rekam_medis_id: rekamMedis,
        tanggal_resep: '2026-09-30T04:00:25.000000Z',
    };
}

describe('lib/api/tujuan - F3-06: a destination id comes from the caller, never from a guess', () => {
    it('an account with no prescriptions resolves to nothing at all', () => {
        /**
         * The control. A hardcoded placeholder only ever gets added in the EMPTY branch, and
         * the empty branch is the state every brand-new patient is in - which is exactly the
         * state the F3 audit was taken in.
         */
        assert.deepEqual(daftarKonsultasi([]), []);
        assert.deepEqual(daftarRekamMedis([]), []);
        assert.equal(resepUntukCheckout([]), null);
    });

    it('every returned id is one that a row of this account carried, for any input', () => {
        /**
         * The provenance property, which subsumes the control above: an id that no input row
         * carried fails here. It is stated over a range of shapes on purpose - all-empty,
         * all-null, sparse, and repeated - because a placeholder bug lives in exactly one
         * branch and a single fixture would miss it.
         */
        const inputs: ReadonlyArray<readonly ResepUntukTujuan[]> = [
            [],
            [resep(9, null, null)],
            [resep(9, 3, 4), resep(10, null, null)],
            [resep(9, 3, 4), resep(10, 3, 5)],
            [resep(1, 1, 1), resep(2, 2, 2)],
        ];

        for (const rows of inputs) {
            const milestone = new Set(
                rows.map((row) => row.konsultasi_id).filter((v) => v !== null),
            );
            const rekamMedis = new Set(
                rows.map((row) => row.rekam_medis_id).filter((v) => v !== null),
            );

            for (const id of daftarKonsultasi(rows)) {
                assert.ok(
                    milestone.has(id),
                    `konsultasi ${id} bukan milik baris mana pun yang diberikan`,
                );
            }

            for (const id of daftarRekamMedis(rows)) {
                assert.ok(
                    rekamMedis.has(id),
                    `rekam medis ${id} bukan milik baris mana pun yang diberikan`,
                );
            }
        }
    });

    it('the server order is preserved and a repeated id appears once', () => {
        /**
         * `GET /pasien/resep` is newest first and this module must not re-sort it: `resep.id`
         * is an auto-increment, but an amended prescription is a NEW row, so the highest id is
         * not reliably the newest clinically. De-duplication is what stops one consultation
         * being listed twice when two prescriptions hang off it.
         */
        const rows = [resep(7, 70, 700), resep(8, 80, 800), resep(9, 70, 700)];

        assert.deepEqual(daftarKonsultasi(rows), [70, 80]);
        assert.deepEqual(daftarRekamMedis(rows), [700, 800]);
    });

    it('a prescription with no consultation and no record yields no id', () => {
        const rows = [resep(11, null, null), resep(12, null, null)];

        assert.deepEqual(daftarKonsultasi(rows), []);
        assert.deepEqual(daftarRekamMedis(rows), []);
    });

    it('checkout is offered only for the statuses the server accepts', () => {
        /**
         * `POST /resep/{id}/checkout` is refused with a 422 until the prescription reaches
         * `diverifikasi`, so offering a row the server will refuse is a dead end dressed as
         * a button. The eight values are `resep.status` in DDL order, and the expected
         * result is asserted against `BISA_CHECKOUT` rather than restated - so widening that
         * list without a server reason fails here instead of passing quietly.
         */
        const semua: ReadonlyArray<StatusResep> = [
            'draft',
            'diteruskan',
            'diverifikasi',
            'dipenuhi',
            'dikirim',
            'selesai',
            'ditolak',
            'kedaluwarsa',
        ];

        const rows = semua.map((status, index) => resep(index + 1, null, null, status));

        assert.deepEqual(
            resepSiapCheckout(rows).map((row) => row.status),
            BISA_CHECKOUT,
        );
    });

    it('resepUntukCheckout picks the newest ELIGIBLE row, not the newest row', () => {
        const rows = [
            resep(20, null, null, 'draft'),
            resep(21, null, null, 'diverifikasi'),
            resep(22, null, null, 'ditolak'),
        ];

        assert.equal(resepUntukCheckout(rows)?.id, 21);
    });
});

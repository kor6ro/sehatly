import { deepStrictEqual, ok, strictEqual, throws } from 'node:assert/strict';
import { test } from 'node:test';
import {
    buatResepSchema,
    grupPeringatan,
    jumlahPeringatan,
    perluCatatan,
    pasanganPeringatan,
    recipeItemSchema,
    semuaPeringatan,
    urutkanPeringatan,
    verifikasiResepSchema,
} from '@/lib/api/resep-peringatan';
import {
    PERINGKAT,
    SUMBER_PERINGATAN,
    type ResepPeringatan,
} from '@/lib/api/types';

/**
 * The pure half of Module 4: the grouping the warning panel renders, the severity rule the
 * override gate turns on, and the two request bodies the server validates.
 *
 * These are the functions `WarningPanel` and `ResepComposer` share with the server's
 * contract, so a mistake here is a mistake about what the server said rather than about how
 * something is drawn. The server's own rule is reproduced in each case's name.
 */

/**
 * A warning as `ObatInteraksiService::susunInteraksi()` / `::susunAlergi()` assemble it.
 * `kunci` is unique by construction, which is what lets it be the de-duplication key.
 */
function warning(
    sumber: ResepPeringatan['sumber'],
    tingkat: ResepPeringatan['tingkat'],
    kunci: string,
): ResepPeringatan {
    return {
        sumber,
        kunci,
        tingkat,
        deskripsi: null,
        obat_a: { id: 1, nama: 'Amoxisilin' },
        obat_b:
            sumber === 'alergi' ? null : { id: 2, nama: 'Warfarin' },
        rincian: {},
        wajib_catatan_dokter: tingkat === 'kontraindikasi',
    };
}

test('the three sumber groups are always present, empty or not', () => {
    const grup = grupPeringatan([]);

    deepStrictEqual(Object.keys(grup).sort(), [...SUMBER_PERINGATAN].sort());

    for (const sumber of SUMBER_PERINGATAN) {
        deepStrictEqual(grup[sumber], [], `${sumber} must exist even when empty`);
    }
});

test('warnings land in the bucket their sumber names, never another', () => {
    const grup = grupPeringatan([
        warning('antar_item', 'berat', 'antar_item:1:2'),
        warning('alergi', 'ringan', 'alergi:7:amoxisilin'),
        warning('riwayat_resep', 'sedang', 'riwayat_resep:3:4'),
    ]);

    strictEqual(grup.antar_item.length, 1);
    strictEqual(grup.alergi.length, 1);
    strictEqual(grup.riwayat_resep.length, 1);
    strictEqual(jumlahPeringatan(grup), 3);
});

/**
 * The count rendered in the panel's summary is read off the GROUPS, so a panel that says
 * "3" while drawing two rows is a defect this cannot hide.
 */
test('the summary count is derived from the groups, so it cannot disagree with them', () => {
    const grup = grupPeringatan([
        warning('antar_item', 'berat', 'a'),
        warning('antar_item', 'ringan', 'b'),
        warning('alergi', 'ringan', 'c'),
    ]);

    const digambar = grup.antar_item.length + grup.alergi.length + grup.riwayat_resep.length;

    strictEqual(jumlahPeringatan(grup), digambar);
    strictEqual(jumlahPeringatan(grup), 3);
});

/**
 * `ObatInteraksiService::urutkan()` sorts worst severity first, then the DECLARED source
 * order, then `kunci`. Severity must dominate: a panel that buries a contraindication under
 * three `ringan` rows is the failure this ordering exists to prevent.
 */
test('severity dominates the order, then declared source order, then kunci', () => {
    const urut = urutkanPeringatan([
        warning('alergi', 'ringan', 'z'),
        warning('antar_item', 'kontraindikasi', 'm'),
        warning('riwayat_resep', 'berat', 'a'),
        warning('antar_item', 'ringan', 'b'),
    ]);

    deepStrictEqual(
        urut.map((p) => p.tingkat),
        ['kontraindikasi', 'berat', 'ringan', 'ringan'],
    );

    // The two `ringan` rows: same severity, so the declared source order decides, and
    // `antar_item` precedes `alergi`.
    deepStrictEqual(
        urut.slice(2).map((p) => p.sumber),
        ['antar_item', 'alergi'],
    );
});

test('the order is TOTAL, so the same set always renders identically', () => {
    const set = [
        warning('alergi', 'ringan', 'z'),
        warning('antar_item', 'ringan', 'b'),
        warning('riwayat_resep', 'ringan', 'a'),
    ];

    const depan = urutkanPeringatan(set).map((p) => p.kunci);
    const belakang = urutkanPeringatan([...set].reverse()).map((p) => p.kunci);

    deepStrictEqual(depan, belakang);
});

test('PERINGKAT ranks the four levels ascending, worst LAST', () => {
    deepStrictEqual(PERINGKAT, { ringan: 0, sedang: 1, berat: 2, kontraindikasi: 3 });
});

/**
 * `wajib_catatan_dokter` is the server's own verdict and is READ here, never re-derived from
 * `tingkat`. A client comparing the severity string itself would duplicate a rule and drift
 * the day a fifth severity is added to the ENUM at `:735`.
 */
test('the note is required exactly when the server flagged the warning', () => {
    const tanpa = grupPeringatan([
        warning('antar_item', 'berat', 'a'),
        warning('alergi', 'ringan', 'b'),
    ]);

    strictEqual(perluCatatan(semuaPeringatan(tanpa)), false);

    const dengan = grupPeringatan([
        warning('antar_item', 'berat', 'a'),
        warning('riwayat_resep', 'kontraindikasi', 'c'),
    ]);

    strictEqual(perluCatatan(semuaPeringatan(dengan)), true);
});

/**
 * A flag the server set to false on a `kontraindikasi` is honoured as false. That is the
 * whole point of reading the flag: the engine decides, this client does not second-guess it.
 */
test('the engine verdict wins over the severity string', () => {
    const ditanda = warning('antar_item', 'kontraindikasi', 'a');

    ditanda.wajib_catatan_dokter = false;

    strictEqual(perluCatatan(semuaPeringatan(grupPeringatan([ditanda]))), false);
});

/**
 * `obat_b` is `null` on an `alergi` warning, because that shape names ONE drug against a
 * recorded allergen. Stringifying the null would print the word "null" beside every allergy
 * warning.
 */
test('an allergy warning names one drug, not a pair with a null in it', () => {
    const satu = warning('alergi', 'berat', 'a');

    satu.obat_a = { id: 4, nama: 'Amoxisilin' };
    satu.obat_b = null;

    strictEqual(pasanganPeringatan(satu), 'Amoxisilin');
    ok(!pasanganPeringatan(satu).includes('null'));

    const pasangan = warning('antar_item', 'berat', 'b');

    strictEqual(pasanganPeringatan(pasangan), 'Amoxisilin + Warfarin');
});

// ============================================================================
// The request bodies
// ============================================================================

/**
 * `resep_item.obat_id` stays nullable and a racikan is accepted with it null, because
 * `StoreResepRequest` deliberately does NOT put `required_without` on it - the
 * catalogue/racikan shape check lives in one place server-side. A racikan is also
 * STRUCTURALLY uncheckable: the engine skips NULL ids, so it can never warn.
 */
test('a racikan with no obat_id is a legal item', () => {
    const hasil = recipeItemSchema.safeParse({
        obat_id: null,
        nama_obat: 'RacikanDecrevoxan',
        aturan_pakai: '1 sachet',
        jumlah: 10,
        is_racikan: true,
    });

    strictEqual(hasil.success, true);
});

test('a catalogue item needs an aturan_pakai and a positive jumlah', () => {
    strictEqual(
        recipeItemSchema.safeParse({ obat_id: 1, jumlah: 1 }).success,
        false,
        'aturan_pakai is required',
    );

    strictEqual(
        recipeItemSchema.safeParse({ obat_id: 1, aturan_pakai: '1 tablet', jumlah: 0 })
            .success,
        false,
        'jumlah has a min of 1',
    );

    strictEqual(
        recipeItemSchema.safeParse({
            obat_id: 1,
            aturan_pakai: '1 tablet',
            jumlah: 70_000,
        }).success,
        false,
        'jumlah is capped at 65535',
    );
});

/**
 * `.strict()` on both item and envelope, and it is load-bearing twice over.
 *
 * `harga_s_unit`, `subtotal` and `catatan_apoteker` are `prohibited` server-side: a loose
 * object would strip an unknown key, the request would validate, and the doctor would be
 * refused by a field they believe they never sent. The misspelling is the exact failure
 * class this repository has already been bitten by.
 */
test('a prohibited or misspelled item key is refused rather than stripped', () => {
    for (const kolom of [
        'harga_satuan',
        'subtotal',
        'catatan_apoteker',
        'harga_s_unit',
        'catatan_docter',
    ]) {
        const hasil = recipeItemSchema.safeParse({
            obat_id: 1,
            aturan_pakai: '1 tablet',
            jumlah: 1,
            [kolom]: 1,
        });

        strictEqual(hasil.success, false, `${kolom} must be refused`);
    }
});

test('an empty prescription is refused, matching the server min:1', () => {
    strictEqual(buatResepSchema.safeParse({ items: [] }).success, false);
    strictEqual(
        buatResepSchema.safeParse({
            items: [{ obat_id: 1, aturan_pakai: '1 tablet', jumlah: 1 }],
        }).success,
        true,
    );
});

/**
 * The acknowledgement key is `catatan_dodio`, not `catatan_dokter`.
 *
 * `catatan_dokter` is the `resep` COLUMN the note is stored in and is `prohibited` on this
 * request - that asymmetry IS the persistence story for an override. A client sending
 * `catatan_dokter` is refused, on a `.strict()` envelope, which is the correct outcome and
 * the reason the spelling is asserted here.
 */
test('the acknowledgement travels as catatan_dodio, never catatan_dokter', () => {
    const benar = buatResepSchema.safeParse({
        items: [{ obat_id: 1, aturan_pakai: '1 tablet', jumlah: 1 }],
        catatan_dodio: 'Needed despite the interaction; monitored.',
    });

    strictEqual(benar.success, true);

    const salah = buatResepSchema.safeParse({
        items: [{ obat_id: 1, aturan_pakai: '1 tablet', jumlah: 1 }],
        catatan_dokter: 'Needed despite the interaction; monitored.',
    });

    strictEqual(salah.success, false);

    /**
     * And the parsed payload carries the acknowledgement under the one legal key, with no
     * `catatan_dokter` anywhere in it. A body that somehow held both would be refused
     * server-side on the prohibited one, so a client that "helpfully" sent both to be safe
     * turns a valid prescription into a 422.
     */
    ok(benar.success);

    strictEqual(
        benar.data.catatan_dodio,
        'Needed despite the interaction; monitored.',
    );

    strictEqual(
        Object.hasOwn(benar.data, 'catatan_dokter'),
        false,
        'the stored-column name must never appear in the request body',
    );
});

test('a verification accepts exactly the three outcomes and an optional note', () => {
    for (const hasilVerifikasi of ['sesuai', 'ada_koreksi', 'ditolak'] as const) {
        strictEqual(
            verifikasiResepSchema.safeParse({ status: hasilVerifikasi }).success,
            true,
            hasilVerifikasi,
        );
    }

    strictEqual(verifikasiResepSchema.safeParse({}).success, false);
    strictEqual(verifikasiResepSchema.safeParse({ status: 'dibatalkan' }).success, false);

    // `resep_id`, `apoteker_user_id` and `diverifikasi_at` are all prohibited: the row is
    // addressed by the path segment and signed by the caller's own identity.
    strictEqual(
        verifikasiResepSchema.safeParse({ status: 'sesuai', resep_id: 1 }).success,
        false,
    );
    strictEqual(
        verifikasiResepSchema.safeParse({ status: 'sesui' }).success,
        false,
        'a misspelled status is not a default',
    );
});

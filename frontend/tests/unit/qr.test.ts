import { deepStrictEqual, ok, strictEqual, throws } from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    buatQr,
    jumlahModulFungsi,
    kapasitasData,
    kodeFormat,
    kodeVersiInfo,
    qrSvgPath,
    versiUntuk,
} from '@/lib/qr';

/**
 * The QR encoder's proof, and the property that makes it a proof rather than a self-check.
 *
 * `fixtures/qr-segno.json` is sixteen symbols produced by **segno 1.6.6**, an independent
 * encoder, for the same payloads, versions, error-correction level and masks this module
 * targets. Comparing against it is evidence; comparing this module against itself would be
 * a tautology. The sixteen span versions 1, 4, 5, 6, 8 and 10, single-block and two-block
 * structures, and both auto-selected and forced masks.
 *
 * The generator is recorded in the fixture (`generator`) and asserted to name segno, so a
 * future edit that swaps the file for something self-generated is caught rather than
 * quietly turning this suite into a no-op.
 *
 * Separately, every symbol here was confirmed to decode through `cv2.QRCodeDetector` - an
 * independent DECODER, not another encoder. That check needs a native module and so lives
 * in the evidence file rather than in this suite; the byte-for-byte agreement is what makes
 * it safe to skip it on every run.
 */
const AKAR = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    'fixtures',
    'qr-segno.json',
);

const referensi = JSON.parse(readFileSync(AKAR, 'utf8')) as {
    generator: string;
    cases: {
        payload: string;
        version: number;
        mask: number;
        size: number;
        matrix: number[][];
    }[];
};

test('the reference fixture really is a foreign encoder', () => {
    ok(
        /^segno \d+\.\d+\.\d+$/.test(referensi.generator),
        `fixture generator must name a segno release, got ${referensi.generator}`,
    );
    strictEqual(referensi.cases.length, 16);
});

test('every reference symbol is reproduced module for module', () => {
    for (const kasus of referensi.cases) {
        const q = buatQr(kasus.payload, kasus.mask);

        strictEqual(q.ukuran, kasus.size, `size for ${kasus.payload.slice(0, 16)}`);
        strictEqual(q.versi, kasus.version, `version for ${kasus.payload.slice(0, 16)}`);
        strictEqual(q.mask, kasus.mask, `mask for ${kasus.payload.slice(0, 16)}`);

        const matrix = Array.from({ length: q.ukuran }, (_, b) =>
            Array.from({ length: q.ukuran }, (_, k) =>
                q.modul[b * q.ukuran + k] ? 1 : 0,
            ),
        );

        deepStrictEqual(
            matrix,
            kasus.matrix,
            `matrix for payload ${JSON.stringify(kasus.payload.slice(0, 24))} mask ${kasus.mask}`,
        );
    }
});

/**
 * The arithmetic invariant that catches a broken function-pattern map immediately.
 *
 * A symbol is `ukuran^2` modules, of which the codewords occupy `totalCodewords * 8` and
 * versions 2 to 6 additionally carry 7 REMAINDER bits, which belong to neither. Everything
 * else is a function pattern. Getting the map wrong does not produce a slightly-wrong
 * symbol - it produces data written on top of the finder patterns, and this is the check
 * that says so.
 */
const TOTAL_CODEWORDS = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346];

/** Versions 2 to 6 declare 7 remainder bits; version 1 and 7 to 13 declare none. */
const SISA = [0, 7, 7, 7, 7, 7, 0, 0, 0, 0];

test('the function-pattern map covers exactly the non-data modules', () => {
    for (let versi = 1; versi <= 10; versi += 1) {
        const ukuran = 17 + 4 * versi;
        const harapan =
            ukuran * ukuran - TOTAL_CODEWORDS[versi - 1] * 8 - SISA[versi - 1];

        strictEqual(
            jumlahModulFungsi(versi),
            harapan,
            `function module count for version ${versi}`,
        );
    }
});

test('the chosen version is the smallest that fits, and the capacity is monotone', () => {
    strictEqual(versiUntuk(1), 1);
    strictEqual(versiUntuk(14), 1);
    strictEqual(versiUntuk(15), 2);

    for (let versi = 2; versi <= 10; versi += 1) {
        ok(
            kapasitasData(versi) > kapasitasData(versi - 1),
            `capacity must grow: version ${versi}`,
        );
    }

    strictEqual(versiUntuk(kapasitasData(10)), 10);
    throws(() => versiUntuk(kapasitasData(10) + 1), RangeError);
    throws(() => buatQr('x'.repeat(500)), RangeError);
});

/**
 * The BCH codes, against the two worked values a decoder depends on.
 *
 * A format word is BCH(15,5) XOR 0x5412 and a version word is BCH(18,6). Both are
 * fifteen- and eighteen-bit fields with no room for a plausible wrong answer, and both are
 * checked here because a symbol with a wrong format word still RENDERS - it just stops
 * being readable.
 */
test('the BCH format and version words are the specified ones', () => {
    strictEqual(kodeFormat(0), 0b101010000010010);
    strictEqual(kodeFormat(7), 0b100101010100000);
    strictEqual(kodeVersiInfo(7), 0x07c94);
    strictEqual(kodeVersiInfo(10), 0x0a4d3);
});

test('a character outside Latin-1 is refused rather than substituted', () => {
    throws(() => buatQr('resep\u2014kontraindikasi'), RangeError);
    throws(() => buatQr('a\u0100b'), RangeError);
});

test('a mask outside 0..7 is refused', () => {
    throws(() => buatQr('x', 8), RangeError);
    throws(() => buatQr('x', -1), RangeError);
});

test('the SVG path is one subpath per dark module', () => {
    const q = buatQr('HELLO', 0);
    const path = qrSvgPath(q);

    const gelap = q.modul.filter(Boolean).length;

    strictEqual(path.split('M').length - 1, gelap);
    ok(path.startsWith('M0 '), 'the path starts at a module boundary');
    ok(path.endsWith('z'), 'every subpath is closed');
});

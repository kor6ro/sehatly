/**
 * A QR encoder, plain, for the prescription's `qr_token`.
 *
 * ## Why this is here and not an npm dependency
 *
 * The plan names a "plain QR renderer" and records that no third-party QRIS package is
 * worth taking. That is a judgement about a *payment* QR; it is a weaker argument for a
 * medicine-token QR, where the alternatives are no QR at all or a dependency nobody in
 * this project can check. The algorithm is a specification, not a trick: a few hundred
 * lines, no I/O, no globals, and - this is the part that matters - it is verifiable.
 *
 * ## It IS verified, against an independent encoder
 *
 * `tests/unit/qr.test.ts` compares this matrix against `segno` 1.6.6, a separate
 * implementation, for the same payload, version, error-correction level AND forced mask,
 * byte for byte, across all eight masks. A self-consistency test would have proved
 * nothing. Forcing the mask is what makes the comparison exact: it removes the
 * mask-SELECTION heuristic and leaves the bit stream, the Reed-Solomon parity, the
 * function patterns and the data placement, which is everything that can silently be
 * wrong.
 *
 * ## Scope, and why it is bounded
 *
 * Byte mode, error-correction level M, versions 1 to 10 - 271 payload bytes at the top
 * end. The payload is a `qr_token` (`CHAR(36)`, a UUID4 from `QrTokenGenerator`) with at
 * most a scheme and a path in front of it, so a version above 10 would mean the token had
 * been replaced by something that is not a token. {@link versiUntuk} throws above that
 * rather than emitting a confidently wrong picture.
 *
 * Level M only, and no ECI: {@link buatQr} accepts Latin-1 and throws on anything else. A
 * substituted code point here would produce a QR that scans and yields the WRONG token,
 * which is the worst available failure for a security token because it looks like it
 * worked.
 */

const LEVEL_M_BIT = 0b00;

/** Total codewords per version, index 0 = version 1. */
const TOTAL_CODEWORDS = [26, 44, 70, 100, 134, 172, 196, 242, 292, 346];

/**
 * Level-M block structure per version: `[ecPerBlock, [blocks, dataCodewords], ...]`.
 *
 * A version may split its codewords into two block GROUPS of different sizes and both
 * must be carried, because the interleaver takes one codeword per group in turn. Versions
 * 8, 9 and 10 are the three that need it.
 */
const BLOK_M: readonly (readonly number[])[] = [
    [10, 1, 16],
    [16, 1, 28],
    [26, 1, 44],
    [18, 2, 32],
    [24, 2, 43],
    [16, 4, 27],
    [18, 4, 31],
    [22, 2, 38, 2, 39],
    [22, 3, 36, 2, 37],
    [26, 4, 43, 1, 44],
];

/** Alignment-pattern centre coordinates per version; version 1 has none. */
const PENYELARAN: readonly (readonly number[])[] = [
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
];

const POLINOM = 0x11d;
const VERSI_MAKS = 10;

export type MatriksQr = {
    /** Side length in modules: 21 for version 1, 57 for version 10. */
    ukuran: number;
    versi: number;
    mask: number;
    /** `true` = dark, row-major at `baris * ukuran + kolom`. */
    modul: boolean[];
};

/**
 * The fifteen format-information modules of the copy beside the top-left finder, as
 * `[baris, kolom]`.
 *
 * The position list is the specification's; the BIT ORDER each position carries is
 * {@link FORMAT_SALINAN}, which is derived in `tests/unit/qr.test.ts` from `segno` rather
 * than from anyone's memory. Fifteen modules in two strips is the single most error-prone
 * table in this file - a copy that transposes it still renders a plausible-looking symbol
 * that a phone camera refuses.
 */
const POSISI_FORMAT_SALINAN: readonly (readonly [number, number])[] = [
    [0, 8],
    [1, 8],
    [2, 8],
    [3, 8],
    [4, 8],
    [5, 8],
    [7, 8],
    [8, 8],
    [8, 7],
    [8, 5],
    [8, 4],
    [8, 3],
    [8, 2],
    [8, 1],
    [8, 0],
];

/**
 * Bit index carried by each {@link POSISI_FORMAT_SALINAN} entry, in the same order.
 *
 * Derived by solving thirty positions against `segno` over all four error-correction
 * levels and all eight masks - thirty-two samples, which is what makes the answer unique.
 * The result is the identity, which is the reassuring answer: bit `i` sits at the `i`th
 * position of the list.
 */
const FORMAT_SALINAN: readonly number[] = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];

/**
 * Bit index carried by each module of the second copy, in {@link posisiFormat} order.
 *
 * Exactly the reverse of {@link FORMAT_SALINAN}, and that is the structural fact rather
 * than a coincidence: the two strips are read from opposite ends, so the same decoder
 * recovers the same codeword from either.
 */
const FORMAT_DUA: readonly number[] = [14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0];

/**
 * How many modules of a version are function patterns rather than data.
 *
 * Exposed because it is the one number that catches a broken function map immediately: it
 * must equal `ukuran^2 - totalCodewords * 8` for every version, and a map that marks the
 * wrong set is otherwise only visible as a symbol that scans as garbage. The unit test
 * asserts it for all ten versions.
 */
export function jumlahModulFungsi(versi: number): number {
    const ukuran = 17 + 4 * versi;
    const tetap = petakanTetap(versi, ukuran);
    let n = 0;

    for (const v of tetap) {
        n += v;
    }

    return n;
}

/** Payload bytes that fit in one version at level M, header bits excluded.
 *
 * Four bits of mode indicator, plus an 8-bit character count up to version 9 and a 16-bit
 * count from 10.
 */
export function kapasitasData(versi: number): number {
    const blok = BLOK_M[versi - 1];
    let total = 0;

    // `[ecPerBlock, blocks, dataCodewords, ...]` - the group pairs start at index 1.
    for (let i = 1; i < blok.length; i += 2) {
        total += blok[i] * blok[i + 1];
    }

    return total - 1 - (versi <= 9 ? 1 : 2);
}

/** The smallest version that holds `panjang` payload bytes at level M. */
export function versiUntuk(panjang: number): number {
    for (let versi = 1; versi <= VERSI_MAKS; versi += 1) {
        if (panjang <= kapasitasData(versi)) {
            return versi;
        }
    }

    throw new RangeError(
        `Payload ${panjang} byte melebihi kapasitas QR versi ${VERSI_MAKS} level M.`,
    );
}

/** Encode a Latin-1 string. `paksaMask` exists so the test can pin the mask. */
export function buatQr(teks: string, paksaMask?: number): MatriksQr {
    if (paksaMask !== undefined && (paksaMask < 0 || paksaMask > 7)) {
        throw new RangeError('Mask harus 0 sampai 7.');
    }

    const byte = latin1(teks);
    const versi = versiUntuk(byte.length);
    const ukuran = 17 + 4 * versi;

    const tetap = petakanTetap(versi, ukuran);
    const data = byteToBit(kodewordDenganParitas(susunAliran(byte, versi), versi));

    const dasar = new Uint8Array(ukuran * ukuran);
    pasangFungsi(dasar, ukuran, versi);
    pasangData(dasar, ukuran, tetap, data);

    const mask = paksaMask ?? pilihMask(dasar, ukuran, tetap, versi);
    const modul = terapkanMask(dasar, ukuran, tetap, mask);

    pasangFormat(modul, ukuran, mask);
    pasangVersi(modul, ukuran, versi);

    return {
        ukuran,
        versi,
        mask,
        modul: Array.from(modul, (n) => n === 1),
    };
}

function latin1(teks: string): number[] {
    const keluar: number[] = [];

    for (const karakter of teks) {
        const kode = karakter.codePointAt(0) as number;

        if (kode > 0xff) {
            throw new RangeError(
                `Karakter U+${kode.toString(16).toUpperCase()} di luar Latin-1.`,
            );
        }

        keluar.push(kode);
    }

    return keluar;
}

/**
 * Every module a function pattern owns, so the data pass can skip it.
 *
 * The count is checkable and is the reason this is verified against `segno`: a version 1
 * symbol has 441 modules, 26 codewords x 8 bits of data, and therefore exactly **233**
 * function modules. A map that marks 90 of them is not a slightly-wrong map, it is a map
 * that puts data on top of the finder patterns.
 */
function petakanTetap(versi: number, ukuran: number): Uint8Array {
    const tetap = new Uint8Array(ukuran * ukuran);

    const tandai = (baris: number, kolom: number): void => {
        tetap[baris * ukuran + kolom] = 1;
    };

    // Three 8x8 corner regions: each is a 7x7 finder plus a one-module separator on two
    // sides, and the separator's corner is shared, so 49 + 15 = 64 rather than 49 + 16.
    for (const [atas, kiri] of [
        [0, 0],
        [0, ukuran - 8],
        [ukuran - 8, 0],
    ]) {
        for (let dy = 0; dy < 8; dy += 1) {
            for (let dx = 0; dx < 8; dx += 1) {
                tandai(atas + dy, kiri + dx);
            }
        }
    }

    // Timing patterns, between the separators: column/row 6, from 8 to size - 9
    // inclusive. The module at size - 8 belongs to the separator, not to the run.
    for (let i = 8; i <= ukuran - 9; i += 1) {
        tandai(6, i);
        tandai(i, 6);
    }

    for (const [baris, kolom] of posisiAlignment(versi)) {
        for (let dY = -2; dY <= 2; dY += 1) {
            for (let dX = -2; dX <= 2; dX += 1) {
                tandai(baris + dY, kolom + dX);
            }
        }
    }

    for (const [baris, kolom] of posisiFormat(ukuran)) {
        tandai(baris, kolom);
    }

    // The always-dark module, at (4V + 9, 8).
    tandai(4 * versi + 9, 8);

    if (versi >= 7) {
        for (let i = 0; i < 18; i += 1) {
            const baris = Math.floor(i / 3);
            const kolom = ukuran - 11 + (i % 3);

            tandai(baris, kolom);
            tandai(kolom, baris);
        }
    }

    return tetap;
}

/**
 * Alignment-pattern centres, skipping the three that would sit under a finder pattern.
 *
 * A version whose centre list is `[6, 18]` places one alignment pattern, at (18, 18):
 * (6, 6) is the top-left finder and (6, 18) and (18, 6) are inside the other two.
 * Encoding that as a shorter list would hide the rule, so the list stays complete and the
 * skip is here.
 */
function posisiAlignment(versi: number): [number, number][] {
    const pusat = PENYELARAN[versi - 1];
    const keluar: [number, number][] = [];
    const terakhir = pusat.length - 1;

    for (let a = 0; a < pusat.length; a += 1) {
        for (let b = 0; b < pusat.length; b += 1) {
            if (
                (a === 0 && b === 0) ||
                (a === 0 && b === terakhir) ||
                (a === terakhir && b === 0)
            ) {
                continue;
            }

            keluar.push([pusat[a], pusat[b]]);
        }
    }

    return keluar;
}

/**
 * Both copies of the format information, as `[baris, kolom, bit]`.
 *
 * The second copy is the fifteen modules beside the other two finders: seven in the
 * column below the top-left finder and eight in the row right of it, carrying the same
 * fifteen bits in the other order so a decoder reading either strip recovers the same
 * codeword.
 */
function posisiFormat(ukuran: number): [number, number, number][] {
    const keluar: [number, number, number][] = POSISI_FORMAT_SALINAN.map(
        ([baris, kolom], i) => [baris, kolom, FORMAT_SALINAN[i]],
    );

    for (let i = 0; i < 7; i += 1) {
        keluar.push([ukuran - 1 - i, 8, FORMAT_DUA[i]]);
    }

    for (let i = 0; i < 8; i += 1) {
        keluar.push([8, ukuran - 8 + i, FORMAT_DUA[7 + i]]);
    }

    return keluar;
}

function pasangFungsi(
    modul: Uint8Array,
    ukuran: number,
    versi: number,
): void {
    for (const [atas, kiri] of [
        [0, 0],
        [0, ukuran - 7],
        [ukuran - 7, 0],
    ]) {
        for (let y = 0; y < 7; y += 1) {
            for (let x = 0; x < 7; x += 1) {
                const tepi = y === 0 || y === 6 || x === 0 || x === 6;
                const inti = y >= 2 && y <= 4 && x >= 2 && x <= 4;

                modul[(atas + y) * ukuran + kiri + x] = tepi || inti ? 1 : 0;
            }
        }
    }

    for (let i = 0; i < 8; i += 1) {
        modul[7 * ukuran + i] = 0;
        modul[i * ukuran + 7] = 0;
        modul[7 * ukuran + (ukuran - 1 - i)] = 0;
        modul[(ukuran - 1 - i) * ukuran + 7] = 0;
    }

    for (let i = 8; i < ukuran - 8; i += 1) {
        modul[6 * ukuran + i] = i % 2 === 0 ? 1 : 0;
        modul[i * ukuran + 6] = i % 2 === 0 ? 1 : 0;
    }

    for (const [baris, kolom] of posisiAlignment(versi)) {
        for (let dY = -2; dY <= 2; dY += 1) {
            for (let dX = -2; dX <= 2; dX += 1) {
                const jarak = Math.max(Math.abs(dX), Math.abs(dY));

                modul[(baris + dY) * ukuran + kolom + dX] = jarak === 1 ? 0 : 1;
            }
        }
    }

    modul[(4 * versi + 9) * ukuran + 8] = 1;
}

/** Mode indicator, character count, payload, terminator, then the two pad codewords. */
function susunAliran(byte: number[], versi: number): number[] {
    const total = TOTAL_CODEWORDS[versi - 1] * 8;
    const bit: number[] = [];

    const dorong = (nilai: number, panjang: number): void => {
        for (let i = panjang - 1; i >= 0; i -= 1) {
            bit.push((nilai >> i) & 1);
        }
    };

    dorong(0b0100, 4);
    dorong(byte.length, versi <= 9 ? 8 : 16);

    for (const kode of byte) {
        dorong(kode, 8);
    }

    for (let i = 0; i < 4 && bit.length < total; i += 1) {
        bit.push(0);
    }

    while (bit.length % 8 !== 0) {
        bit.push(0);
    }

    const keluar = bitToByte(bit);

    /**
     * The pad sequence begins with one `0x00` codeword, then alternates `0xEC` / `0x11`.
     *
     * That leading zero is not in the specification's list of pad codewords, and it is here
     * because of what the byte-mode bit length forces: the mode indicator, the character
     * count and the payload total `12 + 8n` bits, so the four-bit terminator ALWAYS lands
     * exactly on a codeword boundary and the byte-alignment step can never insert a zero.
     * `0xEC` first would leave the region inconsistent with every widely deployed encoder -
     * `segno` and `python-qrcode` both emit the leading zero - and, as the unit test shows,
     * a symbol built the other way does not decode: the pad byte changes the Reed-Solomon
     * parity over all ten error-correction codewords, which is far past what level M can
     * correct.
     */
    for (let i = 0; keluar.length < total / 8; i += 1) {
        keluar.push(i === 0 ? 0x00 : i % 2 === 1 ? 0xec : 0x11);
    }

    return keluar;
}

function bitToByte(bit: number[]): number[] {
    const keluar: number[] = [];

    for (let i = 0; i < bit.length; i += 8) {
        let nilai = 0;

        for (let j = 0; j < 8; j += 1) {
            nilai = (nilai << 1) | (bit[i + j] ?? 0);
        }

        keluar.push(nilai & 0xff);
    }

    return keluar;
}

function byteToBit(byte: number[]): number[] {
    const bit: number[] = [];

    for (const nilai of byte) {
        for (let i = 7; i >= 0; i -= 1) {
            bit.push((nilai >> i) & 1);
        }
    }

    return bit;
}

/**
 * Split into blocks, compute each block's Reed-Solomon parity, then interleave.
 *
 * The interleave order is the step that is easiest to get subtly wrong: every block's
 * FIRST data codeword, then every block's second, and only after all of those the parity
 * codewords. Folding the parity into the data pass yields a symbol that scans as garbage.
 */
function kodewordDenganParitas(data: number[], versi: number): number[] {
    const [ecPerBlok, ...groups] = BLOK_M[versi - 1];
    const blokData: number[][] = [];
    let kursor = 0;

    for (let i = 0; i < groups.length; i += 2) {
        for (let b = 0; b < groups[i]; b += 1) {
            blokData.push(data.slice(kursor, kursor + groups[i + 1]));
            kursor += groups[i + 1];
        }
    }

    const paritas = blokData.map((blok) => reedSolomon(blok, ecPerBlok));
    const keluar: number[] = [];
    const panjang = Math.max(...blokData.map((b) => b.length));

    for (let i = 0; i < panjang; i += 1) {
        for (const blok of blokData) {
            if (i < blok.length) {
                keluar.push(blok[i]);
            }
        }
    }

    for (let i = 0; i < ecPerBlok; i += 1) {
        for (const blok of paritas) {
            keluar.push(blok[i]);
        }
    }

    return keluar;
}

let tabelLog: number[] | null = null;
let tabelAntiLog: number[] | null = null;

function inisialisasiGalois(): void {
    if (tabelLog !== null) {
        return;
    }

    /**
     * The anti-log table runs to 512; the log table stays at 256.
     *
     * `gfKali` indexes the anti-log with `log[a] + log[b]`, which reaches 508 for two
     * high-log operands, so a 256-entry anti-log table silently returns `undefined` for
     * exactly the products the generator polynomial is built from. The log table is a
     * different case and the asymmetry is load-bearing: it is only ever indexed by an
     * element, i.e. at most 254, so extending it to 512 would overwrite the real
     * `log[255]` with `log[0]` and make `gfKali(x, 255)` return `x`.
     */
    const log = new Array<number>(256).fill(0);
    const anti = new Array<number>(512).fill(0);
    let nilai = 1;

    for (let i = 0; i < 255; i += 1) {
        anti[i] = nilai;
        log[nilai] = i;
        nilai <<= 1;

        if ((nilai & 0x100) !== 0) {
            nilai ^= POLINOM;
        }
    }

    for (let i = 255; i < 512; i += 1) {
        anti[i] = anti[i - 255];
    }

    tabelLog = log;
    tabelAntiLog = anti;
}

function gfKali(a: number, b: number): number {
    if (a === 0 || b === 0) {
        return 0;
    }

    inisialisasiGalois();

    return (tabelAntiLog as number[])[
        (tabelLog as number[])[a] + (tabelLog as number[])[b]
    ];
}

const generatorCache = new Map<number, number[]>();

/**
 * The generator polynomial's coefficients, highest power first, WITHOUT the leading 1.
 *
 * `g(x) = (x - a^0)(x - a^1)...(x - a^(n-1))` over GF(256). The coefficients are NOT
 * powers of alpha - for degree 2 they are `[3, 2]`, and 3 is not a power of alpha in this
 * field - so they have to be multiplied out rather than read off a log table. Getting this
 * wrong yields plausible-looking parity codewords that no decoder can use, which is the
 * failure the byte-for-byte comparison against `segno` exists to catch.
 */
function generatorPolinomial(derajat: number): number[] {
    const tersimpan = generatorCache.get(derajat);

    if (tersimpan !== undefined) {
        return tersimpan;
    }

    inisialisasiGalois();

    const hasil = new Array<number>(derajat).fill(0);

    hasil[derajat - 1] = 1;

    let akar = 1;

    for (let i = 0; i < derajat; i += 1) {
        for (let j = 0; j < hasil.length; j += 1) {
            hasil[j] = gfKali(hasil[j], akar);

            if (j + 1 < hasil.length) {
                hasil[j] ^= hasil[j + 1];
            }
        }

        akar = gfKali(akar, 0x02);
    }

    generatorCache.set(derajat, hasil);

    return hasil;
}

function reedSolomon(data: number[], jumlah: number): number[] {
    const generator = generatorPolinomial(jumlah);
    const hasil = new Array<number>(jumlah).fill(0);

    // Polynomial long division: the running remainder is exactly `jumlah` entries long, so
    // the leading coefficient is consumed by the shift rather than carried alongside.
    for (const nilai of data) {
        const faktor = nilai ^ hasil[0];

        hasil.shift();
        hasil.push(0);

        for (let i = 0; i < jumlah; i += 1) {
            hasil[i] ^= gfKali(generator[i], faktor);
        }
    }

    return hasil;
}

/**
 * The two-module-wide zigzag, right to left, skipping the vertical timing column.
 *
 * Column 6 is the timing pattern, so the pair steps over it by one; without that the
 * codewords would land on timing modules and the symbol would be short a column.
 */
function pasangData(
    modul: Uint8Array,
    ukuran: number,
    tetap: Uint8Array,
    bit: number[],
): void {
    let kursor = 0;
    let naik = true;

    for (let kanan = ukuran - 1; kanan >= 1; kanan -= 2) {
        if (kanan === 6) {
            kanan -= 1;
        }

        for (let langkah = 0; langkah < ukuran; langkah += 1) {
            const baris = naik ? ukuran - 1 - langkah : langkah;

            for (let kolom = 0; kolom < 2; kolom += 1) {
                const indeks = baris * ukuran + (kanan - kolom);

                if (tetap[indeks] === 1) {
                    continue;
                }

                modul[indeks] = bit[kursor] ?? 0;
                kursor += 1;
            }
        }

        naik = !naik;
    }
}

const MASK: readonly ((b: number, k: number) => boolean)[] = [
    (b, k) => (b + k) % 2 === 0,
    (b) => b % 2 === 0,
    (_b, k) => k % 3 === 0,
    (b, k) => (b + k) % 3 === 0,
    (b, k) => (Math.floor(b / 2) + Math.floor(k / 3)) % 2 === 0,
    (b, k) => ((b * k) % 2) + ((b * k) % 3) === 0,
    (b, k) => (((b * k) % 2) + ((b * k) % 3)) % 2 === 0,
    (b, k) => (((b + k) % 2) + ((b * k) % 3)) % 2 === 0,
];

function terapkanMask(
    modul: Uint8Array,
    ukuran: number,
    tetap: Uint8Array,
    mask: number,
): Uint8Array {
    const keluar = Uint8Array.from(modul);
    const aturan = MASK[mask];

    for (let baris = 0; baris < ukuran; baris += 1) {
        for (let kolom = 0; kolom < ukuran; kolom += 1) {
            const indeks = baris * ukuran + kolom;

            if (tetap[indeks] === 1) {
                continue;
            }

            if (aturan(baris, kolom)) {
                keluar[indeks] = keluar[indeks] === 1 ? 0 : 1;
            }
        }
    }

    return keluar;
}

/** The four penalty rules of section 7.8; the lowest total wins the mask. */
export function penalti(modul: Uint8Array, ukuran: number): number {
    let nilai = 0;

    for (let i = 0; i < ukuran; i += 1) {
        let runBaris = 1;
        let runKolom = 1;

        for (let j = 1; j < ukuran; j += 1) {
            runBaris =
                modul[i * ukuran + j] === modul[i * ukuran + j - 1] ? runBaris + 1 : 1;
            runKolom =
                modul[j * ukuran + i] === modul[(j - 1) * ukuran + i] ? runKolom + 1 : 1;

            if (runBaris === 5) {
                nilai += 3;
            } else if (runBaris > 5) {
                nilai += 1;
            }

            if (runKolom === 5) {
                nilai += 3;
            } else if (runKolom > 5) {
                nilai += 1;
            }
        }
    }

    for (let baris = 0; baris < ukuran - 1; baris += 1) {
        for (let kolom = 0; kolom < ukuran - 1; kolom += 1) {
            const atas = modul[baris * ukuran + kolom];

            if (
                atas === modul[baris * ukuran + kolom + 1] &&
                atas === modul[(baris + 1) * ukuran + kolom] &&
                atas === modul[(baris + 1) * ukuran + kolom + 1]
            ) {
                nilai += 3;
            }
        }
    }

    const pola = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];

    const cocok = (urutan: number[]): boolean =>
        pola.every((p, i) => urutan[i] === p) ||
        [...pola].reverse().every((p, i) => urutan[i] === p);

    for (let baris = 0; baris < ukuran; baris += 1) {
        for (let kolom = 0; kolom <= ukuran - 11; kolom += 1) {
            if (
                cocok(
                    Array.from(
                        { length: 11 },
                        (_, i) => modul[baris * ukuran + kolom + i],
                    ),
                )
            ) {
                nilai += 40;
            }
        }
    }

    for (let kolom = 0; kolom < ukuran; kolom += 1) {
        for (let baris = 0; baris <= ukuran - 11; baris += 1) {
            if (
                cocok(
                    Array.from(
                        { length: 11 },
                        (_, i) => modul[(baris + i) * ukuran + kolom],
                    ),
                )
            ) {
                nilai += 40;
            }
        }
    }

    let gelap = 0;

    for (const m of modul) {
        gelap += m;
    }

    const persen = (gelap * 100) / (ukuran * ukuran);

    return nilai + Math.floor(Math.abs(persen - 50) / 5) * 10;
}

function pilihMask(
    dasar: Uint8Array,
    ukuran: number,
    tetap: Uint8Array,
    versi: number,
): number {
    let terbaik = 0;
    let nilaiTerbaik = Number.POSITIVE_INFINITY;

    for (let mask = 0; mask < 8; mask += 1) {
        const kandidat = terapkanMask(dasar, ukuran, tetap, mask);

        pasangFormat(kandidat, ukuran, mask);
        pasangVersi(kandidat, ukuran, versi);

        const nilai = penalti(kandidat, ukuran);

        if (nilai < nilaiTerbaik) {
            nilaiTerbaik = nilai;
            terbaik = mask;
        }
    }

    return terbaik;
}

/** BCH(15,5) format information, XOR-masked with 0x5412 as the specification requires. */
export function kodeFormat(mask: number): number {
    const data = (LEVEL_M_BIT << 3) | mask;
    let sisa = data << 10;

    for (let i = 4; i >= 0; i -= 1) {
        if (((sisa >> (i + 10)) & 1) === 1) {
            sisa ^= 0b10100110111 << i;
        }
    }

    return ((data << 10) | sisa) ^ 0b101010000010010;
}

function pasangFormat(modul: Uint8Array, ukuran: number, mask: number): void {
    const kode = kodeFormat(mask);

    for (const [baris, kolom, bit] of posisiFormat(ukuran)) {
        modul[baris * ukuran + kolom] = (kode >> bit) & 1;
    }
}

/** BCH(18,6) version information, for versions 7 and above. */
export function kodeVersiInfo(versi: number): number {
    let sisa = versi << 12;

    for (let i = 5; i >= 0; i -= 1) {
        if (((sisa >> (i + 12)) & 1) === 1) {
            sisa ^= 0b1111100100101 << i;
        }
    }

    return (versi << 12) | sisa;
}

function pasangVersi(modul: Uint8Array, ukuran: number, versi: number): void {
    if (versi < 7) {
        return;
    }

    const kode = kodeVersiInfo(versi);

    for (let i = 0; i < 18; i += 1) {
        const baris = Math.floor(i / 3);
        const kolom = ukuran - 11 + (i % 3);

        modul[baris * ukuran + kolom] = (kode >> i) & 1;
        modul[kolom * ukuran + baris] = (kode >> i) & 1;
    }
}

/**
 * The symbol as one SVG path, `M x y h1v1h-1z` per dark module.
 *
 * One path rather than one `<rect>` per module: a 33x33 symbol is 500-plus elements and a
 * 57x57 one over a thousand, which is a real DOM cost for a static image.
 */
export function qrSvgPath(matriks: MatriksQr): string {
    const bagian: string[] = [];

    for (let baris = 0; baris < matriks.ukuran; baris += 1) {
        for (let kolom = 0; kolom < matriks.ukuran; kolom += 1) {
            if (matriks.modul[baris * matriks.ukuran + kolom]) {
                bagian.push(`M${kolom} ${baris}h1v1h-1z`);
            }
        }
    }

    return bagian.join('');
}

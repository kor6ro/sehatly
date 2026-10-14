/**
 * The F02 return path, sanitised.
 *
 * The privacy screen is linked from a blocked booking or payment with
 * `?kembali=/booking/12`, so that after a decision the patient can go back to what they
 * were doing instead of losing their place. The parameter is attacker-reachable: a link
 * somebody else wrote can put anything in the query string, and a client that navigates
 * to it unexamined is an open redirect.
 *
 * Only an internal, path-absolute route survives. The rules are deliberately narrow:
 *
 * - it must start with a single `/` (so `https://elsewhere` and `javascript:` fail);
 * - it must not start with `//` (a protocol-relative URL is an external destination);
 * - it must not contain a backslash (browsers normalise `\` to `/` in some positions,
 *   which is how `/\evil.example` becomes `//evil.example`);
 * - it must not contain control characters (a header-splitting shape, and never valid in
 *   a path this app produces).
 *
 * The query string is kept: a deep link carries `?tanggal=...&jam=...` and dropping it
 * would return the patient to a blank booking form. Nothing sensitive belongs there - the
 * screen itself forbids consent status or values in a URL.
 */
const PANJANG_MAKS = 512;

export function jalurKembali(nilai: string | null): string | null {
    if (nilai === null || nilai === '') {
        return null;
    }

    if (nilai.length > PANJANG_MAKS) {
        return null;
    }

    if (!nilai.startsWith('/') || nilai.startsWith('//')) {
        return null;
    }

    if (nilai.includes('\\')) {
        return null;
    }

    for (const karakter of nilai) {
        const kode = karakter.codePointAt(0) ?? 0;

        if (kode < 0x20 || kode === 0x7f) {
            return null;
        }
    }

    return nilai;
}

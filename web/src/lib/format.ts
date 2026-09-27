import type { Decimal, Iso, Tanggal } from '@/lib/api/types';

/**
 * Display formatting, in one place so no two screens spell the same value differently.
 *
 * Every function here is total: it accepts the nullable columns the DDL allows and never
 * throws, because a formatter that throws inside a render is how a whole route white-screens
 * on one malformed row.
 */

/**
 * Coerce a MySQL `DECIMAL` to a number.
 *
 * A `DECIMAL` column reaches the client as a JSON **string** unless the Eloquent model
 * declares a cast, and which of the two happens is a property of the model rather than of
 * the resource - so a value typed `number` here would be a lie on some rows. `Number('')`
 * is `0` rather than `NaN`, which is exactly the wrong answer for a missing measurement, so
 * the empty and whitespace cases are handled before the conversion rather than after.
 */
export function toNumber(value: Decimal): number | null {
    if (value === null) {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    const trimmed = value.trim();

    if (trimmed === '') {
        return null;
    }

    const parsed = Number(trimmed);

    return Number.isFinite(parsed) ? parsed : null;
}

/**
 * A rupiah amount, or an em-dash-shaped placeholder when there is none.
 *
 * `maximumFractionDigits: 0` because a consultation fee is quoted in whole rupiah and
 * showing `Rp 150.000,00` implies a precision the column does not have.
 */
export function formatRupiah(value: Decimal): string {
    const amount = toNumber(value);

    if (amount === null) {
        return '-';
    }

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(amount);
}

/**
 * A decimal to at most `digits` places, without trailing zeros.
 *
 * Used for a rating, where `4.85` is meaningful and `4.850000` is noise, and for the
 * `DECIMAL(5,1)` / `DECIMAL(5,2)` body measurements.
 */
export function formatDecimal(value: Decimal, digits = 1): string {
    const amount = toNumber(value);

    if (amount === null) {
        return '-';
    }

    return new Intl.NumberFormat('id-ID', {
        maximumFractionDigits: digits,
    }).format(amount);
}

/**
 * A `DATE` column, published as `Y-m-d` and never zone-shifted.
 *
 * Parsed by splitting the string rather than by handing it to `new Date(string)`: a
 * `Date` constructed from a bare `Y-m-d` is parsed as **UTC midnight**, and rendering it in
 * a negative offset date-format locale shifts the calendar day. A birth date is a calendar
 * date, not an instant. `PasienResource` makes the same argument about emitting it.
 */
export function formatTanggal(value: Tanggal): string {
    if (value === null || value === '') {
        return '-';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    const bulan = [
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember',
    ];

    const monthIndex = Number(parts[1]) - 1;
    const monthName = bulan[monthIndex];

    if (monthName === undefined || !/^[0-9]{2}$/.test(parts[2] ?? '')) {
        return value;
    }

    return `${Number(parts[2])} ${monthName} ${parts[0]}`;
}

/**
 * An ISO-8601 instant, rendered in the browser's own locale and time zone.
 *
 * The server publishes UTC (`config/app.php` is UTC and the resources call `toISOString()`).
 * Displaying it in the reader's zone is the right default for a "last seen" line and is
 * the reason the value carries a `Z` at all.
 *
 * A malformed value is returned as-is rather than as "Invalid Date": showing the raw
 * string is more useful to a support request than showing an English error inside an
 * Indonesian screen.
 */
export function formatWaktu(value: Iso): string {
    if (value === null || value === '') {
        return '-';
    }

    const parsed = new Date(value);

    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('id-ID', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsed);
}

/**
 * A phone number, unchanged.
 *
 * There is deliberately no normaliser here. `AuthRequest` matches `no_telepon` exactly and
 * never rewrites it, because storing and looking up the caller's own spelling is the
 * predictable behaviour - a normaliser that rewrote `+62` to `0` would silently make an
 * account unreachable for anyone who typed it the other way round. Formatting the value
 * here would reintroduce exactly the mismatch the server refuses to have.
 */
export function formatTelepon(value: string | null): string {
    return value ?? '-';
}

/**
 * A masked national identifier, rendered exactly as the server sent it.
 *
 * `App\Support\NikMasker` already replaced the middle characters with U+2022 BULLET before
 * the value left PHP, and its output is the same length as its input. This function does
 * **not** mask, un-mask, trim, or re-format: there is no operation in this client that
 * turns a masked value back into a NIK, and adding one would be the actual leak.
 *
 * It exists only to centralise the "is there anything to show" decision, so a `null`
 * NIK renders as a placeholder on every screen at once.
 */
export function formatNikMasked(value: string | null): string {
    if (value === null || value === '') {
        return '-';
    }

    return value;
}

/** `jenis_kelamin ENUM('L','P')`, rendered as the word rather than the code. */
export function formatJenisKelamin(value: 'L' | 'P'): string {
    return value === 'L' ? 'Laki-laki' : 'Perempuan';
}

/**
 * A phone number is a string of digits with an optional `+`, per
 * `RegisterRequest`'s `regex:/^\+?[0-9]{8,20}$/`. This normalises a typed
 * `0812 3456` into `08123456` for a `maxlength` count only, and never for a request
 * body: the value the user typed is the value that is sent.
 */
export function countTeleponDigits(value: string): number {
    return value.replace(/[^0-9]/g, '').length;
}

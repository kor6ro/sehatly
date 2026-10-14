/**
 * The `Y-m-d` <-> `Date` boundary, in one file, because it is the one place in this client
 * where a timezone bug would be invisible and would still produce a wrong booking.
 *
 * ## The trap, stated precisely
 *
 * A consultation date is a **calendar day**, not an instant. `booking.tanggal_kunjungan` is
 * a `DATE` column, `BookingResource` publishes it with `toDateString()`, and
 * `SlotAvailabilityService` compares it as a bare `Y-m-d` string against
 * `dokter_jadwal.berlaku_mulai` / `berlaku_sampai` and against the database's own
 * `SELECT CURDATE()`. Nothing in that pipeline has a time or a zone.
 *
 * JavaScript's `Date` has both, and its two constructors disagree:
 *
 * | expression | parsed as | 2026-10-05 becomes |
 * | --- | --- | --- |
 * | `new Date('2026-10-05')` | **UTC** midnight | 4 October, in any zone behind UTC |
 * | `new Date(2026, 9, 5)` | **local** midnight | 5 October, everywhere |
 * | `date.toISOString().slice(0, 10)` | converts local -> **UTC** | 4 October, in any zone behind UTC |
 *
 * A browser in `Asia/Jakarta` is UTC+7, so `toISOString()` moves a midnight-local day
 * *backwards* by seven hours and lands on the previous day. Jakarta is this app's own
 * audience and the largest zone that moves a date backwards, so the bug would be at its
 * most visible exactly where it matters most.
 *
 * So: **all three functions below read and write local calendar fields**, never
 * `toISOString()` and never a date-only parse. Every conversion in the booking flow goes
 * through them.
 */

/** Today, in the reader's own calendar, as `Y-m-d`. */
export function hariIni(): string {
    return dateKeTanggal(new Date());
}

/**
 * `Y-m-d` -> a local-midnight `Date`, for `@daypicker/react`.
 *
 * DayPicker works entirely in local calendar fields, so the `Date` it is handed must be
 * local midnight of the same calendar day. A `Date` built from the string parts is
 * exactly that, and the reverse parse is not.
 *
 * A malformed or absent value returns `undefined` rather than an Invalid Date: DayPicker
 * treats `selected={undefined}` as "nothing selected", which is the honest rendering of
 * "this screen has no date yet", whereas an Invalid Date would render as a blank cell.
 */
export function tanggalKeDate(tanggal: string | null | undefined): Date | undefined {
    if (tanggal === null || tanggal === undefined) {
        return undefined;
    }

    const parts = tanggal.split('-');

    if (parts.length !== 3) {
        return undefined;
    }

    const tahun = Number(parts[0]);
    const bulan = Number(parts[1]);
    const hari = Number(parts[2]);

    if (
        !/^[0-9]{4}$/.test(parts[0] ?? '') ||
        !/^[0-9]{2}$/.test(parts[1] ?? '') ||
        !/^[0-9]{2}$/.test(parts[2] ?? '') ||
        bulan < 1 ||
        bulan > 12 ||
        hari < 1 ||
        hari > 31
    ) {
        return undefined;
    }

    const date = new Date(tahun, bulan - 1, hari);

    /**
     * Roll-over guard. `new Date(2026, 1, 31)` is 3 March, so a string like `2026-02-31`
     * would silently become a different day. The server refuses it - `date_format:Y-m-d`
     * round-trips, which is the whole reason `StoreBookingRequest` uses that rule and not
     * `date` - so the client refuses the same string rather than offering a day the server
     * will reject.
     */
    if (
        date.getFullYear() !== tahun ||
        date.getMonth() !== bulan - 1 ||
        date.getDate() !== hari
    ) {
        return undefined;
    }

    return date;
}

/**
 * A local-calendar `Date` -> `Y-m-d`, for `@daypicker/react`'s `onSelect`.
 *
 * `getFullYear` / `getMonth` / `getDate` are the **local** accessors. The `getUTC*`
 * family and `toISOString()` are the two that move a day in the zones this app serves, and
 * neither appears here.
 *
 * The zero-pad matters: without it October renders as `2026-10-5`, which is not a
 * `Y-m-d` string, and `date_format:Y-m-d` on the server would reject it.
 */
export function dateKeTanggal(date: Date): string {
    const tahun = String(date.getFullYear()).padStart(4, '0');
    const bulan = String(date.getMonth() + 1).padStart(2, '0');
    const hari = String(date.getDate()).padStart(2, '0');

    return `${tahun}-${bulan}-${hari}`;
}

/** `Date` -> `HH:MM:SS`, the `H:i:s` form `StoreBookingRequest` demands. */
export function dateKeJam(date: Date): string {
    const jam = String(date.getHours()).padStart(2, '0');
    const menit = String(date.getMinutes()).padStart(2, '0');

    return `${jam}:${menit}:00`;
}

/** A `HH:MM` string from an `<input type="time">` -> the `H:i:s` the API wants. */
export function jamKeHms(value: string): string {
    return /^[0-9]{2}:[0-9]{2}$/.test(value) ? `${value}:00` : value;
}

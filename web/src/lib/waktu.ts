import { formatJam } from '@/lib/format';

/**
 * The display half of the timezone boundary, and the reason it is a separate file from
 * `lib/tanggal.ts`.
 *
 * ## The two clocks, stated once
 *
 * `dokter_jadwal.jam_mulai`, `booking.slot_mulai` and `booking.slot_selesai` are `TIME`
 * columns holding **Asia/Jakarta wall clock** (`SlotAvailabilityService::ZONA_WAKTU`), and
 * the value submitted to `POST /booking` stays that `H:i:s` string, unconverted. What a
 * patient reads, however, must be their own device's clock with the zone named - a bare
 * `09.00` is a defect, because the device zone can be set manually and be wrong
 * (`web/ux/patterns/_global.md` §5).
 *
 * So this module converts **for display only**:
 *
 * | direction | function |
 * | --- | --- |
 * | Jakarta wall clock -> device zone, one time | {@link formatJamZona} |
 * | Jakarta wall clock -> device zone, a start-end pair | {@link formatRentangJamZona} |
 * | device zone + Jakarta zone when they differ | {@link formatJamZonaGanda} |
 *
 * ## Why `Intl` and not an offset table
 *
 * A hardcoded `+07:00` is correct until a zone changes its rules, and Indonesia has
 * changed its own more than once. `Intl.DateTimeFormat` carries the IANA database, so the
 * conversion below asks the platform for the offset at the instant in question rather than
 * assuming one. The only literal zone name in this file is the schedule's own
 * `Asia/Jakarta`, which is the server's contract and not a client guess.
 *
 * ## The midnight caveat, stated rather than hidden
 *
 * A converted time is rendered as a time of day, not as a date. A 23:30 WIB slot read from
 * a WIT device is `00.30 WIT` on the **next** calendar day, and this module does not print
 * that day shift. The demo schedule runs 08:00-12:00 WIB, so the case is unreachable in
 * practice; if a schedule ever crosses 22:00 WIB the caller must render the shifted date
 * beside the time.
 */

/** The schedule's own zone, `SlotAvailabilityService::ZONA_WAKTU`. */
export const ZONA_JADWAL = 'Asia/Jakarta';

/**
 * The three Indonesian zone labels, keyed by IANA name.
 *
 * `Asia/Pontianak` is WIB as well - it is UTC+7 like Jakarta - and `Asia/Ujung_Pandang` is
 * the older spelling of `Asia/Makassar`, still returned by some platforms. A zone outside
 * this map falls back to `Intl`'s own short name rather than to a wrong Indonesian label.
 */
const LABEL_ZONA: Readonly<Record<string, string>> = {
    'Asia/Jakarta': 'WIB',
    'Asia/Pontianak': 'WIB',
    'Asia/Makassar': 'WITA',
    'Asia/Ujung_Pandang': 'WITA',
    'Asia/Jayapura': 'WIT',
};

/** The device's IANA zone, or the schedule's zone when the platform cannot say. */
export function zonaPerangkat(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || ZONA_JADWAL;
    } catch {
        return ZONA_JADWAL;
    }
}

/**
 * A zone's short label: `WIB` / `WITA` / `WIT` for the three Indonesian zones, and
 * `Intl`'s own short name (`GMT+1`, `JST`, ...) for anything else.
 */
export function labelZona(zona: string): string {
    const label = LABEL_ZONA[zona];

    if (label !== undefined) {
        return label;
    }

    try {
        const parts = new Intl.DateTimeFormat('id-ID', {
            timeZone: zona,
            timeZoneName: 'short',
        }).formatToParts(new Date());

        return parts.find((part) => part.type === 'timeZoneName')?.value ?? zona;
    } catch {
        return zona;
    }
}

/**
 * The offset of `zona` at one instant, in milliseconds.
 *
 * The standard `Intl` trick: format the instant in the target zone, read the wall-clock
 * fields back, and treat them as if they were UTC. The difference between that reading and
 * the instant is the zone's offset at that moment - DST included, because the formatter
 * resolved it.
 */
function offsetZonaMs(instant: number, zona: string): number {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: zona,
        hour12: false,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    }).formatToParts(new Date(instant));

    const ambil = (tipe: string): number =>
        Number(parts.find((part) => part.type === tipe)?.value ?? '0');

    const sebagaiUtc = Date.UTC(
        ambil('year'),
        ambil('month') - 1,
        ambil('day'),
        // `hour12: false` can render midnight as `24` on some engines.
        ambil('hour') % 24,
        ambil('minute'),
        ambil('second'),
    );

    return sebagaiUtc - instant;
}

/**
 * A Jakarta wall clock (`H:i:s` or `H:i`) on a `Y-m-d` date -> the instant it names.
 *
 * Two passes, because the offset depends on the instant and the instant depends on the
 * offset: the first guess treats the wall clock as UTC, the offset is read at that guess,
 * and the guess is corrected by it. For a zone with a DST transition inside the hour this
 * can be off by the transition; Indonesia has no DST, so the correction is exact here.
 */
function instantDariJamJadwal(
    jam: string,
    tanggal: string,
    zona: string,
): Date | null {
    const cocokJam = /^(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(jam);
    const cocokTanggal = /^(\d{4})-(\d{2})-(\d{2})$/.exec(tanggal);

    if (cocokJam === null || cocokTanggal === null) {
        return null;
    }

    const jamAngka = Number(cocokJam[1]);
    const menitAngka = Number(cocokJam[2]);
    const detikAngka = Number(cocokJam[3] ?? '0');

    if (jamAngka > 23 || menitAngka > 59 || detikAngka > 59) {
        return null;
    }

    const tebakan = Date.UTC(
        Number(cocokTanggal[1]),
        Number(cocokTanggal[2]) - 1,
        Number(cocokTanggal[3]),
        jamAngka,
        menitAngka,
        detikAngka,
    );

    return new Date(tebakan - offsetZonaMs(tebakan, zona));
}

/**
 * A Jakarta wall clock rendered in `zona` as `09.00` (id-ID's dot separator), or `null`
 * when the input is not a time this module can convert.
 */
function jamDiZona(
    jam: string,
    tanggal: string,
    zona: string,
): string | null {
    const instant = instantDariJamJadwal(jam, tanggal, ZONA_JADWAL);

    if (instant === null) {
        return null;
    }

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: zona,
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(instant);
}

/**
 * One schedule time, converted to `zona` (the device zone by default) and labelled:
 * `09.00 WIB`.
 *
 * A malformed time or date falls back to {@link formatJam}'s raw rendering rather than to
 * an empty string, for the same reason every formatter in `lib/format.ts` is total: a
 * render must not throw on one bad row.
 */
export function formatJamZona(
    jam: string,
    tanggal: string,
    zona: string = zonaPerangkat(),
): string {
    const teks = jamDiZona(jam, tanggal, zona);

    if (teks === null) {
        return formatJam(jam);
    }

    return `${teks} ${labelZona(zona)}`;
}

/**
 * A start-end pair, converted and labelled once: `09.00–09.15 WIB`.
 *
 * The label is appended once rather than twice because both ends are in the same zone by
 * construction, and `09.00 WIB–09.15 WIB` reads as two separate facts.
 */
export function formatRentangJamZona(
    mulai: string,
    selesai: string,
    tanggal: string,
    zona: string = zonaPerangkat(),
): string {
    const awal = jamDiZona(mulai, tanggal, zona);
    const akhir = jamDiZona(selesai, tanggal, zona);

    if (awal === null || akhir === null) {
        return `${formatJam(mulai)}–${formatJam(selesai)}`;
    }

    return `${awal}–${akhir} ${labelZona(zona)}`;
}

/**
 * The confirmation form: the device zone first, and the schedule's own zone in
 * parentheses when the two differ - `10.00 WITA (09.00 WIB)`.
 *
 * `_global.md` §5 requires both zones on a confirmation when the device is not on the
 * schedule's clock, because the patient and the clinic are then reading two different
 * numbers for the same appointment. When the device **is** on `Asia/Jakarta` the two
 * readings are identical and the parenthetical is omitted rather than repeated.
 */
export function formatJamZonaGanda(
    jam: string,
    tanggal: string,
    zona: string = zonaPerangkat(),
): string {
    const utama = formatJamZona(jam, tanggal, zona);

    if (zona === ZONA_JADWAL) {
        return utama;
    }

    return `${utama} (${formatJamZona(jam, tanggal, ZONA_JADWAL)})`;
}

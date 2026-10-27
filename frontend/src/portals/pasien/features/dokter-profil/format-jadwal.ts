import type { JadwalHari, JadwalMinggu, TanggalSlot } from '@/lib/api/jadwal';
import { dateKeTanggal, hariIni, tanggalKeDate } from '@/lib/tanggal';

/**
 * The weekly-schedule -> calendar-date boundary for the public doctor profile.
 *
 * ## Why this exists instead of a server field
 *
 * `GET /dokter/{dokter}/jadwal` publishes a **weekly** window map keyed by PHP's own
 * `date('w')` (`0` = Minggu .. `6` = Sabtu), and every window carries a wall-clock
 * `jam_mulai`/`jam_selesai` but **no date**. F04 §8 needs "the nearest day", which is a
 * calendar date, so the next occurrence of each weekday is derived here from the reader's
 * own local calendar - the same `lib/tanggal.ts` boundary the booking flow uses, never
 * `new Date(string)` and never `toISOString()`.
 *
 * The time-of-day stays Asia/Jakarta wall clock and is converted for display by
 * `lib/waktu.ts`; this module only answers "which date comes next".
 */

/** Indonesian short day names, `id-ID` spelling (`Sen`, not `Mon`). */
const HARI_SINGKAT: ReadonlyArray<string> = [
    'Min',
    'Sen',
    'Sel',
    'Rab',
    'Kam',
    'Jum',
    'Sab',
];

/** Indonesian short month names, `id-ID` spelling (`Agu`, not `Aug`, `Okt`, not `Oct`). */
const BULAN_SINGKAT: ReadonlyArray<string> = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];

/** The nearest calendar day's own label, `Sen, 5 Okt`. */
export function labelHariTanggal(tanggal: TanggalSlot): string {
    const date = tanggalKeDate(tanggal);

    if (date === undefined) {
        return tanggal;
    }

    const hari = HARI_SINGKAT[date.getDay()] ?? '';
    const bulan = BULAN_SINGKAT[date.getMonth()] ?? '';

    return `${hari}, ${String(date.getDate())} ${bulan}`;
}

/**
 * `hari ini` when the date is the reader's today, otherwise its day label.
 *
 * F04 §4.3's copy for the nearest slot is exactly `Slot terdekat: hari ini 14.30 WIB`,
 * so the prefix is part of the sentence rather than an optional decoration.
 */
export function labelHariRelatif(tanggal: TanggalSlot): string {
    return tanggal === hariIni() ? 'hari ini' : labelHariTanggal(tanggal);
}

/** One upcoming calendar day that carries at least one schedule window. */
export type HariJadwal = {
    tanggal: TanggalSlot;
    jendela: JadwalHari[];
};

/**
 * The next `jumlah` calendar days, today first, keeping only days with a window.
 *
 * A day with windows is sorted by `jam_mulai` ascending: `getJadwal()` publishes the rows
 * in insertion order, and "the nearest time" must mean the earliest clock time on that day,
 * not the order an admin happened to type them.
 *
 * Days without a window are dropped rather than rendered empty, because
 * `getJadwal()` returns all seven keys unconditionally - including an empty array - so
 * "no row for Wednesday" and "Wednesday exists but holds nothing" are the same wire shape,
 * and neither is a schedule a patient can book.
 */
export function jadwalMendatang(jadwal: JadwalMinggu, jumlah = 7): HariJadwal[] {
    const hasil: HariJadwal[] = [];
    const sekarang = new Date();

    for (let offset = 0; offset < jumlah; offset += 1) {
        const date = new Date(
            sekarang.getFullYear(),
            sekarang.getMonth(),
            sekarang.getDate() + offset,
        );

        const jendela = [...(jadwal[String(date.getDay())] ?? [])].sort((a, b) =>
            a.jam_mulai.localeCompare(b.jam_mulai),
        );

        if (jendela.length > 0) {
            hasil.push({ tanggal: dateKeTanggal(date), jendela });
        }
    }

    return hasil;
}

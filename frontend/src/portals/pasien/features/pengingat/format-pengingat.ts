import { formatTanggal } from '@/lib/format';
import { labelZona, zonaPerangkat } from '@/lib/waktu';
import type { Pengingat } from '@/lib/api/pengingat';

/**
 * Presentation helpers for the reminder list.
 *
 * ## Wall clocks, not instants
 *
 * `pengingat.waktu` is a list of `HH:MM` strings on the reminder's OWN `zona_waktu`.
 * They are never converted and never parsed with `new Date()`: a reminder set for
 * `08:00` in WITA is 08:00 on a WITA wall clock, and converting it to the device would
 * change the number the user typed. The zone label printed beside it comes from the
 * API's own `zona_label`, with the local `labelZona()` as a fallback.
 *
 * ## Day grouping is derived, because the API publishes a schedule and not occurrences
 *
 * A row carries `tanggal_mulai`, an optional `lama_hari` and a list of times - it does
 * not carry one row per occurrence. "Which day is this reminder showing under?" is
 * therefore answered from the window: a reminder whose window covers today shows under
 * `Hari ini`; one that starts in the future under its start date; an expired one under
 * its start date so it stays visible rather than disappearing. All comparisons use the
 * reminder's own zone, because `tanggal_mulai` is read on that zone's calendar.
 */

/** The wall-clock date (`Y-m-d`) in `zona` at `saat`. */
export function tanggalDiZona(zona: string, saat: Date = new Date()): string {
    try {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: zona,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(saat);
    } catch {
        return new Intl.DateTimeFormat('en-CA', {
            timeZone: 'UTC',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(saat);
    }
}

/** `ymd` shifted by `delta` days, as `Y-m-d`. Calendar arithmetic, no zone involved. */
export function geserHari(ymd: string, delta: number): string {
    const cocok = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd);

    if (cocok === null) {
        return ymd;
    }

    const dasar = Date.UTC(Number(cocok[1]), Number(cocok[2]) - 1, Number(cocok[3]));
    const hasil = new Date(dasar + delta * 86_400_000);

    return `${hasil.getUTCFullYear()}-${String(hasil.getUTCMonth() + 1).padStart(2, '0')}-${String(hasil.getUTCDate()).padStart(2, '0')}`;
}

/** The day a reminder belongs under, from its own window and its own zone. */
export function hariTampilPengingat(row: Pengingat, hariIni: string): string {
    const mulai = row.tanggal_mulai ?? hariIni;

    if (mulai >= hariIni) {
        return mulai;
    }

    if (row.lama_hari === null) {
        return hariIni;
    }

    const akhir = geserHari(mulai, row.lama_hari - 1);

    return akhir >= hariIni ? hariIni : mulai;
}

/** `Hari ini` / `Besok` / the calendar date, in the reminder's zone. */
export function labelHariPengingat(hari: string, hariIni: string): string {
    if (hari === hariIni) {
        return 'Hari ini';
    }

    if (hari === geserHari(hariIni, 1)) {
        return 'Besok';
    }

    return formatTanggal(hari);
}

/** The zone label printed beside a reminder wall clock. */
export function zonaPengingat(row: Pengingat): string {
    return row.zona_label ?? labelZona(row.zona_waktu);
}

/** `08.00, 20.00 WIB` - id-ID's dot separator, one zone label at the end. */
export function formatWaktuPengingat(waktu: string[], zona: string): string {
    if (waktu.length === 0) {
        return 'Waktu belum diatur.';
    }

    const daftar = [...waktu].sort().map((jam) => jam.replace(':', '.'));

    return `${daftar.join(', ')} ${zona}`;
}

/** The window sentence, `Mulai 4 Oktober 2026` plus the length when it is set. */
export function formatRentangPengingat(row: Pengingat): string {
    if (row.tanggal_mulai === null) {
        return 'Tanggal mulai belum diatur.';
    }

    const mulai = `Mulai ${formatTanggal(row.tanggal_mulai)}`;

    if (row.lama_hari === null) {
        return `${mulai} • berlanjut`;
    }

    return `${mulai} • ${row.lama_hari} hari`;
}

const WAKTU_TERAKHIR = '99:99';

function waktuPertama(row: Pengingat): string {
    return [...row.waktu].sort()[0] ?? WAKTU_TERAKHIR;
}

export type KelompokPengingat = {
    hari: string;
    label: string;
    rows: Pengingat[];
};

/**
 * Rows grouped by day: `Hari ini` first, then upcoming days ascending, then past days
 * newest-first. Rows inside a day are ordered by their earliest reminder time.
 */
export function kelompokkanPengingat(
    rows: Pengingat[],
    saat: Date = new Date(),
): KelompokPengingat[] {
    const acuan = tanggalDiZona(zonaPerangkat(), saat);
    const perHari = new Map<string, KelompokPengingat>();

    for (const row of rows) {
        const hariIni = tanggalDiZona(row.zona_waktu, saat);
        const hari = hariTampilPengingat(row, hariIni);
        const ada = perHari.get(hari);

        if (ada === undefined) {
            perHari.set(hari, {
                hari,
                label: labelHariPengingat(hari, hariIni),
                rows: [row],
            });
        } else {
            ada.rows.push(row);
        }
    }

    const hasil = [...perHari.values()];

    const peringkat = (hari: string): number => {
        if (hari === acuan) {
            return 0;
        }

        return hari > acuan ? 1 : 2;
    };

    hasil.sort((a, b) => {
        const selisih = peringkat(a.hari) - peringkat(b.hari);

        if (selisih !== 0) {
            return selisih;
        }

        if (peringkat(a.hari) === 2) {
            return a.hari < b.hari ? 1 : -1;
        }

        return a.hari < b.hari ? -1 : 1;
    });

    for (const kelompok of hasil) {
        kelompok.rows.sort((a, b) => {
            const x = waktuPertama(a);
            const y = waktuPertama(b);

            return x === y ? a.id - b.id : x < y ? -1 : 1;
        });
    }

    return hasil;
}

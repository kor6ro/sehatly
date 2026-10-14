import { Link } from 'react-router';
import {
    CheckCheck,
    CircleCheck,
    CirclePause,
    Pencil,
    Power,
    Trash2,
} from 'lucide-react';
import {
    LABEL_JENIS_PENGINGAT,
    LABEL_STATUS_PENGINGAT,
    type Pengingat,
} from '@/lib/api/pengingat';
import {
    formatRentangPengingat,
    formatWaktuPengingat,
    kelompokkanPengingat,
    zonaPengingat,
} from '@/features/pengingat/format-pengingat';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Pagination } from '@/components/layout/pagination';
import type { ApiMeta } from '@/lib/http';

/**
 * The reminder rows, grouped by day.
 *
 * ## Medical text is `text-lg` and never truncated
 *
 * A drug reminder's `judul` is the medicine name and `dosis` may be a strength, so both
 * render at a readable size with no `truncate`/`line-clamp` - the same rule the inbox
 * rows follow.
 *
 * ## Status is text + icon + colour
 *
 * `Aktif` carries a `success`-tinted check, `Nonaktif` a pause glyph, `Selesai` a double
 * check. Colour alone never distinguishes them, and the label is always present.
 *
 * ## The times are wall clocks on the reminder's own zone
 *
 * `PengingatPengirim` compares the literal `HH:MM` against `zona_waktu`, so the list
 * prints the stored time with the API's own `zona_label`; converting to the device zone
 * would print a number the reminder does not fire at.
 */
export function PengingatList({
    rows,
    meta,
    online,
    pendingId,
    onUbah,
    onToggleStatus,
    onHapus,
    onPageChange,
}: {
    rows: Pengingat[];
    meta: ApiMeta | undefined;
    online: boolean;
    /** The row whose pause/resume or delete request is in flight, if any. */
    pendingId: number | null;
    onUbah: (row: Pengingat) => void;
    onToggleStatus: (row: Pengingat) => void;
    onHapus: (row: Pengingat) => void;
    onPageChange: (page: number) => void;
}) {
    const kelompok = kelompokkanPengingat(rows);

    return (
        <div className="flex flex-col gap-6">
            {kelompok.map((grup) => (
                <section
                    key={grup.hari}
                    data-slot="pengingat-grup"
                    data-hari={grup.hari}
                    aria-labelledby={`pengingat-hari-${grup.hari}`}
                    className="flex flex-col gap-1"
                >
                    <h2
                        id={`pengingat-hari-${grup.hari}`}
                        data-slot="pengingat-hari"
                        className="text-muted-foreground text-sm font-semibold uppercase tracking-wide"
                    >
                        {grup.label}
                    </h2>

                    <ul className="divide-border flex flex-col divide-y">
                        {grup.rows.map((row) => (
                            <li
                                key={row.id}
                                data-slot="pengingat-item"
                                data-jenis={row.jenis}
                                data-status={row.status}
                                className="flex flex-col gap-2 py-4"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant="secondary"
                                        data-slot="pengingat-jenis"
                                    >
                                        {LABEL_JENIS_PENGINGAT[row.jenis]}
                                    </Badge>

                                    <StatusPengingat status={row.status} />
                                </div>

                                <p
                                    data-slot="pengingat-judul"
                                    className={
                                        row.jenis === 'obat'
                                            ? 'text-lg font-medium'
                                            : 'text-base font-medium'
                                    }
                                >
                                    {row.judul}
                                </p>

                                {row.jenis === 'obat' &&
                                (row.dosis !== null ||
                                    row.jumlah_per_hari !== null) ? (
                                    <p
                                        data-slot="pengingat-dosis"
                                        className="text-muted-foreground text-base"
                                    >
                                        {[
                                            row.dosis,
                                            row.jumlah_per_hari === null
                                                ? null
                                                : `${row.jumlah_per_hari} kali sehari`,
                                        ]
                                            .filter(
                                                (bagian): bagian is string =>
                                                    bagian !== null &&
                                                    bagian !== '',
                                            )
                                            .join(' • ')}
                                    </p>
                                ) : null}

                                <p
                                    data-slot="pengingat-waktu"
                                    className="text-base"
                                >
                                    {formatWaktuPengingat(
                                        row.waktu,
                                        zonaPengingat(row),
                                    )}
                                </p>

                                <p
                                    data-slot="pengingat-rentang"
                                    className="text-muted-foreground text-sm"
                                >
                                    {formatRentangPengingat(row)}
                                </p>

                                {row.jenis === 'janji_temu' && row.booking_id !== null ? (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="min-h-11 w-fit"
                                        data-slot="pengingat-booking-link"
                                    >
                                        <Link to="/booking">Lihat booking</Link>
                                    </Button>
                                ) : null}

                                <div className="mt-1 flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        data-slot="pengingat-ubah"
                                        disabled={!online}
                                        aria-disabled={!online ? true : undefined}
                                        onClick={() => {
                                            onUbah(row);
                                        }}
                                    >
                                        <Pencil aria-hidden />

                                        Ubah
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="min-h-11"
                                        data-slot="pengingat-toggle-status"
                                        disabled={!online || pendingId === row.id}
                                        aria-disabled={
                                            !online || pendingId === row.id
                                                ? true
                                                : undefined
                                        }
                                        onClick={() => {
                                            onToggleStatus(row);
                                        }}
                                    >
                                        <Power aria-hidden />

                                        {row.status === 'aktif'
                                            ? 'Nonaktifkan'
                                            : 'Aktifkan'}
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="destructive"
                                        className="min-h-11"
                                        data-slot="pengingat-hapus"
                                        disabled={!online || pendingId === row.id}
                                        aria-disabled={
                                            !online || pendingId === row.id
                                                ? true
                                                : undefined
                                        }
                                        onClick={() => {
                                            onHapus(row);
                                        }}
                                    >
                                        <Trash2 aria-hidden />

                                        Hapus
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            ))}

            <Pagination meta={meta} onPageChange={onPageChange} />
        </div>
    );
}

function StatusPengingat({ status }: { status: Pengingat['status'] }) {
    const ikon =
        status === 'aktif' ? (
            <CircleCheck aria-hidden className="text-success size-4" />
        ) : status === 'selesai' ? (
            <CheckCheck aria-hidden className="text-muted-foreground size-4" />
        ) : (
            <CirclePause aria-hidden className="text-muted-foreground size-4" />
        );

    return (
        <span
            data-slot="pengingat-status"
            className="text-muted-foreground flex items-center gap-1.5 text-sm"
        >
            {ikon}

            {LABEL_STATUS_PENGINGAT[status]}
        </span>
    );
}

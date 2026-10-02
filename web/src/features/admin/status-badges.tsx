import {
    CircleCheck,
    CircleX,
    Clock,
    TriangleAlert,
    Video,
    VideoOff,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { AdminDokter, StatusVerifikasiAdmin } from '@/lib/api/admin';
import { labelStatusVerifikasi, tanggalSingkat } from '@/features/admin/format-admin';

/**
 * The admin status badges: text + icon + colour, never colour alone.
 *
 * ## Why the fill is soft and the text is `--foreground`
 *
 * F13's lesson #15 is that `--success`/`--warning` are fill tokens whose
 * foreground pairing is the contrast guarantee, and a status word painted in the
 * hue itself can fall under 4.5:1 on a tinted background. So the badge keeps a
 * `/{40}` border and `/{10}` tint for the channel that is *not* text, the icon
 * carries the hue, and the words stay on `--foreground`. That satisfies both
 * "not colour alone" and the contrast floor axe measures.
 *
 * ## `data-status` is the machine-readable half
 *
 * Every badge stamps it, so an e2e assertion can pin the state without matching
 * a sentence that copy might later reword.
 */

export function AdminVerifikasiBadge({
    status,
    className,
}: {
    status: StatusVerifikasiAdmin;
    className?: string;
}) {
    const tampilan = {
        pending: {
            icon: Clock,
            kelas: 'border-warning/40 bg-warning/10 text-foreground',
            ikon: 'text-warning',
        },
        terverifikasi: {
            icon: CircleCheck,
            kelas: 'border-success/40 bg-success/10 text-foreground',
            ikon: 'text-success',
        },
        ditolak: {
            icon: CircleX,
            kelas: 'border-destructive/40 bg-destructive/10 text-foreground',
            ikon: 'text-destructive',
        },
    }[status];

    const Ikon = tampilan.icon;

    return (
        <Badge
            variant="outline"
            data-slot="admin-verifikasi-badge"
            data-status={status}
            className={cn('gap-1 font-normal', tampilan.kelas, className)}
        >
            <Ikon aria-hidden className={cn('size-3.5', tampilan.ikon)} />

            {labelStatusVerifikasi(status)}
        </Badge>
    );
}

/** `dokter.status_aktif` and `tersedia_telemedisin` as two independent badges. */
export function AdminAktifBadge({ aktif }: { aktif: boolean }) {
    const Ikon = aktif ? CircleCheck : CircleX;

    return (
        <Badge
            variant="outline"
            data-slot="admin-aktif-badge"
            data-status={aktif ? 'aktif' : 'nonaktif'}
            className={cn(
                'gap-1 font-normal',
                aktif
                    ? 'border-success/40 bg-success/10 text-foreground'
                    : 'border-destructive/40 bg-destructive/10 text-foreground',
            )}
        >
            <Ikon
                aria-hidden
                className={cn('size-3.5', aktif ? 'text-success' : 'text-destructive')}
            />

            {aktif ? 'Aktif' : 'Nonaktif'}
        </Badge>
    );
}

export function AdminTelemedisinBadge({ tersedia }: { tersedia: boolean }) {
    const Ikon = tersedia ? Video : VideoOff;

    return (
        <Badge
            variant="outline"
            data-slot="admin-telemedisin-badge"
            data-status={tersedia ? 'tersedia' : 'tidak-tersedia'}
            className="text-muted-foreground gap-1 font-normal"
        >
            <Ikon aria-hidden className="size-3.5" />

            {tersedia ? 'Tersedia telemedisin' : 'Tidak tersedia telemedisin'}
        </Badge>
    );
}

type MasaBerlaku = Pick<
    AdminDokter,
    | 'str_berlaku_sampai'
    | 'str_sisa_hari'
    | 'str_kedaluwarsa'
    | 'str_segera_kedaluwarsa'
>;

/**
 * The STR expiry line: the stored date plus the server's own signed day count.
 *
 * The arithmetic belongs to the server (`str_sisa_hari`, `str_segera_kedaluwarsa`,
 * `str_kedaluwarsa`), and this component only chooses words: "58 hari lagi" for
 * the warning state and "Kedaluwarsa 12 hari" for the lapsed one. It never
 * guesses from the date, because a client clock that disagreed with the clinic's
 * day would disagree about which doctor is close to lapsing.
 */
export function StrMasaBerlaku({ dokter }: { dokter: MasaBerlaku }) {
    const sisa = dokter.str_sisa_hari;

    return (
        <span className="flex flex-wrap items-center gap-2 text-base">
            <span className="tabular-nums">
                s.d. {tanggalSingkat(dokter.str_berlaku_sampai)}
            </span>

            {dokter.str_kedaluwarsa && sisa !== null ? (
                <Badge
                    variant="outline"
                    data-slot="admin-str-badge"
                    data-status="kedaluwarsa"
                    className="text-foreground gap-1 border-destructive/40 bg-destructive/10 font-normal"
                >
                    <TriangleAlert aria-hidden className="text-destructive size-3.5" />

                    <span className="tabular-nums">
                        Kedaluwarsa {Math.abs(sisa)} hari
                    </span>
                </Badge>
            ) : null}

            {dokter.str_segera_kedaluwarsa && sisa !== null ? (
                <Badge
                    variant="outline"
                    data-slot="admin-str-badge"
                    data-status="segera"
                    className="text-foreground gap-1 border-warning/40 bg-warning/10 font-normal"
                >
                    <TriangleAlert aria-hidden className="text-warning size-3.5" />

                    <span className="tabular-nums">{sisa} hari lagi</span>
                </Badge>
            ) : null}
        </span>
    );
}

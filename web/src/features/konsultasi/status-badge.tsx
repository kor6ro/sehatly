import { Ban, CircleCheck, CircleSlash, Clock, FileSignature, Hourglass, XCircle } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { StatusDokumen, StatusKonsultasi } from '@/lib/api/types';
import { Badge } from '@/components/ui/badge';

/**
 * The two vocabularies a consultation screen shows as badges, in one file and for
 * one reason: both are closed sets fixed by the DDL, and a `Record` keyed on the
 * union makes a value that is not in either a **compile error** rather than a badge
 * that silently falls through to a default colour.
 *
 * ## Six consultation statuses, not five
 *
 * The plan's todo 35 asks for "the 5 consultation statuses". `telemedicine_test.sql:542`
 * declares six - `menunggu_dokter`, `berlangsung`, `menunggu_resep`, `selesai`,
 * `dibatalkan`, `gagal` - and `App\Enums\KonsultasiStatus` has six cases with
 * `STATUS_AKHIR = ['selesai', 'dibatalkan', 'gagal']`. All six are rendered.
 *
 * ## Colour alone is never the discriminator
 *
 * Nine treatments across two vocabularies cannot be nine hues that stay
 * distinguishable side by side, and this app is in Indonesian rather than a control
 * room. Each is carried by four channels that survive greyscale, colour-blindness
 * and a phone in sunlight: a token colour, an icon, the Indonesian label, and
 * `data-status` for a test to scope to. This is the same four-channel rule
 * `BookingStatusBadge` follows for the booking ENUM.
 *
 * ## The three terminal consultation states must not look alike
 *
 * `selesai`, `dibatalkan` and `gagal` are the only three with no outgoing edge, and
 * they mean three different things: the visit happened, somebody chose to end it,
 * and it broke. Two of the three - `dibatalkan` and `gagal` - would collapse into one
 * destructive badge if colour were the only channel, and that collapse is the defect:
 * a patient told a failed session was cancelled loses the reason they are not being
 * seen.
 */

type Treatment = { icon: LucideIcon; className: string };

const KONSULTASI: Record<StatusKonsultasi, Treatment> = {
    menunggu_dokter: {
        icon: Hourglass,
        className:
            'text-warning-subtle-foreground bg-warning-subtle border-transparent',
    },
    berlangsung: {
        icon: CircleCheck,
        className:
            'text-success-subtle-foreground bg-success-subtle border-transparent',
    },
    menunggu_resep: {
        icon: Clock,
        className:
            'text-warning-subtle-foreground bg-warning-subtle border-transparent',
    },
    selesai: {
        icon: FileSignature,
        /**
         * `text-foreground/70`, not `text-muted-foreground`: the latter measures
         * about 4.2:1 on `--muted`, under the 4.5:1 floor at this size. 70% ink
         * keeps the quiet, de-emphasised register at 7.3:1 in light mode and
         * still passes in dark mode.
         */
        className: 'text-foreground/70 bg-muted border-transparent',
    },
    dibatalkan: {
        icon: Ban,
        className: 'text-destructive border-destructive',
    },
    gagal: {
        icon: XCircle,
        className:
            'bg-destructive-subtle text-destructive-subtle-foreground border-transparent',
    },
};

const STATUS_DOKUMEN: Record<StatusDokumen, Treatment> = {
    draft: {
        icon: FileSignature,
        className:
            'text-warning-subtle-foreground bg-warning-subtle border-transparent',
    },
    final: {
        icon: CircleCheck,
        className:
            'text-success-subtle-foreground bg-success-subtle border-transparent',
    },
    diamendemen: {
        icon: CircleSlash,
        className: 'text-foreground/70 bg-muted border-dashed',
    },
};

const LABEL_KONSULTASI: Record<StatusKonsultasi, string> = {
    menunggu_dokter: 'Menunggu dokter',
    berlangsung: 'Berlangsung',
    menunggu_resep: 'Menunggu resep',
    selesai: 'Selesai',
    dibatalkan: 'Dibatalkan',
    gagal: 'Gagal',
};

const LABEL_DOKUMEN: Record<StatusDokumen, string> = {
    draft: 'Draft',
    final: 'Final',
    diamendemen: 'Diamendemen',
};

export function KonsultasiStatusBadge({
    status,
    label,
    className,
}: {
    status: StatusKonsultasi;
    /**
     * Overrides the enum's default wording. F13's dashboard reads `menunggu_dokter`
     * as "Menunggu diterima" because the action on that row is accepting it, and the
     * icon, colour and `data-status` still come from the status itself.
     */
    label?: string;
    className?: string;
}) {
    const { icon: Icon, className: tone } = KONSULTASI[status];

    return (
        <Badge
            variant="outline"
            data-slot="konsultasi-status-badge"
            data-status={status}
            className={cn('gap-1', tone, className)}
        >
            <Icon aria-hidden />

            {label ?? LABEL_KONSULTASI[status]}
        </Badge>
    );
}

export function StatusDokumenBadge({
    status,
    className,
}: {
    status: StatusDokumen;
    className?: string;
}) {
    const { icon: Icon, className: tone } = STATUS_DOKUMEN[status];

    return (
        <Badge
            variant="outline"
            data-slot="status-dokumen-badge"
            data-status={status}
            className={cn('gap-1', tone, className)}
        >
            <Icon aria-hidden />

            {LABEL_DOKUMEN[status]}
        </Badge>
    );
}

/**
 * The `versi` badge, which is the other half of a document's identity.
 *
 * `rekam_medis.versi` is a `TINYINT UNSIGNED` capped at 255 by
 * `RekamMedisService::VERSI_MAKS`, so it is a short ordinal and a plain number
 * would be ambiguous next to a record id. `v1` reads as "version one" and cannot be
 * confused with a primary key.
 */
export function VersiBadge({
    versi,
    terbaru,
    className,
}: {
    versi: number;
    terbaru: boolean;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            data-slot="versi-badge"
            data-versi={versi}
            className={cn(
                'tabular-nums',
                terbaru
                    ? 'border-primary text-primary'
                    : 'text-muted-foreground',
                className,
            )}
        >
            v{versi}

            {terbaru ? <span className="sr-only">(versi terbaru)</span> : null}
        </Badge>
    );
}

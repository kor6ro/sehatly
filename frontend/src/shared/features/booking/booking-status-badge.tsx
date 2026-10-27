import {
    Ban,
    CalendarCheck,
    CircleCheck,
    CircleDot,
    CreditCard,
    LogIn,
    TimerOff,
    UserX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { labelDibatalkanOleh, labelStatusBooking, STATUS_MELEPAS_SLOT } from '@/lib/api/booking';
import type { StatusBooking } from '@/lib/api/types';
import { Badge } from '@/components/ui/badge';

/**
 * The eight `booking.status` values, as eight distinguishable treatments.
 *
 * ## Why colour alone is not the discriminator
 *
 * Eight states cannot be eight hues that stay distinguishable side by side, and this app
 * is in Indonesian, not a control room. So the status is carried by **four** channels that
 * survive greyscale, colour-blindness and a phone in sunlight: a token colour, an icon, the
 * Indonesian label, and - for the two terminal states - an explicit sentence about
 * whether the slot came back.
 *
 * ## The three treatments the plan names separately
 *
 * | group | statuses | treatment |
 * | --- | --- | --- |
 * | the terminal pair | `dibatalkan`, `kadaluarsa` | both release the slot, and both are rendered as **outlines**, never fills, because neither is an active booking. They differ from each other by icon and by hue. |
 * | the failure state | `no_show` | `dibatalkan` and `no_show` share the destructive hue, and collapsing them would be the defect: one is a choice and one is a failure the patient is responsible for. Distinguished by icon, and by a label that says so. |
 * | everything else | the other five | one fill or outline each, `terjadwal` and `check_in` and `berlangsung` deliberately separated because they are three different moments of the same visit. |
 *
 * `no_show` is worth one more sentence. It is the only status in the ENUM that records
 * something the patient did, and it is the only one whose `dibatalkan_oleh` is `null` and
 * whose `alasan_pembatalan` is `null` - nothing was cancelled, the patient simply did not
 * come. Rendering it identically to a cancellation would tell a patient they cancelled an
 * appointment they attended.
 */
type Treatment = {
    icon: LucideIcon;
    className: string;
    /**
     * Optional icon colour when the badge label must stay `text-foreground` for contrast.
     * The label is normal-size text and is held to 4.5:1; the icon is a graphic and is
     * held to 3:1, so it can carry the hue on its own.
     */
    iconClassName?: string;
};

const TREATMENT: Record<StatusBooking, Treatment> = {
    menunggu_pembayaran: {
        icon: CreditCard,
        className:
            'text-warning-subtle-foreground bg-warning-subtle border-transparent',
    },
    terjadwal: {
        icon: CalendarCheck,
        className: 'bg-primary text-primary-foreground border-transparent',
    },
    check_in: {
        icon: LogIn,
        className:
            'text-success-subtle-foreground bg-success-subtle border-transparent',
    },
    berlangsung: {
        icon: CircleDot,
        className:
            'text-success-subtle-foreground bg-success-subtle border-transparent',
    },
    selesai: {
        icon: CircleCheck,
        className:
            'text-muted-foreground bg-muted border-transparent',
    },
    dibatalkan: {
        icon: Ban,
        className: 'text-foreground border-destructive',
        iconClassName: 'text-destructive',
    },
    no_show: {
        icon: UserX,
        className:
            'bg-destructive-subtle text-destructive-subtle-foreground border-transparent',
    },
    kadaluarsa: {
        icon: TimerOff,
        className:
            'text-muted-foreground bg-muted/40 border-dashed',
    },
};

export function BookingStatusBadge({
    status,
    className,
}: {
    status: StatusBooking;
    className?: string;
}) {
    const treatment = TREATMENT[status];
    const Icon = treatment.icon;

    return (
        <Badge
            variant="outline"
            className={cn('gap-1', treatment.className, className)}
            data-status={status}
        >
            <Icon aria-hidden className={treatment.iconClassName} />

            {labelStatusBooking(status)}
        </Badge>
    );
}

/**
 * The sentence under a badge for the statuses that ended on their own.
 *
 * The three terminal states need one extra fact each, and it is the fact a patient
 * actually wants: can I book this doctor at this time again? `dibatalkan` and
 * `kadaluarsa` both RELEASE the slot - they are the two members of
 * `SlotAvailabilityService::STATUS_TIDAK_MENGKONSUMSI` - so both say the slot is free, and
 * they say it for the same reason rather than for two different invented ones.
 *
 * `selesai` also releases nothing but the visit is over, so it gets a different sentence
 * rather than the same one.
 */
export function BookingStatusNote({
    status,
    dibatalkanOleh,
    alasan,
}: {
    status: StatusBooking;
    dibatalkanOleh: 'pasien' | 'dokter' | 'sistem' | null;
    alasan: string | null;
}) {
    if (STATUS_MELEPAS_SLOT.includes(status)) {
        return (
            <div className="text-muted-foreground flex flex-col gap-0.5 text-xs">
                <span>
                    Slot ini sudah dilepas dan dapat dipesan kembali.
                </span>

                {status === 'dibatalkan' ? (
                    <span>
                        {labelDibatalkanOleh(dibatalkanOleh)}
                        {alasan === null || alasan === '' ? '' : `: ${alasan}`}
                    </span>
                ) : null}
            </div>
        );
    }

    if (status === 'no_show') {
        return (
            <p className="text-destructive text-xs">
                Booking ditandai tidak hadir. Tidak ada pembatalan yang
                dicatat.
            </p>
        );
    }

    if (status === 'selesai') {
        return (
            <p className="text-muted-foreground text-xs">
                Konsultasi sudah selesai.
            </p>
        );
    }

    return null;
}

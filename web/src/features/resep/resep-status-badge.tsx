import { AlertOctagon, CheckCircle2, PackageCheck, XCircle } from 'lucide-react';
import { LABEL_STATUS_RESEP, LABEL_STATUS_VERIFIKASI } from '@/lib/api/resep';
import type { StatusResep, StatusVerifikasiResep } from '@/lib/api/types';
import { Badge } from '@/components/ui/badge';

/**
 * The `resep.status` badge, for all eight states.
 *
 * ## The five/three split is what the colours encode
 *
 * `ObatInteraksiService::STATUS_BERLAKU` is the first five values - a course the patient
 * is still on, and therefore the set a NEW prescription is checked against - and
 * `STATUS_AKHIR` is the last three, a course that ended. The same partition is
 * `ResepStateMachine::TERMINAL`. So the first five get a `default` badge and the last
 * three get `secondary`, and the boundary is drawn from the server's own two lists rather
 * than from taste: a `selesai` prescription rendered with the "live" treatment would tell
 * a patient their finished course is still running.
 */
const AKHIR: ReadonlySet<string> = new Set(['selesai', 'kedaluwarsa', 'dibatalkan']);

export function StatusResepBadge({
    status,
    className,
}: {
    status: StatusResep;
    className?: string;
}) {
    return (
        <Badge
            data-slot="status-resep-badge"
            data-status={status}
            variant={AKHIR.has(status) ? 'secondary' : 'default'}
            className={className}
        >
            {LABEL_STATUS_RESEP[status] ?? status}
        </Badge>
    );
}

/**
 * The `is_kedaluwarsa` flag, which is a SEPARATE badge from the status.
 *
 * The reason they are not one badge: `ResepStateMachine::kedaluwarsa()` is true for two
 * different reasons - the stored status is `kedaluwarsa`, OR `berlaku_sampai` is in the
 * past while the status still says `aktif`. Nothing in the schema reacts to
 * `berlaku_sampai`, so the second case is the common one and it is invisible in the status
 * column. Merging them would hide a lapsed prescription behind a cheerful "Aktif".
 */
export function KedaluwarsaBadge({ className }: { className?: string }) {
    return (
        <Badge
            data-slot="kedaluwarsa-badge"
            variant="destructive"
            className={className}
        >
            <AlertOctagon aria-hidden />

            Kedaluwarsa
        </Badge>
    );
}

const IKON_VERIFIKASI = {
    sesuai: CheckCircle2,
    ada_koreksi: PackageCheck,
    ditolak: XCircle,
} as const;

/**
 * The pharmacist's outcome, and `ditolak` says plainly that it is final.
 *
 * `resep_verifikasi.resep_id` is `UNIQUE`, so the one verification a prescription can hold
 * is spent whichever outcome it is, and a rejection additionally closes the prescription
 * for good - there is no `resep.status` meaning "returned for correction" among the eight.
 * The copy names that, because a queue that offered a "resubmit" button here would be
 * advertising a flow the schema cannot honour.
 */
export function StatusVerifikasiBadge({
    status,
    className,
}: {
    status: StatusVerifikasiResep;
    className?: string;
}) {
    const Ikon = IKON_VERIFIKASI[status];

    return (
        <Badge
            data-slot="status-verifikasi-badge"
            data-status={status}
            variant={status === 'ditolak' ? 'destructive' : 'default'}
            className={className}
        >
            <Ikon aria-hidden />

            {LABEL_STATUS_VERIFIKASI[status] ?? status}
        </Badge>
    );
}

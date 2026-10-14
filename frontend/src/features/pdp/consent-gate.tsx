import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import {
    labelJenis,
    persetujuanBelumDiberikan,
    persetujuanOptions,
} from '@/lib/api/pdp-persetujuan';
import { Button } from '@/components/ui/button';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';

/**
 * The mandatory-consent gate in front of a booking and a payment.
 *
 * Owner decision 2026-10-01 (F02 §12 #4): the three consents
 * `syarat_ketentuan`, `kebijakan_privasi` and `berbagi_data_medis` must all be `true`
 * before a patient submits a booking or starts a payment. Browsing the doctor directory
 * is deliberately NOT gated, and `pemasaran` / `komunikasi_tindak_lanjut` never gate -
 * refusing a promotion must not cost access to care.
 *
 * ## `efektif !== true` blocks, and that includes `null`
 *
 * An unanswered slot and a refused one both block, which is why the check is
 * `persetujuanBelumDiberikan()` and not a `=== false` test. The API publishes the
 * checklist with no holes precisely so this decision does not have to guess.
 *
 * ## What it renders in each state
 *
 * | state | surface |
 * | --- | --- |
 * | read in flight | `LoadingState` with skeletons, never a full-screen spinner |
 * | read failed | `ErrorState` with "Coba lagi" (retryable: the gate cannot answer) |
 * | consent missing | `ForbiddenState` with the missing kinds named and one link to `/profil/privasi` |
 * | all present | the children, untouched |
 *
 * The blocked branch intentionally offers no retry button: re-asking the same endpoint
 * cannot change the answer. The one useful action is the link, and it carries a return
 * path so the patient comes back to this booking or payment afterwards.
 */
export function ConsentGate({
    kembaliKe,
    children,
}: {
    /** Internal route to return to after deciding, sanitised by the receiver. */
    kembaliKe: string;
    children: ReactNode;
}) {
    const persetujuan = useQuery(persetujuanOptions());

    if (persetujuan.isPending) {
        return (
            <LoadingState label="Memeriksa persetujuan Anda...">
                <SkeletonRows rows={3} />
            </LoadingState>
        );
    }

    if (persetujuan.isError) {
        return (
            <ErrorState
                error={persetujuan.error}
                onRetry={() => {
                    void persetujuan.refetch();
                }}
            />
        );
    }

    const kurang = persetujuanBelumDiberikan(persetujuan.data.data.persetujuan);

    if (kurang.length > 0) {
        return (
            <ForbiddenState
                title="Persetujuan diperlukan"
                detail={`Sebelum melanjutkan, Anda perlu menyetujui: ${kurang
                    .map(labelJenis)
                    .join(', ')}.`}
                action={
                    <Button asChild className="mt-1 min-h-11">
                        <Link
                            to={`/profil/privasi?kembali=${encodeURIComponent(kembaliKe)}`}
                        >
                            Buka Privasi dan data
                        </Link>
                    </Button>
                }
            />
        );
    }

    return <>{children}</>;
}

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft } from 'lucide-react';
import { meOptions } from '@/lib/api/me';
import { PageHeader } from '@/components/layout/page-header';
import { OfflineBanner } from '@/components/offline-banner';
import { Button } from '@/components/ui/button';
import { ForbiddenState } from '@/components/states/error-state';
import { AntreanVerifikasiList } from '@/features/resep/antrean-verifikasi-list';
import { ApotekerVerifikasiQueue } from '@/features/resep/apoteker-verifikasi-queue';

/**
 * `/apotek/resep` - the pharmacist's verification queue.
 *
 * ## A pharmacist and nobody else
 *
 * `GET /resep` and `POST /resep/{id}/verifikasi` carry the same `tipe:apoteker` plus
 * `permission:resep.verifikasi` pair, and `ResepAccess::untukVerifikasi()` adds a rule no
 * route gate can express: **a doctor may not verify their own prescription.** `dokter.user_id`
 * and `users.tipe` are independent columns and nothing checks that a `dokter` row's user is
 * typed `dokter`, so a pharmacist who also practises is a real possibility in this schema.
 * The queue is therefore rendered for `tipe === 'apoteker'` and `tipe === 'superadmin'` only
 * - the same two `ResepAccess::TIPE_APOTEK` members - and a `dokter` is offered a 403 rather
 * than a list that would fail at the last step.
 *
 * ## Selection stays in page state, not in the URL
 *
 * A row hands its id to this page and the detail + decision render in place. Keeping the id
 * out of the URL is deliberate: the queue publishes no clinical data and no patient
 * identity, and a route that carried the prescription id into the address bar would make
 * sharing a screen a disclosure. The detail read itself is per-row gated server-side.
 *
 * ## Offline is detected, never queued
 *
 * The shared `OfflineBanner` is rendered above the queue. A verification is a terminal,
 * once-only write (`resep_verifikasi.resep_id` is UNIQUE), so no mutation is buffered and
 * replayed - `_global.md` §7 #1.
 */
export function ApotekQueuePage() {
    const [resepId, setResepId] = useState<number | null>(null);

    const saya = useQuery(meOptions());

    const user = saya.data?.data.user ?? null;
    const boleh = user?.tipe === 'apoteker' || user?.tipe === 'superadmin';

    return (
        <>
            <PageHeader
                title="Antrean verifikasi resep"
                description="Resep terbaru muncul lebih dahulu. Satu resep hanya dapat diverifikasi sekali, dan penolakan bersifat final."
            />

            <OfflineBanner className="mt-4" />

            <div className="mt-4">
                {boleh ? (
                    resepId === null ? (
                        <AntreanVerifikasiList onPilih={setResepId} />
                    ) : (
                        <div className="flex flex-col gap-4">
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    data-slot="antrean-kembali"
                                    onClick={() => {
                                        setResepId(null);
                                    }}
                                >
                                    <ArrowLeft aria-hidden />

                                    Kembali ke antrean
                                </Button>
                            </div>

                            <ApotekerVerifikasiQueue
                                resepId={resepId}
                                onSelesai={() => {
                                    setResepId(null);
                                }}
                            />
                        </div>
                    )
                ) : (
                    <ForbiddenState detail="Hanya akun apoteker yang dapat memverifikasi resep." />
                )}
            </div>
        </>
    );
}

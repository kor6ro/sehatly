import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { meOptions } from '@/lib/api/me';
import { ApotekerVerifikasiQueue } from '@/features/resep/apoteker-verifikasi-queue';
import { PageHeader } from '@/components/layout/page-header';
import { ForbiddenState } from '@/components/states/error-state';

/**
 * `/apotek/resep` - the pharmacist's verification queue.
 *
 * ## A pharmacist and nobody else
 *
 * `POST /resep/{id}/verifikasi` carries `tipe:apoteker` plus `permission:resep.verifikasi`,
 * and `ResepAccess::untukVerifikasi()` adds a rule no route gate can express: **a doctor may
 * not verify their own prescription.** `dokter.user_id` and `users.tipe` are independent
 * columns and nothing checks that a `dokter` row's user is typed `dokter`, so a pharmacist
 * who also practises is a real possibility in this schema. The queue is therefore rendered
 * for `tipe === 'apoteker'` and `tipe === 'superadmin'` only - the same two
 * `ResepAccess::TIPE_APOTEK` members - and a `dokter` is offered a 403 rather than a form
 * that would fail at the last step.
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
                description="Satu resep hanya dapat diverifikasi sekali, dan penolakan bersifat final."
            />

            {boleh ? (
                <ApotekerVerifikasiQueue
                    resepId={resepId}
                    onTerpilih={setResepId}
                />
            ) : (
                <ForbiddenState detail="Hanya akun apoteker yang dapat memverifikasi resep." />
            )}
        </>
    );
}

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { Eye, PenLine } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { meOptions } from '@/lib/api/me';
import { rekamMedisOptions } from '@/lib/api/rekam-medis';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState, NotFoundState } from '@/components/states/error-state';
import { Button } from '@/components/ui/button';
import { RekamMedisView } from '@/features/rekam-medis/rekam-medis-view';
import { RekamMedisEditForm } from '@/features/rekam-medis/rekam-medis-edit-form';

/**
 * `/rekam-medis/:id` - the medical record, read-only for a patient and editable by its
 * own doctor.
 *
 * ## Every render that fetches is a REAL read, and that is the point
 *
 * `RekamMedisService::findForAccess()` is the only way to read a record in the
 * application and it delegates to `RekamMedisAccessLogger::baca()`, which writes
 * exactly one `akses_rekam_medis_log` row per completed read - including a patient's
 * read of their OWN record. The log is the compliance record under UU PDP 27/2022 and
 * Permenkes 24/2022, not a diagnostic, so it must not be hideable.
 *
 * Three things follow, and each is a deliberate decision rather than an accident of
 * the query defaults:
 *
 * 1. **One `useQuery` for the whole document.** `RekamMedisResource` publishes the
 *    record, the four child collections and the `ran` chain in a single response, so
 *    there is nothing to fan out over. The chain entries are a reduced projection by
 *    design: expanding one is a separate read and a separate log row, which is the
 *    correct accounting.
 * 2. **`queryKey` is `['v1','rekam-medis', id]` and nothing else.** A key that
 *    included a fresh `Date.now()` would be a read per render and would write a log
 *    row per render.
 * 3. **The write mutations REPLACE the cache rather than invalidating it.** Every one
 *    of `simpan`/`ubah`/`final`/`amandemen` answers with the whole document already,
 *    so an invalidation would be a second `GET /rekam-medis/{id}` - a second log row
 *    for a read the client did not need. One user action, one read.
 *
 * The project-wide `staleTime: 30_000` and `refetchOnWindowFocus: false` are the
 * shared compromise, and they are the reason a tab-switch does not manufacture a log
 * row per focus event.
 */
export function RekamMedisPage() {
    const { id } = useParams<{ id: string }>();
    const rekamMedisId = Number(id);
    const [menyunting, setMenyunting] = useState(false);

    const rekam = useQuery({
        ...rekamMedisOptions(rekamMedisId),
        enabled: Number.isInteger(rekamMedisId),
    });

    const saya = useQuery(meOptions());

    if (!Number.isInteger(rekamMedisId)) {
        return (
            <>
                <PageHeader title="Rekam medis" />

                <NotFoundState
                    title="Rekam medis tidak ditemukan"
                    detail="Alamat halaman harus berisi nomor rekam medis berupa angka."
                />
            </>
        );
    }

    if (rekam.isPending) {
        return (
            <>
                <PageHeader
                    title="Rekam medis"
                    description="Memuat dokumen. Setiap pembacaan tercatat pada akses_rekam_medis_log."
                />

                <SkeletonRows rows={6} />
            </>
        );
    }

    if (rekam.isError) {
        return (
            <>
                <PageHeader title="Rekam medis" />

                {rekam.error instanceof ApiError && rekam.error.isForbidden ? (
                    <ForbiddenState />
                ) : (
                    <ErrorState
                        error={rekam.error}
                        onRetry={() => {
                            void rekam.refetch();
                        }}
                    />
                )}
            </>
        );
    }

    const data = rekam.data.data.rekam_medis;
    const user = saya.data?.data.user ?? null;

    /**
     * A record is editable only by the doctor who owns it, and the UI says which
     * rule decides: all three writes carry `tipe:dokter` plus a `rekam_medis.*`
     * grant, and the service additionally refuses a doctor attached to a different
     * consultation. A patient's read is a read and nothing else, so the form is not
     * merely disabled for them - it is absent.
     */
    const bolehUbah = user?.tipe === 'dokter';

    return (
        <>
            <PageHeader
                title={`Rekam medis #${data.id}`}
                description="Dibaca dari GET /api/v1/rekam-medis/{id}. Setiap pembacaan tercatat pada akses_rekam_medis_log."
                action={
                    bolehUbah ? (
                        <Button
                            type="button"
                            variant={menyunting ? 'outline' : 'default'}
                            onClick={() => {
                                setMenyunting((value) => !value);
                            }}
                        >
                            {menyunting ? <Eye /> : <PenLine />}

                            {menyunting ? 'Tutup editor' : 'Edit rekam medis'}
                        </Button>
                    ) : undefined
                }
            />

            {/**
             * The two halves are never both mounted, for the reason `profile-page.tsx`
             * gives: an editor sitting invisibly over a read view would hold stale
             * values, and the read view would re-render on every keystroke in a field
             * above it.
             */}
            {menyunting && bolehUbah ? (
                <RekamMedisEditForm rekam={data} />
            ) : (
                <RekamMedisView rekam={data} />
            )}
        </>
    );
}

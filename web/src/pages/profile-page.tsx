import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Pencil } from 'lucide-react';
import { profilOptions } from '@/lib/api/pasien-profil';
import { ApiError } from '@/lib/http';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHeader } from '@/components/layout/page-header';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState, ForbiddenState } from '@/components/states/error-state';
import { ProfileDetails, ReferenceIdsNotice } from '@/components/profile/profile-details';
import { ProfileForm } from '@/components/profile/profile-form';

/**
 * `/profil` - read and edit `GET|PUT /api/v1/pasien/profil`.
 *
 * ## Why 403 gets its own screen and not the error box
 *
 * `PasienController` resolves the caller's own row through
 * `PasienRecordAccess::ownPasien()` **before** doing anything else, and an account with no
 * `pasien` row is refused with a 403 on all ten patient routes. That is a statement about
 * the caller's account, not about a missing document, and it is the one patient-route
 * failure a retry cannot fix - so it is rendered as a destination with an explanation
 * rather than as a red box with a "Coba lagi" button that would fail identically.
 *
 * A 404 cannot happen here (there is no `{id}` on this route) and a 422 belongs to the
 * form, so those are the only other branches.
 *
 * ## Why the read view and the edit form are one route
 *
 * `PUT /pasien/profil` writes `users.nama_lengkap` and a block of `pasien` columns in one
 * transaction, and returns the whole row afterwards. So the edit is not a separate screen
 * with its own load: it is the same cached object with the form swapped in, which is why
 * `updateProfilMutation` replaces the cache from the response instead of refetching.
 */
export function ProfilePage() {
    const [editing, setEditing] = useState(false);

    const profil = useQuery(profilOptions());

    if (profil.isPending) {
        return (
            <>
                <PageHeader
                    title="Profil pasien"
                    description="Memuat profil dari GET /api/v1/pasien/profil."
                />

                <SkeletonRows rows={6} />
            </>
        );
    }

    if (profil.isError) {
        if (profil.error instanceof ApiError && profil.error.isForbidden) {
            return (
                <>
                    <PageHeader title="Profil pasien" />

                    <ForbiddenState />
                </>
            );
        }

        return (
            <>
                <PageHeader title="Profil pasien" />

                <ErrorState
                    error={profil.error}
                    onRetry={() => {
                        void profil.refetch();
                    }}
                />
            </>
        );
    }

    const profile = profil.data.data.profile;

    return (
        <>
            <PageHeader
                title="Profil pasien"
                description="Data ini dibaca dari GET /api/v1/pasien/profil."
                action={
                    <Button
                        type="button"
                        variant={editing ? 'outline' : 'default'}
                        onClick={() => {
                            setEditing((value) => !value);
                        }}
                    >
                        <Pencil />

                        {editing ? 'Batal' : 'Ubah profil'}
                    </Button>
                }
            />

            {/**
             * The two halves are never both mounted, so an edit form is never sitting
             * invisibly over a read view holding stale values, and the read view is not
             * re-rendering on every keystroke in a field above it.
             */}
            {editing ? (
                <Card>
                    <CardHeader>
                        <CardTitle>Ubah profil</CardTitle>
                    </CardHeader>

                    <CardContent>
                        <ProfileForm profile={profile} />
                    </CardContent>
                </Card>
            ) : (
                <Card>
                    <CardContent className="pt-6">
                        <ProfileDetails profile={profile} />

                        <div className="mt-6">
                            <ReferenceIdsNotice profile={profile} />
                        </div>
                    </CardContent>
                </Card>
            )}
        </>
    );
}

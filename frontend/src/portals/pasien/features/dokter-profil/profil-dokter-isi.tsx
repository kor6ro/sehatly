import { Video } from 'lucide-react';
import { formatWaktu } from '@/lib/format';
import type { DokterDetail } from '@/lib/api/types';
import { EmptyState } from '@/shared/components/states/empty-state';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CredentialPanel } from '@/portals/pasien/features/dokter-profil/credential-panel';
import { DoctorProfileHero } from '@/portals/pasien/features/dokter-profil/doctor-profile-hero';
import { ReviewsBlock } from '@/portals/pasien/features/dokter-profil/reviews-block';
import { SchedulePreview } from '@/portals/pasien/features/dokter-profil/schedule-preview';

/**
 * The body of a doctor's profile - everything below the title, in the order a patient
 * decides in: who, when, is she real, what do others say, then the two short cards that
 * hold the leftovers.
 *
 * ## Why it is a component and not a page
 *
 * A doctor's profile used to be reachable only at `/dokter/:id`, and the page owned its
 * own frame. It now also opens INSIDE the landing page, because the directory is the
 * landing page: a patient who picks a card out of the answer wants the profile where they
 * are, not a second address they then have to back out of. So the frame stayed with the
 * page (`PageHeader`, the back link, the title) and the content moved here, which means the
 * two surfaces cannot drift about whether `Kredensial & verifikasi` comes before or after
 * `Ulasan pasien`.
 *
 * ## What it does NOT own
 *
 * Not the loading state, not the error state, not the 404. Those differ by surface: the
 * page says "Kembali ke direkturi" and sets a tab title, the landing page swaps back to
 * the list. Both are callers' decisions, so both stay outside.
 */
export function ProfilDokterIsi({
    dokter,
    online,
}: {
    dokter: DokterDetail;
    online: boolean;
}) {
    return (
        <>
            <DoctorProfileHero dokter={dokter} online={online} />

            <SchedulePreview dokterId={String(dokter.id)} />

            <CredentialPanel dokter={dokter} />

            <ReviewsBlock dokterId={String(dokter.id)} />

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Tentang dokter</CardTitle>
                </CardHeader>

                <CardContent>
                    {dokter.bio === null || dokter.bio === '' ? (
                        <EmptyState
                            compact
                            title="Bio belum diisi"
                            description="Dokter ini belum menambahkan deskripsi singkat pada profilnya."
                        />
                    ) : (
                        <p className="text-base leading-relaxed">{dokter.bio}</p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Informasi lain</CardTitle>
                </CardHeader>

                <CardContent className="flex flex-col gap-4">
                    {dokter.tersedia_telemedisin ? (
                        <Badge variant="outline" className="border-success/60 w-fit">
                            <Video aria-hidden className="text-success" />

                            Tersedia telemedisin
                        </Badge>
                    ) : null}

                    {/**
                     * One cell. The rating and review count live in the reviews block,
                     * which reads them from `/ulasan` - the only source recomputed from
                     * `ulasan_dokter` - so printing them here too would be a second
                     * source for one number, and the second one would lie.
                     */}
                    <dl className="grid gap-4 sm:grid-cols-3">
                        <div className="flex flex-col gap-0.5">
                            <dt className="text-muted-foreground text-xs">Terdaftar</dt>

                            <dd className="text-sm font-medium">
                                {formatWaktu(dokter.dibuat_at)}
                            </dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </>
    );
}

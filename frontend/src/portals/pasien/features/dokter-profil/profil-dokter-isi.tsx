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

export function ProfilDokterIsi({
    dokter,
    online,
}: {
    dokter: DokterDetail;
    online: boolean;
}) {
    return (
        <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
            {/* Kolom kiri: identitas + kredensial + bio + ulasan */}
            <div className="flex min-w-0 flex-1 flex-col gap-6">
                <DoctorProfileHero dokter={dokter} online={online} />

                <CredentialPanel dokter={dokter} />

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

                <ReviewsBlock dokterId={String(dokter.id)} />
            </div>

            {/* Kolom kanan: jadwal sticky */}
            <div className="w-full lg:w-80 lg:shrink-0">
                <div className="lg:sticky lg:top-6">
                    <SchedulePreview dokterId={String(dokter.id)} />
                </div>
            </div>
        </div>
    );
}

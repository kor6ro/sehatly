import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { CalendarClock, ChevronDown, Clock } from 'lucide-react';
import { HARI_NAMES, jadwalOptions, type JadwalHari } from '@/lib/api/jadwal';
import { ZONA_JADWAL, formatRentangJamZona, zonaPerangkat } from '@/lib/waktu';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ErrorState } from '@/components/states/error-state';
import { SkeletonRows } from '@/components/states/loading-state';

const URUTAN_HARI = [1, 2, 3, 4, 5, 6, 0] as const;

/**
 * The doctor's weekly schedule, display-only.
 *
 * There is no schedule write endpoint (`GET /dokter/{dokter}/jadwal` only), so this
 * card carries no save affordance and says so in its description. Times are the
 * schedule's Asia/Jakarta wall clock and are converted for display with the zone
 * named; a device outside WIB reads both zones (`_global.md` §5).
 */
export function JadwalMinggu({
    dokterId,
    enabled,
}: {
    dokterId: string | null;
    enabled: boolean;
}) {
    const [terbuka, setTerbuka] = useState(false);

    const jadwal = useQuery({
        ...jadwalOptions(dokterId ?? ''),
        enabled: enabled && dokterId !== null,
    });

    const hari = jadwal.data?.data.jadwal ?? {};

    return (
        <Card data-slot="f13-jadwal">
            <Collapsible open={terbuka} onOpenChange={setTerbuka}>
                <CardHeader>
                    <CardTitle>
                        <CollapsibleTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11 w-full justify-between px-0 hover:bg-transparent"
                            >
                                <span className="flex items-center gap-2">
                                    <CalendarClock aria-hidden />

                                    Jadwal minggu ini
                                </span>

                                <ChevronDown
                                    aria-hidden
                                    className={
                                        terbuka
                                            ? 'rotate-180 transition-transform'
                                            : 'transition-transform'
                                    }
                                />
                            </Button>
                        </CollapsibleTrigger>
                    </CardTitle>

                    <CardDescription>
                        Baca saja. Perubahan jadwal dilakukan di luar dasbor.
                    </CardDescription>
                </CardHeader>

                <CollapsibleContent>
                    <CardContent>
                        {jadwal.isPending ? (
                            <SkeletonRows rows={3} />
                        ) : jadwal.isError ? (
                            <ErrorState
                                error={jadwal.error}
                                title="Gagal memuat jadwal."
                                onRetry={() => {
                                    void jadwal.refetch();
                                }}
                            />
                        ) : jumlahJendela(hari) === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Belum ada jadwal mingguan yang dipublikasikan.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {URUTAN_HARI.map((index) => {
                                    const jendela = hari[String(index)] ?? [];

                                    if (jendela.length === 0) {
                                        return null;
                                    }

                                    return (
                                        <li
                                            key={index}
                                            className="flex flex-col gap-1"
                                        >
                                            <p className="flex items-center gap-2 text-sm font-medium">
                                                {HARI_NAMES[index] ?? String(index)}

                                                {index === new Date().getDay() ? (
                                                    <Badge variant="secondary">Hari ini</Badge>
                                                ) : null}
                                            </p>

                                            {jendela.map((baris) => (
                                                <p
                                                    key={baris.jadwal_id}
                                                    className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm"
                                                >
                                                    <Clock aria-hidden className="size-4" />

                                                    {rentangZona(baris)}

                                                    {' • '}

                                                    {labelTipeJadwal(baris.tipe_layanan)}
                                                </p>
                                            ))}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

function jumlahJendela(hari: Record<string, JadwalHari[]>): number {
    return Object.values(hari).reduce((total, jendela) => total + jendela.length, 0);
}

function rentangZona(baris: JadwalHari): string {
    const tanggal = new Date().toISOString().slice(0, 10);
    const zona = zonaPerangkat();
    const utama = formatRentangJamZona(baris.jam_mulai, baris.jam_selesai, tanggal, zona);

    if (zona === ZONA_JADWAL) {
        return utama;
    }

    return `${utama} (${formatRentangJamZona(
        baris.jam_mulai,
        baris.jam_selesai,
        tanggal,
        ZONA_JADWAL,
    )})`;
}

function labelTipeJadwal(tipe: string): string {
    switch (tipe) {
        case 'online':
            return 'Online';
        case 'klinik':
            return 'Klinik';
        case 'home_visit':
            return 'Kunjungan rumah';
        default:
            return tipe;
    }
}

import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { CalendarClock, LifeBuoy } from 'lucide-react';
import { jadwalOptions, slotOptions } from '@/lib/api/jadwal';
import {
    formatJamZona,
    formatRentangJamZona,
    labelZona,
    zonaPerangkat,
} from '@/lib/waktu';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorState } from '@/components/states/error-state';
import { EmptyState } from '@/components/states/empty-state';
import { LoadingState, SkeletonRows } from '@/components/states/loading-state';
import {
    jadwalMendatang,
    labelHariRelatif,
    labelHariTanggal,
} from '@/features/dokter-profil/format-jadwal';

/**
 * `Jadwal terdekat` - F04's biggest gap, closed against two endpoints that already answer
 * 200: `GET /dokter/{dokter}/jadwal` and `GET /dokter/{dokter}/slot?tanggal=YYYY-MM-DD`.
 *
 * ## The client computes the date, never the availability
 *
 * `getJadwal()` publishes recurring weekly windows; the *date* of "Senin next week" is a
 * calendar calculation the browser can make honestly from `lib/tanggal.ts`. Which
 * **slots** exist on that date is not: `SlotAvailabilityService` is the only authority on
 * quota, holidays, past-time and the STR boundary, so this component asks for the first
 * upcoming date and renders whatever the server's slot list contains. `Slot terdekat`
 * appears only when the server itself marks a slot `tersedia`.
 *
 * ## The full schedule is this block
 *
 * The hero's `Lihat jadwal lengkap` anchors to `#jadwal-terdekat`; the block lists every
 * upcoming day inside a seven-day horizon that carries a window, in date order, each with
 * its Jakarta wall clock converted to the reader's device zone and labelled (`WIB/WITA/WIT`
 * - `_global.md` §5 makes an unlabelled time a defect).
 *
 * ## Four states, none of them blank
 *
 * | situation | what renders |
 * | --- | --- |
 * | `/jadwal` in flight | `SkeletonRows` behind an announced loading label |
 * | `/jadwal` failed | `ErrorState` with `Coba lagi`; hero, CTA and credentials stay |
 * | no upcoming window | `Belum ada jadwal tersedia.` + `Lihat profil lain` + `Hubungi bantuan` |
 * | windows exist | the upcoming-day list, plus `Slot terdekat: ...` when one is open |
 *
 * The empty state deliberately does **not** hide the page's `Pesan jadwal` CTA: booking
 * loads its own availability, so "no published schedule" on the profile is not proof the
 * booking screen will be empty (F04 §6).
 *
 * ## No `/ulasan` call
 *
 * The reviews endpoint is backend-blocked (F04 blocker #1), and this component queries only
 * the two schedule routes. There is no rating summary, distribution or review list here -
 * inventing either would be the fake UI the pattern forbids.
 */
export function SchedulePreview({ dokterId }: { dokterId: string }) {
    const jadwal = useQuery(jadwalOptions(dokterId));

    const rencana = useMemo(
        () =>
            jadwal.data === undefined
                ? []
                : jadwalMendatang(jadwal.data.data.jadwal ?? {}),
        [jadwal.data],
    );

    const terdekat = rencana[0] ?? null;

    const slot = useQuery({
        ...slotOptions(dokterId, terdekat?.tanggal ?? ''),
        enabled: terdekat !== null,
    });

    /**
     * The first slot the **server** marked available on the nearest day. A day whose slots
     * are all full (`tersedia: false`, `alasan: 'penuh'`) leaves this null, and the copy
     * then makes no claim the API did not make.
     */
    const slotTerdekat = useMemo(() => {
        if (terdekat === null || slot.data === undefined) {
            return null;
        }

        const row = (slot.data.data.slots ?? []).find(
            (kandidat) => kandidat.tersedia,
        );

        if (row === undefined) {
            return null;
        }

        return `${labelHariRelatif(terdekat.tanggal)} ${formatJamZona(
            row.jam_mulai,
            terdekat.tanggal,
        )}`;
    }, [slot.data, terdekat]);

    return (
        <Card id="jadwal-terdekat" data-slot="schedule-preview">
            <CardHeader>
                <CardTitle className="text-base">Jadwal terdekat</CardTitle>

                <p className="text-muted-foreground text-sm">
                    {`Waktu ditampilkan dalam ${labelZona(zonaPerangkat())}.`}
                </p>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {jadwal.isPending ? (
                    <LoadingState label="Memuat jadwal dokter...">
                        <SkeletonRows rows={2} />
                    </LoadingState>
                ) : null}

                {jadwal.isError ? (
                    <ErrorState
                        title="Jadwal belum dapat dimuat."
                        error={jadwal.error}
                        onRetry={() => {
                            void jadwal.refetch();
                        }}
                    />
                ) : null}

                {jadwal.isSuccess && rencana.length === 0 ? (
                    <EmptyState
                        compact
                        title="Belum ada jadwal tersedia."
                        description="Dokter ini belum memublikasikan jadwal rutin. Anda tetap dapat mencoba memesan, atau melihat dokter lain."
                        action={
                            <div className="flex flex-wrap items-center justify-center gap-2">
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <Link to="/?direktori=semua">Lihat profil lain</Link>
                                </Button>

                                {/**
                                 * No in-app help route exists yet, so the only honest
                                 * contact channel that does not fabricate a page is the
                                 * product's own support mailbox. Recorded in the report as
                                 * an open item rather than silently pointing at a 404.
                                 */}
                                <Button
                                    asChild
                                    variant="outline"
                                    className="min-h-11"
                                >
                                    <a href="mailto:bantuan@sehatly.id">
                                        <LifeBuoy aria-hidden />
                                        Hubungi bantuan
                                    </a>
                                </Button>
                            </div>
                        }
                    />
                ) : null}

                {jadwal.isSuccess && rencana.length > 0 ? (
                    <div className="flex flex-col gap-3">
                        <ul
                            aria-label="Jadwal dokter yang akan datang"
                            className="flex flex-col gap-2"
                        >
                            {rencana.map(({ tanggal, jendela }) =>
                                jendela.map((row, index) => (
                                    <li
                                        key={`${tanggal}-${row.jadwal_id}-${row.jam_mulai}`}
                                        className="flex flex-wrap items-baseline gap-x-2 text-base"
                                        data-slot="schedule-day"
                                    >
                                        {index > 0 ? (
                                            <span className="sr-only">
                                                {labelHariTanggal(tanggal)}
                                            </span>
                                        ) : (
                                            <span className="font-medium">
                                                {labelHariTanggal(tanggal)}
                                            </span>
                                        )}

                                        <span
                                            aria-hidden
                                            className="text-muted-foreground"
                                        >
                                            •
                                        </span>

                                        <span className="tabular-nums">
                                            {formatRentangJamZona(
                                                row.jam_mulai,
                                                row.jam_selesai,
                                                tanggal,
                                            )}
                                        </span>
                                    </li>
                                )),
                            )}
                        </ul>

                        {slotTerdekat === null ? null : (
                            <p
                                className="flex flex-wrap items-center gap-x-2 text-base"
                                data-slot="slot-terdekat"
                            >
                                <CalendarClock
                                    aria-hidden
                                    className="text-muted-foreground size-4 shrink-0"
                                />

                                <span>
                                    Slot terdekat:{' '}
                                    <span className="font-medium tabular-nums">
                                        {slotTerdekat}
                                    </span>
                                </span>
                            </p>
                        )}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

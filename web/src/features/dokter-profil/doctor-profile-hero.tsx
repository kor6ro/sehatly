import { Link } from 'react-router';
import {
    Building2,
    CalendarCheck,
    CalendarDays,
    ChevronDown,
    Info,
} from 'lucide-react';
import type { DokterDetail } from '@/lib/api/types';
import { labelTipeDokter } from '@/lib/api/dokter';
import { formatRupiah } from '@/lib/format';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { DoctorTrustBadge } from '@/features/dokter-profil/doctor-trust-badge';

/**
 * The top of `/dokter/:id`: identity, trust, cost, venue and the booking CTA.
 *
 * ## Everything a patient decides with, above the fold
 *
 * F04 §1 makes all five pre-commitment facts - name, specialisation, experience, cost and
 * the primary facility - a success metric: they must be readable on a 390 px screen without
 * scrolling, and `Pesan jadwal` must be one tap away (AC-1). The card is therefore ordered
 * by that decision, not by the shape of `DokterDetailResource`:
 *
 * | row | answers |
 * | --- | --- |
 * | name + badge + type + specialisation | who is this, and who vouches for them? |
 * | experience + consultations | do they actually practise? |
 * | three fees and the duration | what does it cost, and how long? |
 * | primary facility (+ full list) | where do they practise? |
 * | the two CTAs | what happens next? |
 *
 * ## Cost is body text, never truncated
 *
 * `web/AGENTS.md` makes a fee medical-adjacent data that must be readable and never
 * ellipsised, and F04 §7 #12 asks for >= 16 px on credentials and cost. The fee paragraphs
 * are `text-base` with `tabular-nums` so `Rp 85.000` and `Rp 120.000` align, and no
 * `truncate`/`line-clamp` appears anywhere in this component.
 *
 * ## The offline CTA stays focusable and says why
 *
 * When `navigator.onLine` is false the primary CTA carries `aria-disabled="true"` and points
 * at the page's visible offline reason through `aria-describedby`; its click handler returns
 * before the router sees it. It is deliberately **not** the native `disabled` attribute,
 * because a disabled anchor leaves the tab order and can never be asked why it does nothing
 * (`_global.md` §7 #1, F04 AC-10).
 *
 * ## The STR/SIP note is the existing text, verbatim
 *
 * The final paragraph is the current page's own sentence, unchanged. It names what is
 * withheld instead of leaving a patient to wonder whether the page failed to load a licence
 * number, and it keeps the privacy boundary visible without publishing anything.
 */
export function DoctorProfileHero({
    dokter,
    online,
}: {
    dokter: DokterDetail;
    online: boolean;
}) {
    const spesialisasiUtama = dokter.spesialisasi.find((row) => row.is_utama);
    const faskesUtama = dokter.faskes.find((row) => row.is_utama) ?? dokter.faskes[0];

    return (
        <Card data-slot="doctor-hero">
            <CardContent className="flex flex-col gap-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
                    {/**
                     * Decorative on purpose: the name is the heading beside it, so the
                     * avatar is hidden from the accessibility tree rather than read as a
                     * second, letter-spelled copy of the same identity. The fallback is
                     * initials, never a broken-image icon (F04 §7 #13).
                     */}
                    <Avatar aria-hidden className="size-16 sm:size-20">
                        {dokter.foto_profil === null ? null : (
                            <AvatarImage src={dokter.foto_profil} alt="" />
                        )}

                        <AvatarFallback className="text-lg font-medium">
                            {inisial(dokter.nama_lengkap)}
                        </AvatarFallback>
                    </Avatar>

                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-xl font-semibold tracking-tight">
                                {dokter.nama_lengkap}
                            </h2>

                            <DoctorTrustBadge status={dokter.status_verifikasi} />
                        </div>

                        <p className="text-muted-foreground text-sm">
                            {labelTipeDokter(dokter.tipe)}
                            {spesialisasiUtama?.nama === null ||
                            spesialisasiUtama?.nama === undefined
                                ? ' • Spesialisasi belum dicatat'
                                : ` • ${spesialisasiUtama.nama}`}
                        </p>

                        <p className="text-base">
                            {dokter.pengalaman_tahun === null
                                ? 'Pengalaman belum dicatat'
                                : `${String(dokter.pengalaman_tahun)} tahun pengalaman`}
                            {' • '}
                            {`${String(dokter.jumlah_konsultasi)} konsultasi`}
                        </p>
                    </div>
                </div>

                <Separator />

                <div className="grid gap-3 sm:grid-cols-3">
                    <p className="text-base">
                        Konsultasi online{' '}
                        <span className="font-semibold tabular-nums">
                            {formatRupiah(dokter.biaya_konsultasi_online)}
                        </span>
                    </p>

                    <p className="text-base">
                        Di luar jam{' '}
                        <span className="font-semibold tabular-nums">
                            {formatRupiah(dokter.biaya_luar_jam)}
                        </span>
                    </p>

                    <p className="text-base">
                        Durasi{' '}
                        <span className="font-semibold">
                            {dokter.durasi_default_menit === null
                                ? '-'
                                : `${String(dokter.durasi_default_menit)} menit`}
                        </span>
                    </p>
                </div>

                <Separator />

                <div className="flex flex-col gap-2">
                    <p className="text-base">
                        <Building2
                            aria-hidden
                            className="text-muted-foreground mr-1.5 inline size-4"
                        />

                        {faskesUtama === undefined ? (
                            'Belum ada afiliasi'
                        ) : (
                            <>
                                {faskesUtama.nama ?? '-'}
                                {faskesUtama.alamat === null
                                    ? ''
                                    : ` • ${faskesUtama.alamat}`}
                            </>
                        )}
                    </p>

                    {dokter.faskes.length > 1 ? (
                        <Collapsible>
                            <CollapsibleTrigger asChild>
                                <button
                                    type="button"
                                    data-slot="facility-trigger"
                                    className="group focus-visible:ring-ring hover:bg-accent hover:text-accent-foreground flex min-h-11 w-fit items-center gap-2 rounded-md px-1 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    Lihat semua fasilitas

                                    <ChevronDown
                                        aria-hidden
                                        className="size-4 transition-transform group-data-[state=open]:rotate-180"
                                    />
                                </button>
                            </CollapsibleTrigger>

                            <CollapsibleContent>
                                <ul className="flex flex-col gap-3 pt-3">
                                    {dokter.faskes.map((row) => (
                                        <li
                                            key={row.faskes_id}
                                            className="flex flex-col gap-0.5 text-sm"
                                            data-slot="facility-row"
                                        >
                                            <span className="font-medium">
                                                {row.nama ?? '-'}
                                            </span>

                                            <span className="text-muted-foreground">
                                                {row.alamat ?? 'Alamat belum dicatat'}
                                            </span>

                                            {row.status_aktif ? null : (
                                                <Badge
                                                    variant="outline"
                                                    className="mt-1 w-fit"
                                                >
                                                    Afiliasi tidak aktif
                                                </Badge>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </CollapsibleContent>
                        </Collapsible>
                    ) : null}
                </div>

                <Separator />

                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <Button
                            asChild
                            className="min-h-11"
                            data-slot="cta-pesan-jadwal"
                            aria-disabled={!online}
                            aria-describedby={
                                online ? undefined : 'dokter-alasan-offline'
                            }
                            onClick={(event) => {
                                if (!online) {
                                    event.preventDefault();
                                }
                            }}
                        >
                            <Link to={`/booking/${String(dokter.id)}`}>
                                <CalendarCheck aria-hidden />
                                Pesan jadwal
                            </Link>
                        </Button>

                        <Button
                            asChild
                            variant="outline"
                            className="min-h-11"
                            data-slot="cta-jadwal-lengkap"
                        >
                            {/**
                             * A same-page anchor: the full upcoming schedule is the
                             * `Jadwal terdekat` block rendered immediately below, so
                             * "lihat jadwal lengkap" scrolls to it instead of sending the
                             * patient to a second screen for information already on this
                             * one.
                             */}
                            <a href="#jadwal-terdekat">
                                <CalendarDays aria-hidden />
                                Lihat jadwal lengkap
                            </a>
                        </Button>
                    </div>

                    <p className="text-muted-foreground flex items-start gap-2 text-sm">
                        <Info aria-hidden className="mt-0.5 size-4 shrink-0" />

                        Nomor STR, nomor SIP, berkas STR, dan kontak langsung dokter
                        tidak dipublikasikan. Kontak pasien ke dokter dilakukan melalui
                        pemesanan konsultasi.
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * The avatar fallback initials: `dr. Rina Wulandari, Sp.A` -> `RW`.
 *
 * Professional prefixes (`dr.`, `drg.`, `Ns.`, ...) and trailing academic titles are dropped
 * so the fallback reads as a person's initials rather than `D` or `DS`, and the result is
 * capped at two letters. Anything unparseable falls back to a single leading character
 * rather than an empty circle.
 */
function inisial(nama: string): string {
    const tanpaGelarDepan = nama.replace(
        /\b(dr|drg|ns|apt|prof|bidan|psikolog)\.?\s+/gi,
        '',
    );

    const kata = tanpaGelarDepan
        .split(/\s+/)
        .map((bagian) => bagian.replace(/[^\p{L}]/gu, ''))
        .filter((bagian) => bagian.length > 0);

    if (kata.length === 0) {
        return nama.slice(0, 1).toUpperCase();
    }

    return kata
        .slice(0, 2)
        .map((bagian) => bagian.slice(0, 1).toUpperCase())
        .join('');
}

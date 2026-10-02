import {
    BadgeCheck,
    Building2,
    ChevronDown,
    GraduationCap,
    ShieldCheck,
} from 'lucide-react';
import type { DokterDetail } from '@/lib/api/types';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { EmptyState } from '@/components/states/empty-state';

/**
 * `Kredensial & verifikasi` - what Sehatly checked, in a collapsed panel.
 *
 * ## It collapses the detail, not the status
 *
 * The trigger is a 44 px button whose label is the section name; the expanded body carries
 * the status sentence, the three credential groups and the verification scope. F04 §2 step
 * 2 wants "what was verified" available, and §7 #1 forbids a dead end, so every group has
 * an honest fallback (`Spesialisasi belum dicatat`, `Riwayat pendidikan belum dicatat`,
 * `Belum ada afiliasi`) instead of an empty region.
 *
 * ## No registration number, and the reason is stated rather than hidden
 *
 * `DokterDetailResource` deliberately withholds `nomor_str`, `nomor_sip` and the `file_*`
 * document URLs, and the owner decision on publishing a masked number is still open
 * (F04 §12 #1). Until it is decided, the panel says exactly what is true today - "Kami
 * memverifikasi STR dan SIP sebelum profil ini tayang." - and no KKI link is rendered,
 * because a link whose number does not exist on the page is decoration. The component is
 * also forbidden from writing "Dokter ini pasti aman": only `Terverifikasi` plus what was
 * checked.
 *
 * ## `text-success` is on the icon only
 *
 * Status must be text + icon + colour (`web/AGENTS.md`), and the success hue at small sizes
 * is close to the 4.5:1 floor for text. The words stay `--foreground`; only the glyph
 * carries the hue, so contrast never depends on the status colour.
 */
export function CredentialPanel({ dokter }: { dokter: DokterDetail }) {
    const spesialisasiUtama = dokter.spesialisasi.find((row) => row.is_utama);
    const spesialisasiLain = dokter.spesialisasi.filter((row) => !row.is_utama);

    return (
        <Card data-slot="credential-panel">
            <Collapsible>
                <CardHeader>
                    <CollapsibleTrigger asChild>
                        <button
                            type="button"
                            data-slot="credential-trigger"
                            className="group focus-visible:ring-ring hover:bg-accent hover:text-accent-foreground -mx-2 flex min-h-11 w-[calc(100%+1rem)] items-center justify-between gap-2 rounded-md px-2 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <span className="flex items-center gap-2 text-base font-semibold">
                                <ShieldCheck aria-hidden className="size-4 shrink-0" />

                                Kredensial &amp; verifikasi
                            </span>

                            <ChevronDown
                                aria-hidden
                                className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-180"
                            />
                        </button>
                    </CollapsibleTrigger>
                </CardHeader>

                <CollapsibleContent>
                    <CardContent className="flex flex-col gap-5 pt-4">
                        <div className="flex flex-col gap-1.5">
                            <p
                                className="flex items-center gap-2 text-base font-medium"
                                data-slot="credential-status"
                            >
                                <BadgeCheck
                                    aria-hidden
                                    className="text-success size-4 shrink-0"
                                />

                                {dokter.status_verifikasi === 'terverifikasi'
                                    ? 'Status: Terverifikasi Sehatly'
                                    : `Status: ${dokter.status_verifikasi}`}
                            </p>

                            <p className="text-muted-foreground text-sm">
                                Kami memverifikasi STR dan SIP sebelum profil ini
                                tayang.
                            </p>
                        </div>

                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">Spesialisasi</h3>

                            {dokter.spesialisasi.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Spesialisasi belum dicatat"
                                    description="Belum ada relasi spesialisasi untuk dokter ini."
                                />
                            ) : (
                                <div className="flex flex-wrap items-center gap-2">
                                    {spesialisasiUtama === undefined ? null : (
                                        <Badge>{spesialisasiUtama.nama ?? '-'}</Badge>
                                    )}

                                    {spesialisasiLain.map((row) => (
                                        <Badge
                                            key={row.id ?? row.kode ?? row.nama}
                                            variant="secondary"
                                        >
                                            {row.nama ?? '-'}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">Pendidikan</h3>

                            {dokter.pendidikan.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Riwayat pendidikan belum dicatat"
                                    description="Belum ada data jenjang atau institusi untuk dokter ini."
                                />
                            ) : (
                                <ul className="flex flex-col gap-2">
                                    {dokter.pendidikan.map((row) => (
                                        <li
                                            key={row.id}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <GraduationCap
                                                aria-hidden
                                                className="mt-0.5 size-4 shrink-0"
                                            />

                                            <span>
                                                {row.jenjang ?? '-'} -{' '}
                                                {row.institusi ?? '-'}
                                                {row.tahun_lulus === null
                                                    ? ''
                                                    : ` (${String(row.tahun_lulus)})`}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                Faskes terafiliasi
                            </h3>

                            {dokter.faskes.length === 0 ? (
                                <EmptyState
                                    compact
                                    title="Belum ada afiliasi"
                                    description="Dokter ini tidak tertaut ke fasilitas kesehatan mana pun."
                                />
                            ) : (
                                <ul className="flex flex-col gap-3">
                                    {dokter.faskes.map((row) => (
                                        <li
                                            key={row.faskes_id}
                                            className="flex flex-col gap-0.5 text-sm"
                                        >
                                            <span className="flex items-center gap-2 font-medium">
                                                <Building2
                                                    aria-hidden
                                                    className="size-4 shrink-0"
                                                />

                                                {row.nama ?? '-'}
                                            </span>

                                            <span className="text-muted-foreground">
                                                {row.kode_faskes ?? '-'}
                                                {row.kelas_rs === null
                                                    ? ''
                                                    : ` - kelas ${row.kelas_rs}`}
                                            </span>

                                            {row.alamat === null ? null : (
                                                <span className="text-muted-foreground">
                                                    {row.alamat}
                                                </span>
                                            )}

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
                            )}
                        </section>
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

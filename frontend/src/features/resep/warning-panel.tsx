import { AlertTriangle, FlaskConical, History, ShieldAlert } from 'lucide-react';
import {
    LABEL_SUMBER_PERINGATAN,
    LABEL_TINGKAT_PERINGATAN,
    jumlahPeringatan,
    pasanganPeringatan,
} from '@/lib/api/resep';
import { SUMBER_PERINGATAN, type PeringatanGrup, type SumberPeringatan } from '@/lib/api/types';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * The drug-interaction panel: every warning the server returned, grouped by `sumber`.
 *
 * ## Three groups, always, and they are VISUALLY distinct on purpose
 *
 * `ObatInteraksiService::SUMBER` is three values and `peringatanGrup()` fills all three
 * keys before returning, so the panel renders three regions unconditionally - an empty
 * group says "checked, nothing found" rather than disappearing. That distinction is the
 * point: "no allergy interaction" and "allergies never checked" are different facts, and
 * a panel that hides its empty groups cannot tell them apart.
 *
 * Each group carries its own accent so a doctor reads three separate CLINICAL questions,
 * not one undifferentiated list:
 *
 * | `sumber` | the question it answers |
 * | --- | --- |
 * | `antar_item` | do the drugs I am prescribing right now clash with each other? |
 * | `riwayat_resep` | does this clash with a course the patient is already on? |
 * | `alergi` | is this drug on the patient's recorded allergy list? |
 *
 * `data-gaya` is the machine-readable name of that treatment, so the distinction is
 * assertable rather than only visible.
 *
 * ## A `kontraindikasi` is BLOCKING, and it does not block the item
 *
 * It blocks the COMMIT, never the composition. The plan's rule is "warn, never silently
 * drop", and the panel honours both halves: a drug that clashes is still added, the
 * warning travels with it, and the decision to proceed is a separate, recorded act (see
 * `ResepComposer`'s gate). A panel that removed the offending drug would hide the
 * doctor's choice from the pharmacist, which is the opposite of what this feature is for.
 */

type GayaGrup = 'antar-item' | 'riwayat-resep' | 'alergi';

const GAYA: Readonly<Record<SumberPeringatan, GayaGrup>> = {
    antar_item: 'antar-item',
    riwayat_resep: 'riwayat-resep',
    alergi: 'alergi',
};

/**
 * The border that separates each group.
 *
 * A left rule in a different hue per source, rather than three different background
 * tints. The panel can hold a red `kontraindikasi` and a yellow `sedang` at once, and
 * three tinted backgrounds would fight the severity colours for the reader's attention -
 * the severity is the thing that must dominate, and the source is the thing that must
 * stay legible behind it.
 */
const GARIS: Readonly<Record<SumberPeringatan, string>> = {
    antar_item: 'border-l-primary',
    riwayat_resep: 'border-l-chart-2',
    alergi: 'border-l-chart-4',
};

const IKON: Readonly<Record<SumberPeringatan, typeof FlaskConical>> = {
    antar_item: FlaskConical,
    riwayat_resep: History,
    alergi: ShieldAlert,
};

/**
 * The severity treatment, worst first.
 *
 * `kontraindikasi` gets the `destructive` variant, the same one every refusal state in this
 * project uses. `berat` gets `outline` plus the `--color-warning` token, which exists in
 * `src/styles/app.css` for exactly this kind of escalation; `components/ui/badge.tsx` is
 * relocated registry code that this todo must not edit, so the token is applied through
 * `className` rather than by inventing a variant nobody else can use.
 *
 * `sedang` and `ringan` stay neutral on purpose. A `ringan` rendered in an alarm colour
 * trains a doctor to ignore alarm colours, and a `kontraindikasi` that looks like the rest
 * of the list is not a warning.
 */
const GAYA_TINGKAT: Readonly<Record<string, { variant: 'destructive' | 'secondary' | 'outline'; className?: string }>> = {
    kontraindikasi: { variant: 'destructive' },
    berat: { variant: 'outline', className: 'border-warning text-warning' },
    sedang: { variant: 'secondary' },
    ringan: { variant: 'outline' },
};

export function WarningPanel({
    grup,
    className,
    /** Hide the per-group regions and show only the summary. Used inside the gate. */
    ringkas = false,
}: {
    grup: PeringatanGrup;
    className?: string;
    ringkas?: boolean;
}) {
    const total = jumlahPeringatan(grup);
    const adaKontraindikasi = grup.antar_item
        .concat(grup.riwayat_resep, grup.alergi)
        .some((p) => p.wajib_catatan_dokter);

    return (
        <div
            data-slot="warning-panel"
            data-total={total}
            data-kontraindikasi={adaKontraindikasi}
            className={cn('flex flex-col gap-3', className)}
        >
            {adaKontraindikasi ? (
                <Alert variant="destructive" data-slot="warning-panel-mandatory">
                    <AlertTriangle />

                    <AlertTitle>Peringatan kontraindikasi</AlertTitle>

                    <AlertDescription>
                        <p>
                            Ada kombinasi obat yang tidak boleh diberikan bersama. Resep
                            hanya tersimpan bila dokter mengisi catatan pengakuan.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            {ringkas ? null : (
                <>
                    {SUMBER_PERINGATAN.map((sumber) => (
                        <WarningGroup key={sumber} sumber={sumber} grup={grup} />
                    ))}
                </>
            )}
        </div>
    );
}

function WarningGroup({
    sumber,
    grup,
}: {
    sumber: SumberPeringatan;
    grup: PeringatanGrup;
}) {
    const isi = grup[sumber];
    const Ikon = IKON[sumber];

    return (
        <section
            data-slot="warning-grup"
            data-sumber={sumber}
            data-gaya={GAYA[sumber]}
            data-jumlah={isi.length}
            aria-label={LABEL_SUMBER_PERINGATAN[sumber] ?? sumber}
            className={cn(
                'border-border bg-muted/30 flex flex-col gap-2 rounded-md border border-l-4 px-3 py-2',
                GARIS[sumber],
            )}
        >
            <h4 className="flex flex-wrap items-center gap-2 text-sm font-semibold">
                <Ikon aria-hidden className="size-4 shrink-0" />

                {LABEL_SUMBER_PERINGATAN[sumber] ?? sumber}

                <Badge variant="outline" className="tabular-nums">
                    {isi.length}
                </Badge>
            </h4>

            {isi.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Tidak ada peringatan dari sumber ini.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {isi.map((p) => (
                        <li
                            key={p.kunci}
                            data-slot="warning-item"
                            data-tingkat={p.tingkat}
                            data-wajib-catatan={p.wajib_catatan_dokter}
                            className="bg-background flex flex-col gap-1 rounded-md border px-3 py-2"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={
                                        GAYA_TINGKAT[p.tingkat]?.variant ?? 'secondary'
                                    }
                                    className={
                                        GAYA_TINGKAT[p.tingkat]?.className
                                    }
                                >
                                    {LABEL_TINGKAT_PERINGATAN[p.tingkat] ??
                                        p.tingkat}
                                </Badge>

                                <span className="text-sm font-medium">
                                    {pasanganPeringatan(p)}
                                </span>
                            </div>

                            {p.deskripsi === null ? null : (
                                <p className="text-muted-foreground text-xs">
                                    {p.deskripsi}
                                </p>
                            )}

                            {/**
                             * The matched core, and only on an `alergi` warning.
                             *
                             * `pasien_alergi.nama_alergen` is free text and is NOT a
                             * foreign key to `master_obat`, so the match is best-effort
                             * name normalisation. Without the core the doctor cannot
                             * answer "why did this fire?", which is the one question a
                             * warning exists to raise. `baris_tertemu` is not rendered for
                             * the pair warnings: two rows collapsed into one panel is a
                             * deduplication detail, not a clinical fact.
                             */}
                            {p.sumber === 'alergi' &&
                            typeof p.rincian.inti_cocok === 'string' ? (
                                <p className="text-muted-foreground text-xs">
                                    Cocok pada: {p.rincian.inti_cocok}
                                    {typeof p.rincian.nama_alergen === 'string'
                                        ? ` (tercatat: ${p.rincian.nama_alergen})`
                                        : ''}
                                </p>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

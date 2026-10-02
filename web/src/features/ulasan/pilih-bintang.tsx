import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * An accessible star picker.
 *
 * ## Real radios, not styled buttons
 *
 * F04's write surface must be operable as a radio group with text labels. Five native
 * `<input type="radio">` elements inside one `<fieldset>`/`<legend>` give arrow-key
 * navigation, a single focus stop and a screen-reader group announcement for free -
 * things a row of `role="radio"` buttons would each have to re-implement. The radio
 * itself is `sr-only`; the label is the visible 44 px target, and `focus-within` draws
 * its ring so keyboard focus is never invisible.
 *
 * ## Selected state is a fill, not a coloured border
 *
 * The selected pill is `bg-primary text-primary-foreground` - a tonal state the design
 * system already owns - rather than a primary-tinted outline, which is the accent-border
 * anti-pattern the frontend rules forbid. The star keeps the `--warning` fill in both
 * states so the option reads as "a star rating" even before selection; the text label
 * says `{n} bintang`, so selection is never conveyed by colour alone.
 *
 * ## Optional groups can be cleared
 *
 * `rating_komunikasi` and `rating_akurasi` are `nullable` in `SimpanUlasanRequest`, so
 * an optional group offers a `Tidak diisi` radio. Without it a mis-click on an optional
 * group could never be undone, because native radios cannot be unchecked by clicking.
 */
export function PilihBintang({
    name,
    legend,
    value,
    onChange,
    opsional = false,
    slot,
    error = null,
    hint,
}: {
    name: string;
    legend: string;
    value: number | null;
    onChange: (next: number | null) => void;
    /** Renders a `Tidak diisi` option instead of requiring a score. */
    opsional?: boolean;
    /** The `data-slot` the e2e spec scopes the group by. */
    slot: string;
    /** A field-level message, rendered as an alert under the group. */
    error?: string | null;
    hint?: string;
}) {
    const kelasOpsi = (terpilih: boolean): string =>
        cn(
            'focus-within:ring-ring/50 focus-within:ring-[3px] flex min-h-11 cursor-pointer items-center gap-1.5 rounded-md border px-3 py-2 transition-colors',
            terpilih
                ? 'border-primary bg-primary text-primary-foreground'
                : 'hover:bg-accent',
        );

    return (
        <fieldset data-slot={slot} className="flex flex-col gap-2">
            <legend className="text-base font-medium">
                {legend}
                {opsional ? (
                    <span className="text-muted-foreground font-normal">
                        {' '}
                        (opsional)
                    </span>
                ) : null}
            </legend>

            {hint === undefined ? null : (
                <p className="text-muted-foreground text-sm">{hint}</p>
            )}

            <div className="flex flex-wrap gap-2">
                {opsional ? (
                    <label className={kelasOpsi(value === null)}>
                        <input
                            type="radio"
                            name={name}
                            value="0"
                            checked={value === null}
                            onChange={() => {
                                onChange(null);
                            }}
                            className="sr-only"
                        />

                        <span className="text-base">Tidak diisi</span>
                    </label>
                ) : null}

                {[1, 2, 3, 4, 5].map((bintang) => {
                    const terpilih = value === bintang;

                    return (
                        <label key={bintang} className={kelasOpsi(terpilih)}>
                            <input
                                type="radio"
                                name={name}
                                value={bintang}
                                checked={terpilih}
                                onChange={() => {
                                    onChange(bintang);
                                }}
                                className="sr-only"
                            />

                            <Star
                                aria-hidden
                                className="text-warning fill-warning size-5 shrink-0"
                            />

                            <span className="text-base">{bintang} bintang</span>
                        </label>
                    );
                })}
            </div>

            {error === null ? null : (
                <p role="alert" className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </fieldset>
    );
}

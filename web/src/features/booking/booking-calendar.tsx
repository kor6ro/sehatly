import { useMemo, useState } from 'react';
import { DayPicker } from '@daypicker/react';
import { id as localeId } from '@daypicker/react/locale';
import '@daypicker/react/style.css';
import { dateKeTanggal, tanggalKeDate, hariIni } from '@/lib/tanggal';
import type { TanggalSlot } from '@/lib/api/jadwal';
import { cn } from '@/lib/utils';

/**
 * The consultation-date half of the booking flow.
 *
 * ## The import is `@daypicker/react`, and that is not cosmetic
 *
 * `package.json` declares `"@daypicker/react": "^10.0.1"`. The v10 line moved the React
 * entry point out of `react-day-picker`, and the old specifier now resolves to a
 * **deprecated shim** that forwards here. Importing the old name would satisfy the
 * compiler and ship a package that is on a removal path, so the plan's acceptance
 * criterion - `grep -rn "from 'react-day-picker'" web/src` returns nothing - is a
 * real constraint on this import line and not a style preference.
 *
 * The base stylesheet is imported for **layout only**. Every colour here comes from the
 * project's own tokens through `classNames`, so the calendar cannot introduce a second
 * palette next to the rest of the app.
 *
 * ## Why no `endMonth`
 *
 * An upper bound on how far ahead a patient may book is policy, and no endpoint or plan
 * line states one. Inventing a 90-day or 12-month horizon here would be a client-side
 * refusal the server does not make, and it would silently hide days a patient could
 * legitimately reach. The only bound applied is the lower one, and it is not invented
 * either: a consultation cannot happen yesterday, so `startMonth` is today.
 */
export function BookingCalendar({
    value,
    onChange,
    disabled = false,
    className,
}: {
    /** `Y-m-d`, or `null` for "no date chosen yet". */
    value: TanggalSlot | null;
    onChange: (tanggal: TanggalSlot) => void;
    disabled?: boolean;
    className?: string;
}) {
    const hariINI = useMemo(hariIni, []);
    const bulanIni = useMemo(() => tanggalKeDate(hariINI), [hariINI]);
    const [bulan, setBulan] = useState<Date | undefined>(bulanIni);

    const terpilih = useMemo(() => tanggalKeDate(value), [value]);

    return (
        <div
            className={cn(
                'bg-card text-card-foreground rounded-lg border p-3',
                className,
            )}
        >
            <DayPicker
                mode="single"
                selected={terpilih}
                onSelect={(selected) => {
                    /**
                     * The one conversion the plan insists on. DayPicker hands back a
                     * `Date` in local calendar fields; it is turned into a `Y-m-d`
                     * string here and nowhere else, and never handed to the API as a
                     * `Date`. See `lib/tanggal.ts` for why the `toISOString()` route
                     * loses a day in Asia/Jakarta.
                     */
                    if (selected === undefined) {
                        return;
                    }

                    onChange(dateKeTanggal(selected));
                }}
                month={bulan}
                onMonthChange={setBulan}
                startMonth={bulanIni}
                locale={localeId}
                disabled={disabled ? true : { before: new Date() }}
                showOutsideDays={false}
                fixedWeeks={false}
                aria-label="Pilih tanggal kunjungan"
                classNames={{
                    root: 'w-full',
                    months: 'flex flex-col gap-4',
                    month: 'flex flex-col gap-3',
                    month_caption:
                        'text-foreground flex items-center justify-between gap-2 font-medium',
                    caption_label: 'text-sm',
                    nav: 'flex items-center gap-1',
                    button_previous:
                        'border-input bg-background hover:bg-accent hover:text-accent-foreground inline-flex size-8 items-center justify-center rounded-md border',
                    button_next:
                        'border-input bg-background hover:bg-accent hover:text-accent-foreground inline-flex size-8 items-center justify-center rounded-md border',
                    chevron: 'size-4 fill-current',
                    weekdays: 'grid grid-cols-7 gap-1',
                    weekday:
                        'text-muted-foreground text-xs font-normal opacity-100',
                    week: 'grid grid-cols-7 gap-1',
                    day: 'text-center',
                    day_button:
                        'hover:bg-accent hover:text-accent-foreground data-selected:bg-primary data-selected:text-primary-foreground size-9 rounded-md text-sm font-normal transition-colors',
                    selected: 'font-medium',
                    today: 'font-semibold underline underline-offset-4',
                    disabled:
                        'text-muted-foreground opacity-40 hover:bg-transparent',
                    outside: 'invisible',
                    hidden: 'invisible',
                }}
            />

            <p className="text-muted-foreground mt-3 text-xs">
                Tanggal yang sudah lewat tidak dapat dipilih. Ketersediaan jam
                ditentukan oleh server pada tanggal yang dipilih.
            </p>
        </div>
    );
}

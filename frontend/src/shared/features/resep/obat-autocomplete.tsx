import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { obatOptions } from '@/lib/api/resep';
import type { MasterObat } from '@/lib/api/types';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

/**
 * The medicine search that fills a prescription line.
 *
 * ## Why the typed text is kept separate from the query key
 *
 * `obatOptions(filters)` keys on the whole filter object, so a keystroke would be a request
 * per character. The debounce below is what makes the query key advance at most once every
 * {@link DEBOUNCE_MS}, and it is the reason `staleTime: 60_000` in `lib/api/resep.ts` is
 * worth having: a doctor typing "amoxi" runs one request, and the next few queries inside
 * that minute are answered from the cache rather than the catalogue.
 *
 * ## The results are NOT auto-selected
 *
 * A doctor picks a drug. An autocomplete that inserted the first match on a keystroke would
 * be a plausible-looking way to prescribe the wrong antibiotic, and every line of a
 * prescription is a clinical act. The list is a list; choosing is a click.
 */

const DEBOUNCE_MS = 300;

/** Below this length there is no catalogue query at all, so no request is made. */
const MINIMAL_KARAKTER = 2;

export function ObatAutocomplete({
    onPilih,
    disabled = false,
    label = 'Cari obat',
}: {
    onPilih: (obat: MasterObat) => void;
    disabled?: boolean;
    label?: string;
}) {
    const [ketik, setKetik] = useState('');
    const [kirim, setKirim] = useState('');
    const [terbuka, setTerbuka] = useState(false);
    const wadah = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const t = setTimeout(() => {
            setKirim(ketik.trim());
        }, DEBOUNCE_MS);

        return () => {
            clearTimeout(t);
        };
    }, [ketik]);

    /**
     * Close the list on an outside press.
     *
     * `pointerdown` rather than `click`, so a press that starts outside does not first tear
     * the list down and leave the click with nothing to land on.
     */
    useEffect(() => {
        if (!terbuka) {
            return;
        }

        const luar = (peristiwa: PointerEvent): void => {
            if (
                wadah.current !== null &&
                !wadah.current.contains(peristiwa.target as Node)
            ) {
                setTerbuka(false);
            }
        };

        document.addEventListener('pointerdown', luar);

        return () => {
            document.removeEventListener('pointerdown', luar);
        };
    }, [terbuka]);

    const cukup = kirim.length >= MINIMAL_KARAKTER;

    const cari = useQuery({
        ...obatOptions({ search: kirim, per_page: 15 }),
        enabled: cukup,
    });

    const hasil = useMemo(() => cari.data?.data.obat ?? [], [cari.data]);

    return (
        <div
            ref={wadah}
            data-slot="obat-autocomplete"
            className="relative flex flex-col gap-2"
        >
            <label
                htmlFor="obat-autocomplete-input"
                className="flex items-center gap-1.5 text-sm font-medium"
            >
                <Search aria-hidden className="size-4" />

                {label}
            </label>

            <div className="relative">
                <Input
                    id="obat-autocomplete-input"
                    value={ketik}
                    disabled={disabled}
                    placeholder="Nama generik atau nama merek"
                    autoComplete="off"
                    role="combobox"
                    aria-expanded={terbuka && cukup && hasil.length > 0}
                    aria-controls="obat-autocomplete-daftar"
                    onChange={(e) => {
                        setKetik(e.target.value);
                        setTerbuka(true);
                    }}
                    onFocus={() => {
                        setTerbuka(true);
                    }}
                />

                {cari.isFetching ? (
                    <Spinner className="absolute top-1/2 right-3 -translate-y-1/2" />
                ) : null}
            </div>

            {terbuka && cukup ? (
                hasil.length === 0 ? (
                    <p
                        id="obat-autocomplete-daftar"
                        data-slot="obat-autocomplete-daftar"
                        className="bg-popover text-popover-foreground absolute top-full right-0 left-0 z-20 mt-1 rounded-md border px-3 py-2 text-sm shadow-md"
                    >
                        {cari.isPending
                            ? 'Memuat katalog...'
                            : 'Tidak ada obat yang cocok.'}
                    </p>
                ) : (
                    <ul
                        id="obat-autocomplete-daftar"
                        data-slot="obat-autocomplete-daftar"
                        role="listbox"
                        className="bg-popover text-popover-foreground absolute top-full right-0 left-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-md border shadow-md"
                    >
                        {hasil.map((obat) => (
                            <li key={obat.id} role="presentation">
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={false}
                                    data-slot="obat-autocomplete-pilihan"
                                    data-obat-id={obat.id}
                                    disabled={disabled}
                                    className="hover:bg-accent flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left disabled:opacity-50"
                                    onClick={() => {
                                        onPilih(obat);
                                        setKetik('');
                                        setKirim('');
                                        setTerbuka(false);
                                    }}
                                >
                                    <span className="text-sm font-medium">
                                        {obat.nama_generik} {obat.kekuatan ?? ''}{' '}
                                        {obat.bentuk_sediaan}
                                        {obat.nama_brand === null
                                            ? ''
                                            : ` (${obat.nama_brand})`}
                                    </span>

                                    <span className="text-muted-foreground text-xs">
                                        <span className="font-mono">
                                            {obat.kode_obat}
                                        </span>
                                        {' - '}
                                        {obat.kelas_obat}
                                        {obat.requires_resep
                                            ? ' - wajib resep'
                                            : ' - bebas'}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )
            ) : null}
        </div>
    );
}

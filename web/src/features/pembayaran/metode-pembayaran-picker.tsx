import { useQuery } from '@tanstack/react-query';
import { formatRupiah } from '@/lib/format';
import {
    TIPE_METODE_LABEL,
    TIPE_METODE_URUT,
    metodePembayaranOptions,
    perkiraanBiayaAdmin,
} from '@/lib/api/pembayaran';
import type { MetodePembayaran, TipeMetodePembayaran } from '@/lib/api/types';
import { Field, FieldSelect } from '@/components/form/field';
import {
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
} from '@/components/ui/select';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';

/**
 * The payment-method selector, grouped by `master_metode_pembayaran.tipe`.
 *
 * ## Grouping is all the client can do, and the schema is why
 *
 * `master_metode_pembayaran` has no gateway column - only free-text `penyedia` - and
 * `BayarInvoiceRequest` does not accept a `gateway` at all. The server picks the gateway
 * from `config('payment.gateway_pembayaran')`, so the browser cannot choose a provider and
 * must not present a control that looks like it could.
 *
 * ## Nine groups, not the five the plan names
 *
 * The `tipe` ENUM declares nine members and `MetodePembayaranSeeder` ships rows across all
 * of them, including `bpjs` and `asuransi`. Grouping is driven by the values the response
 * actually carries, so a new method type appears without a client change.
 */
export function MetodePembayaranPicker({
    value,
    onValueChange,
    subtotal,
    disabled,
}: {
    value: string;
    onValueChange: (value: string) => void;
    /** The order's published `subtotal`, used only to QUOTE the fee, never to apply it. */
    subtotal: number;
    disabled?: boolean;
}) {
    const metode = useQuery(metodePembayaranOptions());

    if (metode.isPending) {
        return <SkeletonRows rows={3} />;
    }

    if (metode.isError) {
        return (
            <ErrorState
                error={metode.error}
                onRetry={() => {
                    void metode.refetch();
                }}
            />
        );
    }

    const semua = metode.data.data.metode_pembayaran;
    const groups = groupByTipe(semua);

    return (
        <Field
            label="Metode pembayaran"
            required
            hint="Fee admin dihitung server dari subtotal dikurangi diskon. Angka di bawah hanya perkiraan."
        >
            <FieldSelect
                data-slot="metode-pembayaran"
                value={value}
                onValueChange={onValueChange}
                placeholder="Pilih metode"
                disabled={disabled === true || semua.length === 0}
            >
                {groups.map((grup, index) => (
                    <SelectGroup key={grup.tipe}>
                        {index === 0 ? null : <SelectSeparator />}

                        <SelectLabel data-slot="metode-grup" data-tipe={grup.tipe}>
                            {TIPE_METODE_LABEL[grup.tipe]}
                        </SelectLabel>

                        {grup.metode.map((m) => (
                            <SelectItem
                                key={m.id}
                                value={String(m.id)}
                                data-slot="metode-pilihan"
                                data-tipe={m.tipe}
                            >
                                {m.nama}
                                {m.penyedia === null ? '' : ` (${m.penyedia})`}
                                {' - '}
                                {formatRupiah(perkiraanBiayaAdmin(m, subtotal))}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </FieldSelect>
        </Field>
    );
}

type GrupMetode = { tipe: TipeMetodePembayaran; metode: MetodePembayaran[] };

/**
 * Group by `tipe` in `TIPE_METODE_URUT`, dropping any group the response did not send.
 *
 * Iterating the response's own types rather than the closed union is what lets a tenth
 * `tipe` member appear in a future seed without this screen silently omitting a method a
 * patient can actually pay with.
 */
function groupByTipe(semua: MetodePembayaran[]): GrupMetode[] {
    const olehTipe = new Map<TipeMetodePembayaran, MetodePembayaran[]>();

    for (const m of semua) {
        const list = olehTipe.get(m.tipe) ?? [];

        list.push(m);

        olehTipe.set(m.tipe, list);
    }

    return [...olehTipe.entries()]
        .sort(
            (a, b) =>
                TIPE_METODE_URUT.indexOf(a[0]) - TIPE_METODE_URUT.indexOf(b[0]),
        )
        .map(([tipe, metode]) => ({ tipe, metode }));
}

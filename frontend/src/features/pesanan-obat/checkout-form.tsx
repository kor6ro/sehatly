import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Loader2, PackageCheck, PackageX, Store } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { formatRupiah, formatTanggal, toNumber } from '@/lib/format';
import { checkoutResepMutation } from '@/lib/api/pesanan-obat';
import type { Resep, StokDiApotek } from '@/lib/api/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldSelect, FormErrorSummary } from '@/components/form/field';
import { SelectItem } from '@/components/ui/select';
import { useStokResep } from '@/features/pesanan-obat/use-stok-resep';

/**
 * The checkout body: the prescription, the pharmacy with enough stock, and the submit.
 *
 * ## The stock table is the honest answer, not a promise
 *
 * Every line shows what `GET /obat/{id}/stok` last said, including the two states a naive
 * panel collapses: a pharmacy that has NEVER stocked the drug (`recorded: false`) is a
 * different fact from one whose shelf is empty (`recorded: true, jumlah_stok: 0`), and only
 * the second is worth revisiting. The submit is shut unless the server said `cukup` for
 * every checkable line, and a racikan - which has no `obat_id` and can therefore never be
 * stock-checked - is shown as exactly that rather than as a zero.
 *
 * ## No total is computed for a discount, because there is none here
 *
 * The order's own money is `subtotal + biaya_kirim`; a promo and an admin fee live on the
 * INVOICE, which does not exist until this form is submitted. So the only number here is
 * the sum of the `resep_item.subtotal` values the server published, labelled as exactly
 * that, and the authoritative total is what `POST /resep/{id}/checkout` returns.
 */
export function CheckoutForm({
    resep,
    onSelesai,
}: {
    resep: Resep;
    onSelesai: (pesananId: number) => void;
}) {
    const [apotekId, setApotekId] = useState<string>('');

    const items = resep.items ?? [];
    const stok = useStokResep(items, apotekId === '' ? null : Number(apotekId));
    const checkout = useMutation(checkoutResepMutation(resep.id));

    const subtotalBaris = items.reduce((total, item) => total + (toNumber(item.subtotal) ?? 0), 0);

    const terkunci = apotekId === '' || !stok.semuaCukup || stok.sedangMemuat;

    const alasanApotek =
        checkout.error instanceof ApiError ? checkout.error.fieldErrors('apotek_id') : [];

    return (
        <Card data-slot="checkout-form" className="flex flex-col">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Store aria-hidden />

                    Rincian pesanan
                </CardTitle>

                <CardDescription>
                    Rincian pesanan dari resep ini. Stok tiap apotek diperiksa
                    ulang saat checkout berlangsung, jadi angka di layar adalah
                    perkiraan, bukan penahanan stok.
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <FormErrorSummary error={checkout.error} />

                <ul data-slot="checkout-items" className="flex flex-col divide-y">
                    {items.map((item) => {
                        const baris = stok.baris.find((b) => b.itemId === item.id);
                        const apotek = baris?.apotek ?? null;

                        return (
                            <li
                                key={item.id}
                                data-slot="checkout-item"
                                data-obat-id={item.obat_id ?? ''}
                                data-cukup={baris?.cukup === null ? 'tidak-dicek' : String(baris?.cukup)}
                                className="flex flex-col gap-1 py-3"
                            >
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <span className="text-sm font-medium">
                                        {item.nama_obat}
                                        {item.kekuatan === null ? '' : ` ${item.kekuatan}`}
                                    </span>

                                    <span className="text-muted-foreground text-xs">
                                        {item.jumlah} {item.satuan ?? ''} x{' '}
                                        {formatRupiah(item.harga_satuan)}
                                    </span>
                                </div>

                                <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                    <span data-slot="checkout-item-subtotal">
                                        Subtotal baris {formatRupiah(item.subtotal)}
                                    </span>
                                </div>

                                <BarisStok
                                    nama={item.nama_obat}
                                    apotek={apotek}
                                    cukup={baris?.cukup ?? null}
                                    jumlah={item.jumlah}
                                />
                            </li>
                        );
                    })}
                </ul>

                <div className="flex items-baseline justify-between border-t pt-3">
                    <span className="text-sm font-medium">Subtotal dari baris resep</span>

                    <span data-slot="checkout-subtotal" className="text-sm font-semibold">
                        {formatRupiah(subtotalBaris)}
                    </span>
                </div>

                <p className="text-muted-foreground text-xs">
                    Angka ini adalah jumlah subtotal seluruh baris resep. Diskon,
                    biaya admin, dan biaya kirim dihitung pada invoice dan
                    ditetapkan saat checkout; tidak ada perhitungannya di layar
                    ini.
                </p>

                {stok.kandidat.length === 0 && !stok.sedangMemuat ? (
                    <p
                        data-slot="checkout-tanpa-apotek"
                        className="text-destructive flex items-start gap-1.5 text-xs"
                    >
                        <PackageX aria-hidden className="mt-0.5 size-3.5 shrink-0" />

                        Tidak ada apotek aktif yang punya cukup stok untuk
                        {stok.tanpaApotek.length === 0
                            ? ' resep ini'
                            : `: ${stok.tanpaApotek.join(', ')}`}
                        . Checkout belum dapat diproses tanpa apotek yang punya
                        stok cukup.
                    </p>
                ) : (
                    <Field
                        label="Apotek"
                        required
                        slot="checkout-apotek"
                        errors={alasanApotek}
                        hint="Daftar apotek menampilkan apotek yang masih memiliki stok obat ini."
                    >
                        <FieldSelect
                            value={apotekId}
                            onValueChange={setApotekId}
                            placeholder="Pilih apotek"
                            disabled={checkout.isPending || stok.kandidat.length === 0}
                        >
                            {stok.kandidat.map((k) => (
                                <SelectItem key={k.apotek_id} value={String(k.apotek_id)}>
                                    {k.nama}
                                </SelectItem>
                            ))}
                        </FieldSelect>
                    </Field>
                )}
            </CardContent>

            <div className="flex items-center justify-end gap-2 border-t p-6">
                <Button
                    data-slot="checkout-submit"
                    type="button"
                    disabled={terkunci || checkout.isPending}
                    onClick={() => {
                        checkout
                            .mutateAsync({ apotek_id: Number(apotekId) })
                            .then((hasil) => {
                                dispatchFlash({ level: 'success', message: hasil.message });

                                onSelesai(hasil.data.pesanan.id);
                            })
                            .catch((error: unknown) => {
                                dispatchFlash({
                                    level: 'error',
                                    message:
                                        error instanceof ApiError
                                            ? error.message
                                            : 'Checkout gagal.',
                                });
                            });
                    }}
                >
                    {checkout.isPending ? <Loader2 className="animate-spin" /> : <PackageCheck />}

                    Buat pesanan
                </Button>
            </div>
        </Card>
    );
}

/**
 * One line's stock state, in the server's own words.
 *
 * Three states and a fourth, and the fourth is the one that matters: `cukup === null`
 * means "not judged yet", which is not the same as "no stock". Rendering it as a failure
 * would tell a patient their prescription is unavailable while the shelf query is still in
 * flight, and rendering it as success would be a lie.
 */
function BarisStok({
    nama,
    apotek,
    cukup,
    jumlah,
}: {
    nama: string;
    apotek: StokDiApotek | null;
    cukup: boolean | null;
    jumlah: number;
}) {
    if (apotek === null) {
        return (
            <p
                data-slot="checkout-stok"
                data-state="belum-dipilih"
                className="text-muted-foreground text-xs"
            >
                {cukup === null
                    ? 'Stok belum diperiksa. Pilih apotek lebih dulu.'
                    : `${nama}: stok belum dapat dicek karena item ini tidak punya obat_id (racikan).`}
            </p>
        );
    }

    const warna = cukup ? 'text-success' : 'text-destructive';

    return (
        <p data-slot="checkout-stok" data-state={cukup ? 'cukup' : 'kurang'} className={`${warna} flex flex-wrap items-center gap-1.5 text-xs`}>
            {cukup ? <PackageCheck aria-hidden className="size-3.5" /> : <PackageX aria-hidden className="size-3.5" />}

            {apotek.nama}:{' '}
            {apotek.recorded
                ? `stok ${apotek.jumlah_stok} untuk ${jumlah} diminta`
                : 'tidak pernah storing obat ini'}

            {apotek.kedaluwarsa === null ? null : `, kedaluwarsa ${formatTanggal(apotek.kedaluwarsa)}`}
        </p>
    );
}

import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { BadgePercent, Loader2 } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { formatRupiah } from '@/lib/format';
import { validasiPromoMutation } from '@/lib/api/pembayaran';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * The promo input, wired to `POST /promo/validasi`.
 *
 * ## Not one rupiah of arithmetic happens here
 *
 * The server owns the discount, and the three rules that make it non-obvious - `kuota`
 * remaining, `min_transaksi`, and the `maks_diskon` cap - are evaluated in SQL inside
 * `PromoService`, with `master_promo` locked `FOR UPDATE` before the quota is counted. A
 * client that multiplied `subtotal * persen` would be right until a cap bit, and would then
 * show a discount the server refuses. So this component renders `nilai_diskon` and `total`
 * exactly as they arrive and never derives either.
 *
 * ## A refusal is a 200 with `valid: false`
 *
 * `PromoController::validasi()` returns the success envelope in every accepted case and
 * carries the reasons in `alasan`, each naming the FIELD it failed on. Branching on the
 * status code would therefore never see the refusal, and each reason is attached to the
 * rule the patient can act on rather than pooled into one sentence.
 */
export function PromoInput({ invoiceId }: { invoiceId: number | null }) {
    const [kode, setKode] = useState('');

    const validasi = useMutation(validasiPromoMutation());

    if (invoiceId === null) {
        return (
            <Alert data-slot="promo-tidak-bisa" variant="default">
                <BadgePercent aria-hidden />

                <AlertTitle>Kode promo belum bisa diperiksa di layar ini</AlertTitle>

                <AlertDescription>
                    <p>
                        POST /api/v1/promo/validasi mewajibkan `invoice_id`, dan invoice
                        baru dibuat oleh checkout. Tidak ada endpoint yang membuat invoice
                        atas permintaan, jadi tidak ada id yang bisa divalidasi sebelum
                        pesanan dibuat. Kode promo bisa dikirim sebagai `kode_promo` pada
                        checkout dan hasilnya dibaca dari invoice.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    const hasil = validasi.data?.data ?? null;
    const alasanFields = new Set(hasil?.alasan.map((a) => a.kolom) ?? []);

    return (
        <Card data-slot="promo-input" className="flex flex-col">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <BadgePercent aria-hidden />

                    Kode promo
                </CardTitle>

                <CardDescription>
                    POST /api/v1/promo/validasi untuk invoice {invoiceId}. Perhitungan
                    diskon, kuota, minimum transaksi, dan plafon diskon seluruhnya
                    dikerjakan server.
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                <FormErrorSummary error={validasi.error} />

                <form
                    className="flex items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();

                        validasi.mutate({ kode: kode.trim(), invoice_id: invoiceId });
                    }}
                >
                    <Field
                        label="Kode promo"
                        errors={
                            validasi.error instanceof ApiError
                                ? validasi.error.fieldErrors('kode')
                                : []
                        }
                    >
                        <FieldInput
                            data-slot="promo-kode"
                            value={kode}
                            maxLength={30}
                            disabled={validasi.isPending}
                            onChange={(e) => {
                                setKode(e.target.value);
                            }}
                            placeholder="mis. HEMAT10"
                        />
                    </Field>

                    <Button
                        data-slot="promo-cek"
                        type="submit"
                        variant="outline"
                        disabled={validasi.isPending || kode.trim() === ''}
                    >
                        {validasi.isPending ? <Loader2 className="animate-spin" /> : null}

                        Periksa
                    </Button>
                </form>

                {hasil === null ? null : (
                    <div
                        data-slot="promo-hasil"
                        data-valid={String(hasil.valid)}
                        className="flex flex-col gap-2"
                    >
                        {hasil.valid ? (
                            <p className="text-success text-sm">
                                Diskon{' '}
                                <span data-slot="promo-diskon" className="font-semibold">
                                    {formatRupiah(hasil.nilai_diskon)}
                                </span>{' '}
                                dan total{' '}
                                <span data-slot="promo-total" className="font-semibold">
                                    {formatRupiah(hasil.total)}
                                </span>{' '}
                                dihitung server.
                            </p>
                        ) : (
                            <Alert variant="destructive">
                                <BadgePercent aria-hidden />

                                <AlertTitle>Kode promo tidak dapat digunakan</AlertTitle>

                                <AlertDescription>
                                    <ul className="flex flex-col gap-1">
                                        {hasil.alasan.map((a) => (
                                            <li
                                                key={`${a.kode}-${a.pesan}`}
                                                data-slot="promo-alasan"
                                                data-kolom={a.kolom}
                                            >
                                                {a.pesan}
                                            </li>
                                        ))}
                                    </ul>
                                </AlertDescription>
                            </Alert>
                        )}

                        <dl className="text-muted-foreground grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <dt>Subtotal</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(hasil.rincian.subtotal)}
                            </dd>

                            <dt>Diskon</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(hasil.rincian.diskon)}
                            </dd>

                            <dt>Biaya admin</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(hasil.rincian.biaya_admin)}
                            </dd>

                            <dt>Biaya kirim</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(hasil.rincian.biaya_pengiriman)}
                            </dd>
                        </dl>

                        {alasanFields.has('kuota') ? (
                            <p className="text-warning text-xs">
                                Promo ini melewati batas kuota yang dihitung server, sehingga
                                potongannya tidak otomatis penuh.
                            </p>
                        ) : null}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Loader2, ShieldCheck } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { formatRupiah, toNumber } from '@/lib/format';
import { labelStatusPesanan, pesananObatOptions } from '@/lib/api/pesanan-obat';
import { STATUS_PESANAN_TERMINAL } from '@/lib/api/types';
import { bayarInvoiceMutation } from '@/lib/api/pembayaran';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { SkeletonRows } from '@/components/states/loading-state';
import {
    ErrorState,
    ForbiddenState,
    NotFoundState,
} from '@/components/states/error-state';
import { MetodePembayaranPicker } from '@/features/pembayaran/metode-pembayaran-picker';
import { PaymentInstructions } from '@/features/pembayaran/payment-instructions';
import { PromoInput } from '@/features/pembayaran/promo-input';

/**
 * How often the order is re-read while it is not terminal.
 *
 * `GET /pesanan-obat/{id}` carries **no** `throttle:` middleware, so nothing the server
 * enforces is being pushed against. 15 s is chosen for the screen, not to find the ceiling:
 * settlement on a mock gateway is immediate, and a real patient is not refreshing this page
 * every second.
 */
const POLL_MS = 15_000;

/**
 * The payment screen, scoped to an ORDER because an invoice cannot be addressed.
 *
 * ## Why this takes an order id and not an invoice id
 *
 * The plan specifies `PaymentWaitingPage` polling `GET /invoice/{id}`. That route does not
 * exist, and the checkout 201 publishes no `invoice_id`, so an invoice id is not derivable
 * from anything the order flow returns. The order id is, so the screen is keyed on that and
 * asks for the invoice id - the one genuinely missing link, stated on the screen rather
 * than worked around.
 *
 * ## The settlement signal is the ORDER's status, and nothing else
 *
 * `PaymentService::LANJUT` moves `pesanan_obat` from `menunggu_pembayaran` to `diproses`
 * inside the same transaction that marks the invoice `lunas`, on the first delivery only.
 * A retried webhook finds a terminal `pembayaran.status` and returns `duplicate: true`
 * with no write, so the order does not move twice. This screen polls that column and renders
 * it verbatim. It holds **no** local "paid" flag, because a local flag would be a second,
 * unsourced copy of a fact the server already publishes.
 */
export function PembayaranMenunggu({ pesananId }: { pesananId: number }) {
    const [invoiceMentah, setInvoiceMentah] = useState('');
    const [metodeId, setMetodeId] = useState('');

    const pesanan = useQuery({
        ...pesananObatOptions(pesananId),
        refetchInterval: (query) => {
            const status = query.state.data?.data.pesanan.status;

            return status === undefined || STATUS_PESANAN_TERMINAL.includes(status)
                ? false
                : POLL_MS;
        },
    });

    const bayar = useMutation(bayarInvoiceMutation());

    if (pesanan.isPending) {
        return <SkeletonRows rows={6} />;
    }

    if (pesanan.isError) {
        /**
         * The 404/403 split `order-tracking.tsx` already makes for this same endpoint, and
         * for the same reason: 404 is how `GET /pesanan-obat/{id}` refuses another account's
         * row without leaking that it exists, and neither status is fixed by a retry.
         */
        if (pesanan.error instanceof ApiError && pesanan.error.isNotFound) {
            return (
                <NotFoundState
                    title="Pesanan tidak ditemukan"
                    detail="Id tersebut tidak ada atau bukan milik pihak yang berhak. Nomor pesanan diberikan setelah checkout berhasil."
                />
            );
        }

        if (pesanan.error instanceof ApiError && pesanan.error.isForbidden) {
            return (
                <ForbiddenState detail="Endpoint ini hanya untuk akun pasien, apoteker, admin, atau superadmin." />
            );
        }

        return (
            <ErrorState
                error={pesanan.error}
                onRetry={() => {
                    void pesanan.refetch();
                }}
            />
        );
    }

    const order = pesanan.data.data.pesanan;
    const invoiceId = Number(invoiceMentah);
    const invoiceValid = Number.isInteger(invoiceId) && invoiceId > 0;
    const lunas = order.status !== 'menunggu_pembayaran';

    return (
        <div data-slot="pembayaran-menunggu" className="flex flex-col gap-4">
            <Card data-slot="pesanan-ringkas">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <ShieldCheck aria-hidden />

                        Pesanan {order.nomor_pesanan}
                    </CardTitle>

                    <CardDescription>
                        GET /api/v1/pesanan-obat/{order.id}. Status pesanan dibaca dari
                        server dan ditampilkan apa adanya.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-2 text-sm">
                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground">Status pesanan</span>

                        <span data-slot="pesanan-status" className="font-semibold">
                            {labelStatusPesanan(order.status)}
                        </span>
                    </div>

                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground">Subtotal</span>
                        <span className="tabular-nums">{formatRupiah(order.subtotal)}</span>
                    </div>

                    <div className="flex items-baseline justify-between">
                        <span className="text-muted-foreground">Biaya kirim</span>
                        <span className="tabular-nums">{formatRupiah(order.biaya_kirim)}</span>
                    </div>

                    <div className="flex items-baseline justify-between border-t pt-2">
                        <span className="font-medium">Total pesanan</span>
                        <span data-slot="pesanan-total" className="font-semibold tabular-nums">
                            {formatRupiah(order.total)}
                        </span>
                    </div>

                    <p className="text-muted-foreground text-xs">
                        Total ini adalah barang plus kirim. Diskon dan biaya admin ada di
                        invoice, dan invoice hanya dipublikasikan sebagai bagian dari
                        respons pembayaran di bawah.
                    </p>

                    {lunas ? (
                        <Alert data-slot="pesanan-sudah-terbayar" variant="default">
                            <ShieldCheck aria-hidden />

                            <AlertTitle>Server sudah mencatat settlement</AlertTitle>

                            <AlertDescription>
                                <p>
                                    Status pesanan sudah tidak lagi{' '}
                                    <code>menunggu_pembayaran</code>, dan transisi itu hanya
                                    ditulis sekali pada pengiriman webhook pertama. Kiriman
                                    ulang answered dengan <code>duplicate: true</code> tanpa
                                    menulis apa pun.
                                </p>
                            </AlertDescription>
                        </Alert>
                    ) : null}
                </CardContent>
            </Card>

            {bayar.data === undefined ? (
                <Card data-slot="payment-mulai">
                    <CardHeader>
                        <CardTitle>Mulai pembayaran</CardTitle>

                        <CardDescription>
                            POST /api/v1/invoice/{'{id}'}/bayar. Id invoice tidak
                            dipublikasikan endpoint mana pun, jadi harus diisi di sini.
                        </CardDescription>
                    </CardHeader>

                    <CardContent className="flex flex-col gap-4">
                        <FormErrorSummary error={bayar.error} />

                        <Field
                            label="Id invoice"
                            required
                            errors={
                                bayar.error instanceof ApiError
                                    ? bayar.error.fieldErrors('metode_id')
                                    : []
                            }
                            hint="Tidak ada GET /invoice/{id} dan respons checkout tidak memuat invoice_id, jadi nilai ini tidak bisa ditemukan dari API. Lihat .omo/evidence/task-48-sehatly.md."
                        >
                            <FieldInput
                                data-slot="payment-invoice-id"
                                type="number"
                                min={1}
                                value={invoiceMentah}
                                disabled={bayar.isPending}
                                onChange={(e) => {
                                    setInvoiceMentah(e.target.value);
                                }}
                            />
                        </Field>

                        <MetodePembayaranPicker
                            value={metodeId}
                            onValueChange={setMetodeId}
                            subtotal={toNumber(order.subtotal) ?? 0}
                            disabled={bayar.isPending}
                        />

                        <Button
                            data-slot="payment-submit"
                            type="button"
                            disabled={!invoiceValid || metodeId === '' || bayar.isPending}
                            onClick={() => {
                                bayar
                                    .mutateAsync({
                                        invoiceId,
                                        input: { metode_id: Number(metodeId) },
                                    })
                                    .then((hasil) => {
                                        dispatchFlash({
                                            level: 'success',
                                            message: hasil.message,
                                        });
                                    })
                                    .catch((error: unknown) => {
                                        dispatchFlash({
                                            level: 'error',
                                            message:
                                                error instanceof ApiError
                                                    ? error.message
                                                    : 'Pembayaran gagal dimulai.',
                                        });
                                    });
                            }}
                        >
                            {bayar.isPending ? <Loader2 className="animate-spin" /> : null}

                            Bayar sekarang
                        </Button>

                        {bayar.error instanceof ApiError && bayar.error.status === 422 ? (
                            <p
                                data-slot="payment-server-refused"
                                className="text-destructive text-xs"
                            >
                                Penolakan ini datang dari server dan ditampilkan apa adanya.
                                Halaman ini tidak menyimpan penanda "sudah bayar" sendiri,
                                jadi jawaban server tetap terlihat di sini.
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
            ) : (
                <PaymentInstructions data={bayar.data.data} />
            )}

            <PromoInput invoiceId={bayar.data === undefined ? null : bayar.data.data.invoice.id} />

            <p className="text-muted-foreground text-xs">
                <Link to={`/pesanan/${order.id}`} className="underline">
                    Lihat lacak pesanan
                </Link>
            </p>
        </div>
    );
}

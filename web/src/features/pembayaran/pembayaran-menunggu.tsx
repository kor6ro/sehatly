import { useEffect, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { CheckCircle2, Loader2, PackageSearch, Wifi, XCircle } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { dispatchFlash } from '@/lib/flash';
import { formatRupiah, toNumber } from '@/lib/format';
import { pesananObatOptions } from '@/lib/api/pesanan-obat';
import { bayarInvoiceMutation } from '@/lib/api/pembayaran';
import { useOnlineStatus } from '@/hooks/use-online-status';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Field, FieldInput, FormErrorSummary } from '@/components/form/field';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { OfflineBanner } from '@/components/offline-banner';
import { SkeletonRows } from '@/components/states/loading-state';
import {
    ErrorState,
    ForbiddenState,
    NotFoundState,
} from '@/components/states/error-state';
import { MetodePembayaranPicker } from '@/features/pembayaran/metode-pembayaran-picker';
import { PaymentInstructions } from '@/features/pembayaran/payment-instructions';
import { PromoInput } from '@/features/pembayaran/promo-input';
import { RingkasanInvoice } from '@/features/pembayaran/invoice-summary';
import { StatusPesananBadge } from '@/features/pembayaran/status-pembayaran';

/**
 * How often the order is re-read while it is still waiting for payment.
 *
 * `GET /pesanan-obat/{id}` carries **no** `throttle:` middleware, so nothing the server
 * enforces is being pushed against. 15 s is chosen for the screen, not to find the ceiling:
 * settlement on a mock gateway is immediate, and a real patient is not refreshing this page
 * every second.
 */
const POLL_MS = 15_000;

/**
 * The server's own refusal sentence, for the 422 block.
 *
 * `bootstrap/app.php` deliberately publishes a FIXED English summary on every
 * `ValidationException` ("The given data was invalid.") and keeps the actionable,
 * Indonesian text in `errors`. For a duplicate payment the sentence that names the
 * existing reference lives in `errors.metode_id`, so that is what is shown - verbatim.
 */
function pesanDitolak(error: ApiError): string {
    const pesan = Object.values(error.errors).flat();

    return pesan.length === 0 ? error.message : pesan.join(' ');
}

/**
 * The payment screen, scoped to an ORDER because an invoice cannot be addressed by the
 * order flow.
 *
 * ## The route keeps the order id, and the invoice id is still typed
 *
 * Checkout does not publish `invoice_id` and no endpoint lists a patient's invoices, so an
 * invoice id is not derivable from anything the order flow returns. The order id is, so the
 * screen is keyed on that and asks for the invoice id - which `GET /invoice/{id}` now
 * resolves, letting the screen show the bill's real status, amount and payment history
 * before any payment is started.
 *
 * ## The settlement signal is the ORDER's status, and nothing else
 *
 * `PaymentService::LANJUT` moves `pesanan_obat` from `menunggu_pembayaran` to `diproses`
 * inside the same transaction that marks the invoice `lunas`, on the first delivery only.
 * A retried webhook finds a terminal `pembayaran.status` and returns `duplicate: true`
 * with no write, so the order does not move twice. This screen polls that column and
 * renders it verbatim. It holds **no** local "paid" flag, because a local flag would be a
 * second, unsourced copy of a fact the server already publishes.
 *
 * ## Polling stops as soon as the order leaves `menunggu_pembayaran`
 *
 * While the order is waiting, the screen re-reads it every {@link POLL_MS}; once the
 * status changes, there is nothing left on THIS screen to watch - later tracking states
 * belong to the tracking page - so the interval disarms. `refetchOnReconnect` is off for
 * the same reason: when the connection returns, the recovery button below is what re-arms
 * the read, so the patient sees the request they asked for.
 */
export function PembayaranMenunggu({ pesananId }: { pesananId: number }) {
    const [invoiceMentah, setInvoiceMentah] = useState('');
    const [metodeId, setMetodeId] = useState('');
    const [perluCobaLagi, setPerluCobaLagi] = useState(false);

    const online = useOnlineStatus();

    useEffect(() => {
        if (!online) {
            setPerluCobaLagi(true);
        }
    }, [online]);

    const pesanan = useQuery({
        ...pesananObatOptions(pesananId),
        refetchOnReconnect: false,
        refetchInterval: (query) => {
            const status = query.state.data?.data.pesanan.status;

            return online &&
                !perluCobaLagi &&
                status === 'menunggu_pembayaran'
                ? POLL_MS
                : false;
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
                    action={
                        <Button asChild variant="outline" className="mt-1 min-h-11">
                            <Link to="/pesanan">Lihat lacak pesanan</Link>
                        </Button>
                    }
                />
            );
        }

        if (pesanan.error instanceof ApiError && pesanan.error.isForbidden) {
            return (
                <ForbiddenState
                    detail="Halaman ini hanya untuk akun pasien, apoteker, admin, atau superadmin."
                    action={
                        <Button asChild variant="outline" className="mt-1 min-h-11">
                            <Link to="/pesanan">Lihat lacak pesanan</Link>
                        </Button>
                    }
                />
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
    const menungguPembayaran = order.status === 'menunggu_pembayaran';
    const dibatalkan = order.status === 'dibatalkan';

    return (
        <div data-slot="pembayaran-menunggu" className="flex flex-col gap-4">
            <OfflineBanner />

            {online && perluCobaLagi ? (
                <Alert
                    data-slot="payment-coba-lagi"
                    role="status"
                    className="border-success/40 bg-success/10"
                >
                    <Wifi aria-hidden />

                    <AlertTitle>Koneksi kembali</AlertTitle>

                    <AlertDescription>
                        <p>
                            Pemantauan status dijeda saat koneksi putus. Muat
                            status terbaru untuk melanjutkan pemantauan.
                        </p>

                        <Button
                            data-slot="payment-cobaLagi"
                            type="button"
                            variant="outline"
                            className="mt-1 min-h-11"
                            onClick={() => {
                                setPerluCobaLagi(false);

                                void pesanan.refetch();
                            }}
                        >
                            Coba lagi
                        </Button>
                    </AlertDescription>
                </Alert>
            ) : null}

            <Card data-slot="pesanan-ringkas">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <PackageSearch aria-hidden />

                        Pesanan {order.nomor_pesanan}
                    </CardTitle>

                    <CardDescription>
                        Ringkasan pesanan obat ini. Status dan total di bawah
                        ditampilkan apa adanya dari data pesanan.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-2 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-muted-foreground">Status pesanan</span>

                        <StatusPesananBadge status={order.status} />
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
                        Total ini adalah barang plus kirim. Diskon dan biaya
                        admin ada di tagihan, yang ditampilkan setelah nomor
                        tagihan diisi di bawah.
                    </p>

                    {menungguPembayaran ? null : dibatalkan ? (
                        <Alert
                            data-slot="pesanan-dibatalkan"
                            role="status"
                            variant="destructive"
                        >
                            <XCircle aria-hidden />

                            <AlertTitle>Pesanan dibatalkan</AlertTitle>

                            <AlertDescription>
                                <p>
                                    Pesanan ini sudah dibatalkan, sehingga tidak
                                    ada pembayaran yang perlu diselesaikan di
                                    halaman ini.
                                </p>
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <Alert
                            data-slot="pesanan-sudah-terbayar"
                            role="status"
                            className="border-success/40 bg-success/10"
                        >
                            <CheckCircle2 aria-hidden className="text-success" />

                            <AlertTitle>Pembayaran tercatat</AlertTitle>

                            <AlertDescription>
                                <p>
                                    Pesanan sedang diproses. Status pesanan sudah
                                    berubah dari menunggu pembayaran, dan
                                    konfirmasi ini hanya dicatat server satu kali.
                                </p>
                            </AlertDescription>
                        </Alert>
                    )}
                </CardContent>
            </Card>

            {menungguPembayaran ? (
                bayar.data === undefined ? (
                    <Card data-slot="payment-mulai">
                        <CardHeader>
                            <CardTitle>Mulai pembayaran</CardTitle>

                            <CardDescription>
                                Masukkan nomor tagihan untuk melanjutkan
                                pembayaran. Nomor ini tercantum pada ringkasan
                                pesanan setelah checkout berhasil.
                            </CardDescription>
                        </CardHeader>

                        <CardContent className="flex flex-col gap-4">
                            <FormErrorSummary error={bayar.error} />

                            <Field
                                label="Id invoice"
                                required
                                hint="Masukkan angka invoice yang diberikan pada ringkasan pesanan."
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
                                errors={
                                    bayar.error instanceof ApiError
                                        ? bayar.error.fieldErrors('metode_id')
                                        : []
                                }
                            />

                            {invoiceValid ? (
                                <RingkasanInvoice
                                    invoiceId={invoiceId}
                                    pesananId={order.id}
                                />
                            ) : null}

                            <Button
                                data-slot="payment-submit"
                                type="button"
                                className="min-h-11"
                                disabled={
                                    !invoiceValid ||
                                    metodeId === '' ||
                                    bayar.isPending ||
                                    !online
                                }
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
                                            /**
                                             * A 422 is a field-level answer and
                                             * `FormErrorSummary` plus the server
                                             * message below already render it; a
                                             * toast would be the second place the
                                             * same sentence appears.
                                             */
                                            if (
                                                error instanceof ApiError &&
                                                error.isValidation
                                            ) {
                                                return;
                                            }

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
                                    className="text-destructive text-sm"
                                >
                                    {pesanDitolak(bayar.error)}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : (
                    <PaymentInstructions data={bayar.data.data} />
                )
            ) : null}

            {menungguPembayaran ? (
                <PromoInput
                    invoiceId={
                        bayar.data !== undefined
                            ? bayar.data.data.invoice.id
                            : invoiceValid
                              ? invoiceId
                              : null
                    }
                />
            ) : null}

            <p className="text-muted-foreground text-xs">
                <Link to={`/pesanan/${order.id}`} className="underline">
                    Lihat lacak pesanan
                </Link>
            </p>
        </div>
    );
}

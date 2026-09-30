import { Copy, QrCode, Wallet } from 'lucide-react';
import { formatRupiah, formatWaktu } from '@/lib/format';
import { labelStatusPembayaran } from '@/lib/api/pembayaran';
import type { MulaiPembayaranData } from '@/lib/api/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

/**
 * The gateway's payment instructions, rendered exactly as the gateway published them.
 *
 * ## `toko` is a discriminated union and this is why
 *
 * `config('payment.metode_tipe_qr')` is `['qris','gerai_retail']`: those two method types
 * get a QR payload, and every other type gets a virtual account. `pembayaran` has one
 * `va_number VARCHAR(30)` column and no `qr_string` column at all, so a panel that read
 * both unconditionally would print "null" beside every QR payment.
 *
 * ## Nothing is rendered as settled
 *
 * `pembayaran.status` is `pending` on a fresh initiation, and `terminal` is the server's
 * own `PembayaranStatus::adalahAkhir()`. This component prints both as the server sent
 * them. It does not infer "paid" from the presence of a VA number, because a VA number
 * exists the moment a transaction is created and the money may never arrive.
 */
export function PaymentInstructions({ data }: { data: MulaiPembayaranData }) {
    const { pembayaran, toko, instruksi, gateway } = data;

    return (
        <Card data-slot="payment-instructions" className="flex flex-col">
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Wallet aria-hidden />

                    Instruksi pembayaran
                </CardTitle>

                <CardDescription>
                    {gateway.nama} - referensi{' '}
                    <span data-slot="payment-referensi" className="font-mono">
                        {gateway.nomor_referensi}
                    </span>
                    . Status menurut server:{' '}
                    <span data-slot="payment-status">{labelStatusPembayaran(pembayaran.status)}</span>
                    {pembayaran.terminal ? ' (terminal)' : ''}.
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-4">
                {toko.va_number === undefined ? (
                    <div data-slot="payment-toko" data-jenis="qr" className="flex flex-col gap-1">
                        <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                            <QrCode aria-hidden className="size-3.5" />

                            QR {toko.nama_penyedia}
                        </p>

                        <code
                            data-slot="payment-qr"
                            className="bg-muted block w-fit break-all rounded p-2 font-mono text-xs"
                        >
                            {toko.qr_string}
                        </code>
                    </div>
                ) : (
                    <div data-slot="payment-toko" data-jenis="va" className="flex flex-col gap-1">
                        <p className="text-muted-foreground text-xs">
                            Virtual account {toko.nama_bank} atas nama {toko.nama_pemilik}
                        </p>

                        <p className="flex items-center gap-2">
                            <code
                                data-slot="payment-va"
                                className="bg-muted rounded px-2 py-1 font-mono text-lg tracking-wider"
                            >
                                {toko.va_number}
                            </code>

                            <Badge variant="secondary">{toko.nama_bank}</Badge>
                        </p>
                    </div>
                )}

                {instruksi.length === 0 ? null : (
                    <ol
                        data-slot="payment-instruksi"
                        className="text-muted-foreground flex list-decimal flex-col gap-1 pl-5 text-sm"
                    >
                        {instruksi.map((step) => (
                            <li key={step}>{step}</li>
                        ))}
                    </ol>
                )}

                <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">Total tagihan</dt>
                    <dd
                        data-slot="payment-total"
                        className="text-right font-semibold tabular-nums"
                    >
                        {formatRupiah(data.invoice.total)}
                    </dd>

                    <dt className="text-muted-foreground">Nomor invoice</dt>
                    <dd className="text-right font-mono">{data.invoice.nomor_invoice}</dd>

                    <dt className="text-muted-foreground">Kedaluwarsa</dt>
                    <dd className="text-right">
                        {formatWaktu(pembayaran.kadaluwarsa_at)}
                    </dd>
                </dl>

                <Alert data-slot="payment-idempotensi">
                    <Copy aria-hidden />

                    <AlertTitle>Status pembayaran hanya dapat dikonfirmasi penyedia pembayaran</AlertTitle>

                    <AlertDescription>
                        <p>
                            Konfirmasi pembayaran hanya diterima dari penyedia
                            pembayaran dan tidak pernah dikirim dari peramban. Karena
                            itu layar ini tidak menebak status berbayar: status dibaca
                            dari pesanan Anda, satu-satunya tanda pembayaran yang
                            tersedia.
                        </p>
                    </AlertDescription>
                </Alert>
            </CardContent>
        </Card>
    );
}

import { useQuery } from '@tanstack/react-query';
import { ReceiptText, TriangleAlert } from 'lucide-react';
import { ApiError } from '@/lib/http';
import { formatRupiah } from '@/lib/format';
import { formatWaktuZona } from '@/lib/waktu';
import { invoiceOptions } from '@/lib/api/pembayaran';
import { CardDescription } from '@/components/ui/card';
import { SkeletonRows } from '@/components/states/loading-state';
import { ErrorState } from '@/components/states/error-state';
import {
    StatusInvoiceBadge,
    StatusPembayaranBadge,
} from '@/features/pembayaran/status-pembayaran';

/**
 * What the typed invoice id resolved to, read from `GET /invoice/{id}`.
 *
 * ## The point of this block
 *
 * The invoice id is typed by hand because checkout does not publish it and no endpoint
 * lists a patient's invoices. This block is the verification step: the server is asked
 * about the id BEFORE `POST /invoice/{id}/bayar` is offered, so a wrong digit shows
 * "tidak ditemukan" instead of starting a payment, and a paying order shows its real
 * status, amount and newest-first payment history.
 *
 * ## Money and status are rendered, never derived
 *
 * Every figure comes from the server as a two-decimal string and goes through
 * `formatRupiah`. No discount, fee or total is added up here, and the status chips read
 * the raw `status` column through the shared label maps.
 *
 * ## A mismatch is possible and is stated
 *
 * The route is keyed on the ORDER and the invoice id is typed, so the two can disagree.
 * The server accepts any invoice the caller owns; this block says so when the invoice does
 * not reference this order, rather than silently presenting someone else's bill as this
 * order's.
 */
export function RingkasanInvoice({
    invoiceId,
    pesananId,
}: {
    invoiceId: number;
    pesananId: number;
}) {
    const invoice = useQuery(invoiceOptions(invoiceId));

    if (invoice.isPending) {
        return (
            <div data-slot="invoice-loading">
                <SkeletonRows rows={2} />
            </div>
        );
    }

    if (invoice.isError) {
        if (invoice.error instanceof ApiError && invoice.error.isNotFound) {
            return (
                <p
                    data-slot="invoice-tidak-ditemukan"
                    className="text-destructive text-sm"
                >
                    Id invoice tidak ditemukan atau bukan milik akun ini. Periksa
                    kembali angka pada ringkasan pesanan.
                </p>
            );
        }

        if (invoice.error instanceof ApiError && invoice.error.isForbidden) {
            return (
                <p data-slot="invoice-tanpa-akses" className="text-destructive text-sm">
                    Akun ini tidak berhak membuka tagihan tersebut.
                </p>
            );
        }

        return (
            <ErrorState
                error={invoice.error}
                onRetry={() => {
                    void invoice.refetch();
                }}
            />
        );
    }

    const tagihan = invoice.data.data.invoice;
    const untukPesananIni =
        tagihan.referensi_tipe === 'pesanan_obat' &&
        tagihan.referensi_id === pesananId;

    return (
        <section
            data-slot="invoice-ringkas"
            aria-live="polite"
            className="bg-muted/30 flex flex-col gap-3 rounded-lg p-3"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <ReceiptText aria-hidden />

                    <span data-slot="invoice-nomor">{tagihan.nomor_invoice}</span>
                </p>

                <StatusInvoiceBadge status={tagihan.status} />
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                <dt className="text-muted-foreground">Total tagihan</dt>
                <dd
                    data-slot="invoice-total"
                    className="text-right font-semibold tabular-nums"
                >
                    {formatRupiah(tagihan.total)}
                </dd>

                <dt className="text-muted-foreground">Jatuh tempo</dt>
                <dd className="text-right">{formatWaktuZona(tagihan.jatuh_tempo)}</dd>

                {tagihan.lunas_at === null ? null : (
                    <>
                        <dt className="text-muted-foreground">Lunas pada</dt>
                        <dd className="text-right">
                            {formatWaktuZona(tagihan.lunas_at)}
                        </dd>
                    </>
                )}
            </dl>

            {untukPesananIni ? null : (
                <div
                    data-slot="invoice-bukan-pesanan-ini"
                    className="border-warning/40 bg-warning/10 flex items-start gap-2 rounded-lg border p-2.5 text-xs"
                >
                    <TriangleAlert
                        aria-hidden
                        className="text-warning mt-0.5 size-3.5 shrink-0"
                    />

                    <p>
                        Id invoice ini bukan tagihan untuk pesanan ini. Periksa
                        kembali angka pada ringkasan pesanan sebelum membayar.
                    </p>
                </div>
            )}

            {tagihan.pembayaran.length === 0 ? (
                <p className="text-muted-foreground text-xs">
                    Belum ada pembayaran yang dimulai untuk tagihan ini.
                </p>
            ) : (
                <div className="flex flex-col gap-2">
                    <CardDescription>Riwayat pembayaran</CardDescription>

                    <ul className="flex flex-col gap-2">
                        {tagihan.pembayaran.map((pembayaran) => (
                            <li
                                key={pembayaran.id}
                                data-slot="invoice-pembayaran-row"
                                data-status={pembayaran.status}
                                className="flex flex-col gap-0.5 border-t pt-2 first:border-t-0 first:pt-0"
                            >
                                <span className="flex flex-wrap items-center justify-between gap-2">
                                    <StatusPembayaranBadge status={pembayaran.status} />

                                    <span className="font-medium tabular-nums">
                                        {formatRupiah(pembayaran.jumlah)}
                                    </span>
                                </span>

                                <span className="text-muted-foreground text-xs">
                                    Referensi {pembayaran.nomor_referensi}
                                </span>

                                {pembayaran.va_number === null ? null : (
                                    <span className="font-mono text-xs">
                                        VA {pembayaran.va_number}
                                    </span>
                                )}

                                <span className="text-muted-foreground text-xs">
                                    Kedaluwarsa {formatWaktuZona(pembayaran.kadaluwarsa_at)}
                                </span>

                                {pembayaran.dibayar_at === null ? null : (
                                    <span className="text-muted-foreground text-xs">
                                        Dibayar {formatWaktuZona(pembayaran.dibayar_at)}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}

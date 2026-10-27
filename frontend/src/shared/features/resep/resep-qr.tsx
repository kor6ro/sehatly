import { useMemo } from 'react';
import { QrCode } from 'lucide-react';
import { buatQr, qrSvgPath } from '@/lib/qr';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

/**
 * The prescription's QR, rendered from `qr_token` - and the token shown in plain text
 * beside it.
 *
 * ## Why the token is never QR-only
 *
 * `resep.qr_token` is `NOT NULL` but deliberately **not** `UNIQUE` (`:758`), and the plan's
 * schema notes record that it is a security token: a duplicate check is an application-level
 * obligation, and a phone camera is a lossy channel. So the token is rendered as selectable
 * text directly beneath the symbol, and a pharmacist who cannot scan - bad light, a printed
 * copy, an old camera - reads the same value without a second system.
 *
 * ## The payload is the token, not a URL this client invented
 *
 * The plan is explicit that the QR payload is generated server-side and that there is no
 * QRIS package worth taking. There is also no verification endpoint in the route table, so
 * encoding a URL to one would print a link that 404s. The payload is the token itself: the
 * one string the server minted and the one a verifier can compare against.
 */
export function ResepQr({ token, className }: { token: string; className?: string }) {
    /**
     * `useMemo` because the encoder is not free: a 57x57 symbol is 1 569 modules of mask
     * evaluation and penalty scoring, and re-running it on every render of a detail screen
     * that also re-renders on a query refetch is waste nobody can see.
     *
     * A token that is not Latin-1, or is long enough to need a version above 10, throws
     * rather than producing a wrong symbol. `buatQr` cannot fail for a UUID, and a crash
     * here would be a genuine defect rather than a rendering nuisance.
     */
    const matriks = useMemo(() => buatQr(token), [token]);
    const path = useMemo(() => qrSvgPath(matriks), [matriks]);

    return (
        <Card data-slot="resep-qr" className={className}>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <QrCode aria-hidden />

                    Kode verifikasi resep
                </CardTitle>

                <CardDescription>
                    Dipindai apoteker untuk mencocokkan dengan resep fisik. Token juga
                    ditampilkan sebagai teks di bawah.
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-3">
                <svg
                    data-slot="resep-qr-svg"
                    role="img"
                    aria-label={`Kode QR token verifikasi resep, versi ${matriks.versi}`}
                    viewBox={`0 0 ${matriks.ukuran} ${matriks.ukuran}`}
                    shapeRendering="crispEdges"
                    className="bg-background size-40 rounded-md border p-2"
                >
                    {/**
                     * One path, not one rect per module: a 33x33 symbol is 500-plus
                     * elements and a 57x57 one over a thousand.
                     */}
                    <path d={path} fill="currentColor" />
                </svg>

                <div className="flex flex-col gap-1">
                    <p className="text-muted-foreground text-xs">Token verifikasi</p>

                    <code
                        data-slot="resep-qr-token"
                        className="bg-muted block break-all rounded-md px-2 py-1 font-mono text-xs"
                    >
                        {token}
                    </code>
                </div>
            </CardContent>
        </Card>
    );
}

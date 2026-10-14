import { CalendarClock, Pill, User } from 'lucide-react';
import { formatRupiah, formatTanggal, formatWaktu, toNumber } from '@/lib/format';
import type { PeringatanGrup, Resep, ResepVerifikasi } from '@/lib/api/types';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { WarningPanel } from '@/features/resep/warning-panel';
import { ResepQr } from '@/features/resep/resep-qr';
import {
    KedaluwarsaBadge,
    StatusResepBadge,
    StatusVerifikasiBadge,
} from '@/features/resep/resep-status-badge';

/**
 * One prescription, read-only, for any of the three parties entitled to it.
 *
 * ## `is_kedaluwarsa` gets its OWN badge, and that is the point
 *
 * `ResepStateMachine::kedaluwarsa()` is true for two different reasons: the stored status is
 * `kedaluwarsa`, or `berlaku_sampai` is in the past while the status still reads `aktif`.
 * **Nothing in the schema reacts to `berlaku_sampai`** - no trigger, no generated column -
 * so the second case is the common one and it is invisible in the status column alone. A
 * patient looking at a row that says "Aktif" must also be told it lapsed yesterday.
 *
 * ## The re-checked warning set is shown, not the one the doctor saw
 *
 * `obat_interaksi` and `pasien_alergi` are live tables, so a warning can appear AFTER the
 * prescription was written and acknowledged. `GET /resep/{id}` publishes the CURRENT set,
 * and a pharmacy that trusted the prescriber's original 201 would dispense against a stale
 * one. The panel therefore renders whatever the read returned, and says so.
 */
export function ResepDetail({
    resep,
    verifikasi,
    warningGrup,
    className,
}: {
    resep: Resep;
    verifikasi: ResepVerifikasi | null;
    warningGrup: PeringatanGrup | null;
    className?: string;
}) {
    const items = resep.items ?? [];
    const total = items.reduce(
        (jumlah, item) => jumlah + (toNumber(item.subtotal) ?? 0),
        0,
    );

    return (
        <div
            data-slot="resep-detail"
            data-status={resep.status}
            data-kedaluwarsa={resep.is_kedaluwarsa}
            className={className}
        >
            <Card>
                <CardHeader>
                    <CardTitle className="flex flex-wrap items-center gap-2">
                        Resep {resep.nomor_resep}
                        <StatusResepBadge status={resep.status} />

                        {resep.is_kedaluwarsa ? <KedaluwarsaBadge /> : null}
                    </CardTitle>

                    <CardDescription>
                        Rincian resep Anda. Peringatan di bawah dihitung dari item
                        resep yang tersimpan di sistem.
                    </CardDescription>
                </CardHeader>

                <CardContent className="flex flex-col gap-6">
                    <section
                        data-slot="resep-identitas"
                        className="flex flex-col gap-2"
                    >
                        <Baris
                            label="Tanggal resep"
                            nilai={formatWaktu(resep.tanggal_resep)}
                        />

                        <Baris
                            label="Berlaku sampai"
                            nilai={formatTanggal(resep.berlaku_sampai)}
                        />

                        <Baris label="Tipe" nilai={resep.tipe} />

                        <Baris
                            label="Total"
                            nilai={formatRupiah(total.toFixed(2))}
                        />
                    </section>

                    <Separator />

                    <section data-slot="resep-items-detail" className="flex flex-col gap-2">
                        <h3 className="flex items-center gap-2 text-sm font-semibold">
                            <Pill aria-hidden className="size-4" />

                            Obat ({items.length})
                        </h3>

                        {items.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                Item tidak termuat pada tampilan ini.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {items.map((item) => (
                                    <li
                                        key={item.id}
                                        data-slot="resep-item-detail"
                                        data-racikan={item.is_racikan}
                                        className="bg-muted/30 flex flex-col gap-1 rounded-md px-3 py-2"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-medium">
                                                {item.nama_obat}
                                                {item.kekuatan === null
                                                    ? ''
                                                    : ` ${item.kekuatan}`}
                                            </span>

                                            {item.is_racikan ? (
                                                <Badge variant="outline">
                                                    Racikan
                                                </Badge>
                                            ) : null}
                                        </div>

                                        <p className="text-muted-foreground text-xs">
                                            {item.jumlah} {item.satuan ?? ''} -{' '}
                                            {item.aturan_pakai}
                                        </p>

                                        <p className="text-muted-foreground text-xs tabular-nums">
                                            {formatRupiah(item.harga_satuan)} x{' '}
                                            {item.jumlah} ={' '}
                                            {formatRupiah(item.subtotal)}
                                        </p>

                                        {item.catatan_apoteker === null ? null : (
                                            <p className="text-warning text-xs">
                                                Catatan apoteker:{' '}
                                                {item.catatan_apoteker}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    {/**
                     * `catatan_dokter` is rendered prominently, and it is the override record.
                     * There is no `resep_interaksi` table and no acknowledgement column, so a
                     * doctor's decision to prescribe despite a `kontraindikasi` survives
                     * ONLY as this free text. A pharmacy auditing why a contraindicated pair
                     * was dispensed reads this line.
                     */}
                    {resep.catatan_dokter === null ? null : (
                        <section
                            data-slot="resep-catatan-dokter"
                            className="flex flex-col gap-1"
                        >
                            <h3 className="flex items-center gap-2 text-sm font-semibold">
                                <CalendarClock aria-hidden className="size-4" />

                                Catatan dokter
                            </h3>

                            <p className="text-sm whitespace-pre-wrap break-words">
                                {resep.catatan_dokter}
                            </p>
                        </section>
                    )}

                    <Separator />

                    {warningGrup === null ? null : (
                        <section data-slot="resep-warning" className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                Peringatan interaksi (diperiksa ulang)
                            </h3>

                            <WarningPanel grup={warningGrup} />
                        </section>
                    )}

                    <Separator />

                    {verifikasi === null ? (
                        <p
                            data-slot="resep-belum-diverifikasi"
                            className="text-muted-foreground text-sm"
                        >
                            Belum diverifikasi apoteker. Resep tidak dapat dipenuhi pada
                            status ini.
                        </p>
                    ) : (
                        <section
                            data-slot="resep-verifikasi"
                            className="flex flex-col gap-2"
                        >
                            <h3 className="flex items-center gap-2 text-sm font-semibold">
                                <User aria-hidden className="size-4" />

                                Verifikasi apoteker
                                <StatusVerifikasiBadge status={verifikasi.status} />
                            </h3>

                            <Baris
                                label="Apoteker"
                                nilai={verifikasi.apoteker?.nama_lengkap ?? '-'}
                            />

                            <Baris
                                label="Waktu"
                                nilai={formatWaktu(verifikasi.diverifikasi_at)}
                            />

                            {verifikasi.catatan === null ? null : (
                                <Baris label="Catatan" nilai={verifikasi.catatan} />
                            )}

                            {verifikasi.ditolak ? (
                                <p className="text-destructive text-xs">
                                    Penolakan bersifat final. Resep tidak dapat diperbaiki
                                    dan dikirim ulang: satu resep hanya dapat memiliki
                                    satu verifikasi.
                                </p>
                            ) : null}
                        </section>
                    )}
                </CardContent>
            </Card>

            <ResepQr token={resep.qr_token} className="mt-4" />
        </div>
    );
}

function Baris({ label, nilai }: { label: string; nilai: string }) {
    return (
        <div className="flex flex-col gap-0.5 sm:flex-row sm:gap-2">
            <span className="text-muted-foreground w-full text-xs sm:w-52">
                {label}
            </span>

            <span className="text-sm break-words">{nilai}</span>
        </div>
    );
}

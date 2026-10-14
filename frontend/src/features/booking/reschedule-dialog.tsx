import { useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { AlertCircle, CalendarClock, ShieldCheck } from 'lucide-react';
import { jadwalUlangBookingMutation } from '@/lib/api/booking';
import { BookingCalendar } from '@/features/booking/booking-calendar';
import { SlotPicker } from '@/features/booking/slot-picker';
import { ApiError } from '@/lib/http';
import { formatTanggal } from '@/lib/format';
import { formatJamZona, formatRentangJamZona } from '@/lib/waktu';
import type { Booking, Tanggal } from '@/lib/api/types';
import type { Slot } from '@/lib/api/jadwal';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * F12's reschedule surface: one dialog, the same booking row, the same doctor and price.
 *
 * It is deliberately not a route and not a booking wizard. The server moves the SAME
 * `booking` row (`nomor_booking`, `pasien_id`, `dokter_id` and price unchanged), so a
 * second navigation step would add screens without adding a decision, and the pattern's
 * budget is <= 4 screens / <= 6 taps. Opening this dialog is one tap, choosing a slot is
 * the second, confirming is the third.
 *
 * The date defaults to the booking's current date because that is the common reschedule
 * ("same day, different hour") and the calendar is right there for a different one. Every
 * slot comes from `GET /dokter/{dokter}/slot` through the existing {@link SlotPicker}, so
 * availability is the server's answer exactly as it is on the booking form - this file
 * computes no geometry and no price.
 */

type SlotTerpilih = {
    jadwal_id: number;
    jam_mulai: string;
    jam_selesai: string;
};

export function RescheduleDialog({
    booking,
    namaDokter,
    open,
    onOpenChange,
    online,
    onSuccess,
    onReload,
}: {
    /** `null` before the first open; the form mounts only for a selected row. */
    booking: Booking | null;
    namaDokter: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    online: boolean;
    /** The server's new row, plus the row it replaced, for the inline success card. */
    onSuccess: (lama: Booking, baru: Booking) => void;
    /** Refetch the booking list after a 422 `status`; the page owns the query. */
    onReload: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto">
                {booking === null ? null : (
                    /**
                     * Keyed by booking id so opening another row remounts the form:
                     * the picked date and slot belong to one booking, and carrying them
                     * across rows would submit a slot the patient never chose.
                     */
                    <RescheduleForm
                        key={booking.id}
                        booking={booking}
                        namaDokter={namaDokter}
                        online={online}
                        onSuccess={onSuccess}
                        onReload={onReload}
                        onClose={() => {
                            onOpenChange(false);
                        }}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function RescheduleForm({
    booking,
    namaDokter,
    online,
    onSuccess,
    onReload,
    onClose,
}: {
    booking: Booking;
    namaDokter: string;
    online: boolean;
    onSuccess: (lama: Booking, baru: Booking) => void;
    onReload: () => void;
    onClose: () => void;
}) {
    const [tanggal, setTanggal] = useState<Tanggal>(booking.tanggal_kunjungan);
    const [slot, setSlot] = useState<SlotTerpilih | null>(null);
    const [galat, setGalat] = useState<unknown>(null);

    const mengirimRef = useRef(false);

    const pindahkan = useMutation(jadwalUlangBookingMutation());

    const tanggalTerpilih = tanggal === null || tanggal === '' ? null : tanggal;

    function pilihJam(jamMulai: string, row: Slot): void {
        setSlot({
            jadwal_id: row.jadwal_id,
            jam_mulai: jamMulai,
            jam_selesai: row.jam_selesai,
        });
        setGalat(null);
    }

    function konfirmasi(): void {
        if (slot === null || tanggalTerpilih === null || !online || mengirimRef.current) {
            return;
        }

        mengirimRef.current = true;

        pindahkan.mutate(
            {
                id: booking.id,
                input: {
                    jadwal_id: slot.jadwal_id,
                    tanggal_kunjungan: tanggalTerpilih,
                    slot_mulai: slot.jam_mulai,
                    slot_selesai: slot.jam_selesai,
                },
            },
            {
                onSuccess: (result) => {
                    mengirimRef.current = false;
                    onSuccess(booking, result.data.booking);
                    onClose();
                },
                onError: (mutationError) => {
                    mengirimRef.current = false;
                    setGalat(mutationError);
                },
            },
        );
    }

    return (
        <>
            <DialogHeader>
                <DialogTitle>Jadwal ulang</DialogTitle>

                <DialogDescription className="text-base">
                    Pilih jadwal baru untuk {namaDokter}.
                </DialogDescription>
            </DialogHeader>

            <div
                data-slot="reschedule-lama"
                className="bg-muted/40 flex flex-col gap-1 rounded-lg border p-3 text-sm"
            >
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    Jadwal lama
                </p>

                <p className="font-medium">
                    {formatTanggal(booking.tanggal_kunjungan)} •{' '}
                    {formatRentangJamZona(
                        booking.slot_mulai,
                        booking.slot_selesai,
                        booking.tanggal_kunjungan ?? '',
                    )}
                </p>
            </div>

            <Separator />

            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium">Pilih tanggal</p>

                <BookingCalendar
                    value={tanggalTerpilih}
                    onChange={(value) => {
                        setTanggal(value);
                        setSlot(null);
                        setGalat(null);
                    }}
                />
            </div>

            <div className="flex flex-col gap-2">
                <p className="text-sm font-medium">Pilih jam</p>

                <SlotPicker
                    dokterId={String(booking.dokter_id)}
                    tanggal={tanggalTerpilih}
                    selected={slot?.jam_mulai ?? null}
                    onSelect={pilihJam}
                />
            </div>

            <div
                data-slot="reschedule-baru"
                className="flex flex-col gap-1 rounded-lg border p-3 text-sm"
            >
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                    Jadwal baru
                </p>

                {slot === null ? (
                    <p className="text-muted-foreground">
                        Belum ada jam baru yang dipilih.
                    </p>
                ) : (
                    <p className="font-medium">
                        {formatTanggal(tanggalTerpilih ?? '')} •{' '}
                        {formatJamZona(
                            slot.jam_mulai,
                            tanggalTerpilih ?? '',
                        )}
                    </p>
                )}
            </div>

            <ul className="flex flex-col gap-2">
                <li className="flex items-start gap-2 text-sm">
                    <ShieldCheck
                        aria-hidden
                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />

                    <span data-slot="reschedule-safety">
                        Jadwal lama Anda tetap berlaku sampai jadwal baru
                        berhasil disimpan.
                    </span>
                </li>

                <li className="flex items-start gap-2 text-sm">
                    <CalendarClock
                        aria-hidden
                        className="text-muted-foreground mt-0.5 size-4 shrink-0"
                    />

                    <span data-slot="reschedule-pembayaran">
                        Harga sama. Tidak ada pembayaran tambahan.
                    </span>
                </li>
            </ul>

            {online ? null : (
                <p className="text-muted-foreground text-sm">
                    Anda sedang offline. Jadwal ulang memerlukan koneksi
                    internet.
                </p>
            )}

            {galat === null ? null : (
                <RescheduleErrorNotice error={galat} onReload={onReload} />
            )}

            <DialogFooter className="bg-background sticky bottom-0 z-10 -mx-6 border-t px-6 py-4">
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11"
                    onClick={onClose}
                >
                    Batal
                </Button>

                <Button
                    type="button"
                    className="min-h-11"
                    data-slot="confirm-reschedule"
                    disabled={slot === null || tanggalTerpilih === null || !online || pindahkan.isPending}
                    onClick={konfirmasi}
                >
                    {pindahkan.isPending ? <Spinner /> : <CalendarClock />}

                    Pindahkan jadwal
                </Button>
            </DialogFooter>
        </>
    );
}

function RescheduleErrorNotice({
    error,
    onReload,
}: {
    error: unknown;
    onReload: () => void;
}) {
    if (error instanceof ApiError && error.fieldErrors('slot').length > 0) {
        return (
            <Alert variant="destructive" data-slot="reschedule-error" data-error="slot">
                <AlertCircle />

                <AlertTitle className="text-foreground">Slot baru sudah terisi</AlertTitle>

                <AlertDescription>
                    <p>Slot baru sudah terisi. Pilih jam lain.</p>
                </AlertDescription>
            </Alert>
        );
    }

    if (error instanceof ApiError && error.fieldErrors('status').length > 0) {
        return (
            <Alert variant="destructive" data-slot="reschedule-error" data-error="status">
                <AlertCircle />

                <AlertTitle className="text-foreground">Janji ini tidak dapat dijadwalkan ulang</AlertTitle>

                <AlertDescription>
                    <p>
                        Janji dengan status ini tidak dapat dijadwalkan ulang.
                        Muat ulang halaman untuk melihat status terbaru.
                    </p>

                    <Button
                        type="button"
                        variant="outline"
                        className="mt-2 min-h-11"
                        onClick={onReload}
                    >
                        Muat ulang
                    </Button>
                </AlertDescription>
            </Alert>
        );
    }

    if (error instanceof ApiError && !error.isValidation) {
        return (
            <Alert variant="destructive" data-slot="reschedule-error" data-error="gagal">
                <AlertCircle />

                <AlertTitle className="text-foreground">Jadwal baru gagal disimpan</AlertTitle>

                <AlertDescription>
                    <p>
                        Gagal menyimpan jadwal baru. Jadwal lama Anda tidak
                        berubah. Coba lagi.
                    </p>
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert variant="destructive" data-slot="reschedule-error" data-error="gagal">
            <AlertCircle />

            <AlertTitle className="text-foreground">Jadwal baru gagal disimpan</AlertTitle>

            <AlertDescription>
                <p>
                    Gagal menyimpan jadwal baru. Jadwal lama Anda tidak berubah.
                    Coba lagi.
                </p>
            </AlertDescription>
        </Alert>
    );
}

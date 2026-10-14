import { Link } from 'react-router';
import { ArrowRight, FileText, Stethoscope } from 'lucide-react';
import type {
    Booking,
    RekamMedisDaftar,
    Resep,
    SuratKeterangan,
} from '@/lib/api/types';
import { labelTipeLayanan } from '@/lib/api/booking';
import { labelTipeSuratKeterangan } from '@/lib/api/surat-keterangan';
import { formatTanggal } from '@/lib/format';
import { formatRentangJamZona, formatWaktuZona } from '@/lib/waktu';
import { Button } from '@/components/ui/button';
import { StatusDokumenBadge, VersiBadge } from '@/features/konsultasi/status-badge';
import { BookingStatusBadge } from '@/features/booking/booking-status-badge';
import {
    KedaluwarsaBadge,
    StatusResepBadge,
} from '@/features/resep/resep-status-badge';

/**
 * The four row shapes F10's hub renders, one component each.
 *
 * Medical text - complaint, diagnosis, doctor name, visit complaint - is always
 * `text-base` and never `truncate`/`line-clamp`, the rule `_global.md` section 6.3
 * sets for clinical data. Status is always badge (text + icon + colour), never a tint
 * alone.
 */

const KARTU = 'bg-card flex flex-col gap-3 rounded-lg border p-4';

/** A row backed by `GET /rekam-medis` - the only clickable row type. */
export function BarisRekamMedis({ rekam }: { rekam: RekamMedisDaftar }) {
    return (
        <li
            data-slot="riwayat-baris"
            data-jenis="rekam-medis"
            data-rekam-id={rekam.id}
            className={KARTU}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground text-sm tabular-nums">
                    {formatWaktuZona(rekam.tanggal_periksa)}
                </span>

                <StatusDokumenBadge status={rekam.status_dokumen} />

                <VersiBadge versi={rekam.versi} terbaru={rekam.adalah_versi_terkini} />
            </div>

            <div className="flex flex-col gap-1">
                <p data-slot="riwayat-keluhan" className="text-base break-words">
                    Keluhan: {rekam.keluhan_utama ?? '-'}
                </p>

                {rekam.diagnosis_kerja === null ? null : (
                    <p
                        data-slot="riwayat-diagnosis"
                        className="text-base break-words"
                    >
                        Diagnosis kerja: {rekam.diagnosis_kerja}
                    </p>
                )}

                <p
                    data-slot="riwayat-dokter"
                    className="text-base break-words"
                >
                    <Stethoscope aria-hidden className="mr-1 inline size-4 align-middle" />
                    {rekam.dokter.nama_lengkap ?? 'Dokter tidak diketahui'}
                </p>
            </div>

            <Button asChild variant="outline" className="min-h-11 w-full sm:w-fit">
                <Link to={`/rekam-medis/${rekam.id}`}>
                    Buka rekam medis
                    <ArrowRight aria-hidden />
                </Link>
            </Button>
        </li>
    );
}

/**
 * A prescription with no medical record yet.
 *
 * Deliberately NOT a link and NOT a button: `rekam_medis_id` is `null`, so there is no
 * destination to open and inventing one would lead to a 404. The row stays visible so
 * the patient can see the prescription exists and why there is nothing to read.
 */
export function BarisResepTanpaRekamMedis({ resep }: { resep: Resep }) {
    return (
        <li
            data-slot="riwayat-baris"
            data-jenis="resep"
            data-resep-id={resep.id}
            className={KARTU}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground text-sm tabular-nums">
                    {formatWaktuZona(resep.tanggal_resep)}
                </span>

                {resep.status === 'kedaluwarsa' ? null : (
                    <StatusResepBadge status={resep.status} />
                )}

                {resep.is_kedaluwarsa ? <KedaluwarsaBadge /> : null}
            </div>

            <p className="text-base break-words">
                <FileText aria-hidden className="mr-1 inline size-4 align-[-2px]" />
                Resep {resep.nomor_resep}
            </p>

            <p className="text-base break-words">
                Belum ada rekam medis untuk resep ini.
            </p>
        </li>
    );
}

/** One `surat_keterangan` row. Letters have no `file_url`, so nothing is downloadable. */
export function BarisSuratKeterangan({ surat }: { surat: SuratKeterangan }) {
    const periode =
        surat.tanggal_mulai === null || surat.tanggal_selesai === null
            ? '-'
            : `${formatTanggal(surat.tanggal_mulai)} - ${formatTanggal(surat.tanggal_selesai)}`;

    return (
        <li
            data-slot="riwayat-baris"
            data-jenis="surat-keterangan"
            data-surat-id={surat.id}
            className={KARTU}
        >
            <div className="flex flex-col gap-1">
                <p data-slot="riwayat-nomor" className="text-base font-medium break-words">
                    {labelTipeSuratKeterangan(surat.tipe)} - {surat.nomor_surat}
                </p>

                <p className="text-base break-words">Periode: {periode}</p>

                <p data-slot="riwayat-dokter" className="text-base break-words">
                    {surat.dokter?.nama_lengkap ?? 'Dokter tidak diketahui'}
                </p>

                <p className="text-muted-foreground text-sm tabular-nums">
                    Dibuat {formatWaktuZona(surat.dibuat_at)}
                </p>
            </div>
        </li>
    );
}

/**
 * One booking row.
 *
 * `BookingResource` publishes no doctor name, so the row labels the doctor by id -
 * the same fallback `booking-list.tsx` uses. It also publishes no `konsultasi_id`, so
 * a visit cannot be linked to its record; the row stays informational.
 */
export function BarisKunjungan({ booking }: { booking: Booking }) {
    return (
        <li
            data-slot="riwayat-baris"
            data-jenis="kunjungan"
            data-booking-id={booking.id}
            className={KARTU}
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground text-sm tabular-nums">
                    {formatTanggal(booking.tanggal_kunjungan)}
                </span>

                <BookingStatusBadge status={booking.status} />
            </div>

            <p data-slot="riwayat-nomor" className="text-base font-medium break-words">
                {booking.nomor_booking}
            </p>

            <p className="text-base break-words">
                {labelTipeLayanan(booking.tipe_layanan)} -{' '}
                {formatRentangJamZona(
                    booking.slot_mulai,
                    booking.slot_selesai,
                    booking.tanggal_kunjungan ?? '',
                )}
            </p>

            <p data-slot="riwayat-dokter" className="text-base break-words">
                Dokter #{booking.dokter_id}
            </p>

            {booking.keluhan === null || booking.keluhan === '' ? null : (
                <p data-slot="riwayat-keluhan" className="text-base break-words">
                    Keluhan: {booking.keluhan}
                </p>
            )}
        </li>
    );
}

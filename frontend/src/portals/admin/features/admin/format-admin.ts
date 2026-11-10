import type { AksiAudit, JenisPersetujuanPdp, StatusBookingLaporan, StatusVerifikasiAdmin, TipeLayananJadwalAdmin } from '@/lib/api/admin';
import type { UserStatus, UserTipe } from '@/lib/api/types';

/**
 * Display labels and date formatting for the F14 admin surfaces.
 *
 * Everything here is derived from a closed vocabulary the server validates
 * (`Rule::in`) or from a `Y-m-d` string the server owns, so no label function
 * can be reached by a value the API refuses. A `switch` with a `default` is used
 * rather than an index lookup so a sixth enum member is a compile error at the
 * label map, not a blank cell at runtime.
 */

/** Indonesian short month names, `id-ID` spelling (`Agu`, not `Aug`). */
const BULAN_SINGKAT: ReadonlyArray<string> = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];

/**
 * A `Y-m-d` date as `12 Nov 2026`, split rather than parsed by `new Date()`.
 *
 * `new Date('2026-11-12')` is UTC midnight, so rendering it through `Intl` in a
 * negative-offset device zone prints 11 November. A `DATE` is a calendar day,
 * which is the same reason `lib/format.ts` splits instead of parsing.
 */
export function tanggalSingkat(value: string | null): string {
    if (value === null || value === '') {
        return '-';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    const bulan = BULAN_SINGKAT[Number(parts[1]) - 1];

    if (bulan === undefined) {
        return value;
    }

    return `${Number(parts[2])} ${bulan} ${parts[0]}`;
}

/** The seven weekdays of `dokter_jadwal.hari`, `0=Minggu .. 6=Sabtu`. */
export function labelHari(hari: number): string {
    switch (hari) {
        case 0:
            return 'Minggu';
        case 1:
            return 'Senin';
        case 2:
            return 'Selasa';
        case 3:
            return 'Rabu';
        case 4:
            return 'Kamis';
        case 5:
            return 'Jumat';
        case 6:
            return 'Sabtu';
        default:
            return `Hari ${hari}`;
    }
}

/** `dokter_jadwal.tipe_layanan`, in the admin surface's words. */
export function labelTipeLayanan(tipe: TipeLayananJadwalAdmin | string): string {
    switch (tipe) {
        case 'online':
            return 'Online';
        case 'klinik':
            return 'Klinik';
        case 'home_visit':
            return 'Kunjungan rumah';
        default:
            return tipe;
    }
}

/** `dokter.status_verifikasi`, in the words the pattern fixes. */
export function labelStatusVerifikasi(status: StatusVerifikasiAdmin): string {
    switch (status) {
        case 'pending':
            return 'Menunggu verifikasi';
        case 'terverifikasi':
            return 'Terverifikasi';
        case 'ditolak':
            return 'Ditolak';
        default:
            return status;
    }
}

/** `audit_log.aksi`, the DDL's eight values. */
export function labelAksi(aksi: AksiAudit | string): string {
    switch (aksi) {
        case 'create':
            return 'Dibuat';
        case 'read':
            return 'Dibaca';
        case 'update':
            return 'Diubah';
        case 'delete':
            return 'Dihapus';
        case 'login':
            return 'Masuk';
        case 'logout':
            return 'Keluar';
        case 'download':
            return 'Diunduh';
        case 'export':
            return 'Diekspor';
        default:
            return aksi;
    }
}

/** `persetujuan_pdp.jenis`, the DDL's five values. */
export function labelJenisPdp(jenis: JenisPersetujuanPdp | string): string {
    switch (jenis) {
        case 'syarat_ketentuan':
            return 'Syarat dan ketentuan';
        case 'kebijakan_privasi':
            return 'Kebijakan privasi';
        case 'berbagi_data_medis':
            return 'Berbagi data medis';
        case 'pemasaran':
            return 'Pemasaran';
        case 'komunikasi_tindak_lanjut':
            return 'Komunikasi tindak lanjut';
        default:
            return jenis;
    }
}

/** `booking.status`, for the report table's fixed eight-column vocabulary. */
export function labelStatusBooking(status: StatusBookingLaporan | string): string {
    switch (status) {
        case 'menunggu_pembayaran':
            return 'Menunggu pembayaran';
        case 'terjadwal':
            return 'Terjadwal';
        case 'check_in':
            return 'Check-in';
        case 'berlangsung':
            return 'Berlangsung';
        case 'selesai':
            return 'Selesai';
        case 'dibatalkan':
            return 'Dibatalkan';
        case 'no_show':
            return 'Tidak hadir';
        case 'kadaluarsa':
            return 'Kadaluarsa';
        default:
            return status;
    }
}

/**
 * `users.tipe`, the DDL's eight values (`telemedicine_test.sql:139`).
 *
 * The account directory's own vocabulary: every row of `GET /admin/pengguna`
 * carries one of these, and `staf` - the filter ALIAS, not a value - never
 * reaches this function because it is expanded before any row exists.
 */
export function labelTipeAkun(tipe: UserTipe | string): string {
    switch (tipe) {
        case 'pasien':
            return 'Pasien';
        case 'dokter':
            return 'Dokter';
        case 'perawat':
            return 'Perawat';
        case 'apoteker':
            return 'Apoteker';
        case 'kurir':
            return 'Kurir';
        case 'admin':
            return 'Admin';
        case 'superadmin':
            return 'Superadmin';
        case 'marketing':
            return 'Marketing';
        default:
            return tipe;
    }
}

/**
 * `users.status`, in the words an operator reads - every value the server's
 * `UserStatus` enum allows (migration `000090` widened the column to seven).
 *
 * All seven are shown by the directory - `pending_verifikasi` and
 * `ditangguhkan` are states an operator must SEE even though no button on this
 * surface writes them (see the server's `STATUS_UBAH`), and the three flow
 * states (`menunggu_persetujuan`, `diundang`, `ditolak`) are exactly the ones
 * the directory's decision buttons act on.
 */
export function labelStatusAkun(status: UserStatus | string): string {
    switch (status) {
        case 'pending_verifikasi':
            return 'Menunggu verifikasi';
        case 'menunggu_persetujuan':
            return 'Menunggu persetujuan';
        case 'diundang':
            return 'Diundang';
        case 'aktif':
            return 'Aktif';
        case 'ditolak':
            return 'Ditolak';
        case 'nonaktif':
            return 'Nonaktif';
        case 'ditangguhkan':
            return 'Ditangguhkan';
        default:
            return status;
    }
}

/**
 * `audit_log.tabel_target`, in the words an operator reads.
 *
 * Unlike every other label here this is NOT a closed `Rule::in` vocabulary -
 * the column holds a table name, and the audited set is DERIVED by
 * `AuditScope` from the schema's foreign keys, so a new table can appear
 * without this file changing. The two roots a person IS get their own words
 * (`users` is "Pengguna", the directory this portal administers, not the
 * English "users"), the rest fall through to a mechanical underscore-to-space
 * humanisation, and the raw name is the last resort - never a blank cell.
 */
export function labelTabelTarget(tabel: string): string {
    switch (tabel) {
        case 'users':
            return 'Pengguna';
        case 'pasien':
            return 'Pasien';
        case 'dokter':
            return 'Dokter';
        default:
            return tabel.replace(/_/g, ' ');
    }
}

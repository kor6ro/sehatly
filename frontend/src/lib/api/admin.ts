import { queryOptions } from '@tanstack/react-query';
import { request } from '@/lib/http';
import type { Decimal, Iso, Tanggal } from '@/lib/api/types';

/**
 * F14's admin surface: the sixteen `/api/v1/admin/*` routes, typed from the
 * resources that publish them.
 *
 * ## Every shape is a transcription of one `App\Http\Resources\Admin*` class
 *
 * | type | resource |
 * | --- | --- |
 * | {@link AdminDokter} | `AdminDokterResource` |
 * | {@link AdminJadwal} | `AdminJadwalResource` |
 * | {@link AdminLibur} | `AdminLiburResource` |
 * | {@link AdminAuditLog} | `AdminAuditLogResource` |
 * | {@link AdminPdpLedger} | `AdminPersetujuanPdpResource` |
 * | {@link AdminDampak} | `AdminDokterService::dampak()` |
 * | {@link LaporanBooking} / {@link LaporanPendapatan} / {@link LaporanKehadiran} | `AdminLaporanService` |
 *
 * The report module names come from the controller actions rather than from
 * resources, because those three actions answer service arrays directly.
 *
 * ## The two things this module refuses to do
 *
 * 1. **No client-side masking.** `nomor_str` and `nomor_sip` arrive masked from
 *    `NikMasker` and are rendered exactly as received. There is no operation in
 *    this client that can turn one back into a credential, and adding one would
 *    be the leak the server's masking exists to prevent.
 * 2. **No bulk endpoint.** `POST /admin/dokter/aksi-massal` was proposed by the
 *    F14 pattern and deliberately not built; ten suspensions are ten
 *    `PUT /admin/dokter/{id}/status` calls, and the per-row answer is what the
 *    pattern's "hasil per-item" rule needs. {@link ubahStatusDokter} is the one
 *    write, and the list page fans it out.
 *
 * ## Money is a string
 *
 * `AdminLaporanService` publishes every revenue figure as a DECIMAL string with
 * two places (`"1245000.00"`), never a JSON number. Typing it `number` is how a
 * screen renders `Rp NaN`; read it through `formatRupiah`.
 */

// ============================================================================
// dokter
// ============================================================================

/** `dokter.status_verifikasi`, the three-value ENUM at `telemedicine_test.sql:427`. */
export type StatusVerifikasiAdmin = 'pending' | 'terverifikasi' | 'ditolak';

/** The three sorts `IndexAdminDokterRequest` accepts; the default is the first. */
export type UrutanDokter = 'str_berlaku_sampai' | 'nama' | 'jumlah_konsultasi';

/** `AdminDokterResource::spesialisasiUtama()`, `null` when none is on file. */
export type AdminSpesialisasiUtama = {
    spesialisasi_id: number;
    kode: string | null;
    nama: string | null;
    is_utama: boolean;
};

/**
 * One row of `GET /admin/dokter` and the `data.dokter` of the detail and of both
 * writes.
 *
 * ## The credential keys are the MASKED form and that is load-bearing
 *
 * `nomor_str` is `3334••••••••3456`, never sixteen digits. The four `str_*` /
 * `sip_*` computed keys exist because the server owns the "expiring soon"
 * arithmetic (`<= 60` days, `StrBerlaku`'s inclusive boundary) and the UI must
 * not re-derive it: a client-side badge that disagreed with the server about
 * which doctor is close to lapsing is exactly the defect the computed keys
 * prevent. `null` on the SIP keys means "no SIP on file", a different fact from
 * "expired".
 *
 * `akun_dihapus` is true when the `users` row behind the doctor is soft-deleted
 * - the admin query joins without the scope specifically so this row stays
 * visible and the operator can see why a doctor stopped appearing.
 */
export type AdminDokter = {
    id: number;
    user_id: number;
    nama_lengkap: string | null;
    akun_dihapus: boolean;
    tipe: string;
    /** Masked, exactly as received. Never rendered in full and never in a URL. */
    nomor_str: string | null;
    str_berlaku_sampai: Tanggal;
    str_sisa_hari: number | null;
    str_kedaluwarsa: boolean;
    str_segera_kedaluwarsa: boolean;
    /** Masked. `null` when no SIP is on file - not "expired". */
    nomor_sip: string | null;
    sip_berlaku_sampai: Tanggal;
    sip_sisa_hari: number | null;
    sip_kedaluwarsa: boolean;
    sip_segera_kedaluwarsa: boolean;
    status_verifikasi: StatusVerifikasiAdmin;
    status_aktif: boolean;
    tersedia_telemedisin: boolean;
    pengalaman_tahun: number | null;
    biaya_konsultasi_online: Decimal;
    rating_rata_rata: Decimal;
    jumlah_ulasan: number | null;
    jumlah_konsultasi: number;
    /** All statuses: how much history the doctor has, not the future-consuming count. */
    jumlah_booking: number | null;
    spesialisasi_utama: AdminSpesialisasiUtama | null;
    dibuat_at: Iso;
    diubah_at: Iso;
};

/**
 * The blast-radius counts `AdminDokterService::dampak()` publishes before a
 * destructive decision. Nothing here cancels anything: the numbers exist so the
 * dialog can say "4 booking aktif tidak otomatis dibatalkan" before an admin
 * commits.
 */
export type AdminDampak = {
    booking_aktif: number;
    jadwal_aktif: number;
    libur_mendatang: number;
};

export type AdminDokterListFilter = {
    q?: string;
    status_verifikasi?: StatusVerifikasiAdmin;
    /** Sent as `'true'`/`'false'`; the request's `boolean` rule accepts both spellings. */
    status_aktif?: boolean;
    tersedia_telemedisin?: boolean;
    urutan?: UrutanDokter;
    page?: number;
    per_page?: number;
};

// ============================================================================
// jadwal / libur
// ============================================================================

/**
 * `dokter_jadwal.tipe_layanan`, ENUM('online','klinik','home_visit') at
 * `telemedicine_test.sql:473`.
 *
 * **Not** the booking enum's four values: the two share only `home_visit`.
 */
export type TipeLayananJadwalAdmin = 'online' | 'klinik' | 'home_visit';

/**
 * One `dokter_jadwal` row as the admin schedule tab publishes it.
 *
 * Every column the tab can write round-trips, so an edit form never loses a
 * field it did not touch. `hari` is `0=Minggu .. 6=Sabtu` and `hari_label` is
 * the server's own Indonesian label. Times are the STORED `H:i:s` spelling and
 * are never zone-shifted: a `TIME` is a wall clock, rendered `08.00 WIB`.
 *
 * `kuota_per_sesi` distinguishes `null` ("no quota set") from `0` ("no slots"),
 * and `booking_aktif` is the same count the delete guard uses - it is what
 * chooses between offering "Hapus" and "Nonaktifkan".
 */
export type AdminJadwal = {
    id: number;
    dokter_id: number;
    faskes_id: number | null;
    tipe_layanan: TipeLayananJadwalAdmin;
    hari: number;
    hari_label: string | null;
    jam_mulai: string;
    jam_selesai: string;
    durasi_slot_menit: number;
    kuota_per_sesi: number | null;
    berlaku_mulai: Tanggal;
    berlaku_sampai: Tanggal;
    status_aktif: boolean;
    booking_aktif: number;
    dibuat_at: Iso;
    diubah_at: Iso;
};

/** One whole-day leave date: `dokter_libur` has no time columns at all. */
export type AdminLibur = {
    id: number;
    dokter_id: number;
    tanggal: Tanggal;
    alasan: string | null;
};

/** The `POST /admin/dokter/{id}/jadwal` body: one row per `hari` entry, atomically. */
export type StoreJadwalBody = {
    hari: number[];
    tipe_layanan: TipeLayananJadwalAdmin;
    faskes_id?: number | null;
    /** `H:i`. */
    jam_mulai: string;
    /** `H:i`. */
    jam_selesai: string;
    durasi_slot_menit: number;
    kuota_per_sesi?: number | null;
    /** `Y-m-d`. */
    berlaku_mulai: string;
    /** `Y-m-d`. */
    berlaku_sampai?: string | null;
    status_aktif: boolean;
};

/** The `PUT /admin/jadwal/{id}` body: every field is optional, and `hari` is one day. */
export type UpdateJadwalBody = Omit<Partial<StoreJadwalBody>, 'hari'> & {
    hari?: number;
};

// ============================================================================
// audit log
// ============================================================================

/** `audit_log.aksi`, the DDL's eight values at `telemedicine_test.sql:1121`. */
export type AksiAudit =
    | 'create'
    | 'read'
    | 'update'
    | 'delete'
    | 'login'
    | 'logout'
    | 'download'
    | 'export';

/**
 * One `audit_log` row, already redacted at WRITE time by `AuditColumnPolicy`.
 *
 * `user_id` is a historical id with no name joined on purpose - the trail must
 * survive the user's deletion. `record_id` is a string because composite keys
 * are stored `"12|34"`, and the UI renders it as-is rather than parsing it.
 * There is no update or delete anywhere on this type because the table has no
 * update timestamp and the route table has no write.
 */
export type AdminAuditLog = {
    id: number;
    user_id: number | null;
    aksi: AksiAudit;
    tabel_target: string;
    record_id: string | null;
    data_lama: Record<string, unknown> | null;
    data_baru: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    endpoint: string | null;
    dibuat_at: Iso;
};

export type AdminAuditFilter = {
    aksi?: AksiAudit;
    tabel_target?: string;
    record_id?: string;
    aktor_user_id?: number;
    dari?: string;
    sampai?: string;
    page?: number;
    per_page?: number;
};

// ============================================================================
// persetujuan PDP
// ============================================================================

/**
 * `persetujuan_pdp.jenis`, the DDL's five values at `telemedicine_test.sql:1137`.
 * Kept as a local union rather than importing the subject-facing module so the
 * admin surface is readable on its own.
 */
export type JenisPersetujuanPdp =
    | 'syarat_ketentuan'
    | 'kebijakan_privasi'
    | 'berbagi_data_medis'
    | 'pemasaran'
    | 'komunikasi_tindak_lanjut';

/**
 * One row of the READ-ONLY admin PDP ledger.
 *
 * Five fields and no sixth: `ip_address` and a joined name are deliberately
 * withheld by the resource - a consent audit names the record, not the person.
 * The page renders what is here and offers no write, because a consent is the
 * subject's own act.
 */
export type AdminPdpLedger = {
    id: number;
    user_id: number;
    jenis: JenisPersetujuanPdp;
    versi_dokumen: string;
    disetujui: boolean;
    disetujui_at: Iso;
};

export type AdminPdpFilter = {
    user_id?: number;
    jenis?: JenisPersetujuanPdp;
    disetujui?: boolean;
    page?: number;
    per_page?: number;
};

// ============================================================================
// laporan
// ============================================================================

/** The eight `booking.status` values `AdminLaporanService` zero-fills. */
export const STATUS_BOOKING_LAPORAN = [
    'menunggu_pembayaran',
    'terjadwal',
    'check_in',
    'berlangsung',
    'selesai',
    'dibatalkan',
    'no_show',
    'kadaluarsa',
] as const;

export type StatusBookingLaporan = (typeof STATUS_BOOKING_LAPORAN)[number];

export type LaporanBookingHarian = {
    tanggal: string;
    total: number;
    per_status: Record<string, number>;
};

export type LaporanBooking = {
    ringkasan: {
        total: number;
        /** All eight statuses, zeros included, so the table's shape is fixed. */
        per_status: Record<string, number>;
    };
    harian: LaporanBookingHarian[];
};

export type LaporanPendapatanHarian = {
    tanggal: string;
    /** DECIMAL string, e.g. `"620000.00"`. */
    total: string;
    jumlah_invoice: number;
};

export type LaporanPendapatan = {
    ringkasan: {
        total: string;
        jumlah_invoice: number;
    };
    harian: LaporanPendapatanHarian[];
};

/**
 * The attendance aggregate.
 *
 * `no_show` has no writer in the application today, so its count is always zero
 * and the key is published anyway. The page renders it as "-" with the note that
 * it is not yet recorded, rather than as a misleading `0`.
 */
export type LaporanKehadiran = {
    ringkasan: {
        total: number;
        check_in: number;
        selesai: number;
        no_show: number;
    };
    harian: Array<{
        tanggal: string;
        total: number;
        check_in: number;
        selesai: number;
        no_show: number;
    }>;
};

export type LaporanRange = {
    dari: string;
    sampai: string;
    dokter_id?: number;
};

// ============================================================================
// fetchers
// ============================================================================

/**
 * A query-parameter record with undefined values dropped.
 *
 * `ky` would stringify a boolean to `"false"` and an absent key to `"undefined"`
 * only if handed one; building the record explicitly is what keeps "no filter"
 * and "the string 'undefined'" from being the same request.
 */
function params(
    input: Record<string, string | number | boolean | undefined>,
): Record<string, string> {
    const out: Record<string, string> = {};

    for (const [key, value] of Object.entries(input)) {
        if (value === undefined || value === '') {
            continue;
        }

        out[key] = String(value);
    }

    return out;
}

export async function fetchAdminDokter(filter: AdminDokterListFilter = {}) {
    return request<{ dokter: AdminDokter[] }>('admin/dokter', {
        searchParams: params({
            q: filter.q,
            status_verifikasi: filter.status_verifikasi,
            status_aktif: filter.status_aktif,
            tersedia_telemedisin: filter.tersedia_telemedisin,
            urutan: filter.urutan,
            page: filter.page,
            per_page: filter.per_page,
        }),
    });
}

export async function fetchAdminDokterDetail(id: number) {
    return request<{ dokter: AdminDokter; dampak: AdminDampak }>(
        `admin/dokter/${id}`,
    );
}

export async function verifikasiDokter(
    id: number,
    status: 'terverifikasi' | 'ditolak',
) {
    return request<{ dokter: AdminDokter; dampak: AdminDampak }>(
        `admin/dokter/${id}/verifikasi`,
        { method: 'PUT', json: { status_verifikasi: status } },
    );
}

export async function ubahStatusDokter(
    id: number,
    body: { status_aktif: boolean; tersedia_telemedisin?: boolean },
) {
    return request<{ dokter: AdminDokter; dampak: AdminDampak }>(
        `admin/dokter/${id}/status`,
        { method: 'PUT', json: body },
    );
}

export async function fetchAdminJadwal(dokterId: number) {
    return request<{ jadwal: AdminJadwal[] }>(`admin/dokter/${dokterId}/jadwal`);
}

export async function simpanJadwal(dokterId: number, body: StoreJadwalBody) {
    return request<{ jadwal: AdminJadwal[] }>(
        `admin/dokter/${dokterId}/jadwal`,
        { method: 'POST', json: body },
    );
}

export async function ubahJadwal(id: number, body: UpdateJadwalBody) {
    return request<{ jadwal: AdminJadwal }>(`admin/jadwal/${id}`, {
        method: 'PUT',
        json: body,
    });
}

export async function hapusJadwal(id: number) {
    return request<{ deleted: boolean; id: number }>(`admin/jadwal/${id}`, {
        method: 'DELETE',
    });
}

export async function fetchAdminLibur(dokterId: number) {
    return request<{ libur: AdminLibur[] }>(`admin/dokter/${dokterId}/libur`);
}

export async function simpanLibur(
    dokterId: number,
    body: { tanggal: string; alasan?: string },
) {
    return request<{ libur: AdminLibur }>(`admin/dokter/${dokterId}/libur`, {
        method: 'POST',
        json: body,
    });
}

export async function hapusLibur(id: number) {
    return request<{ deleted: boolean; id: number }>(`admin/libur/${id}`, {
        method: 'DELETE',
    });
}

export async function fetchAdminAudit(filter: AdminAuditFilter = {}) {
    return request<{ audit: AdminAuditLog[] }>('admin/audit-log', {
        searchParams: params({
            aksi: filter.aksi,
            tabel_target: filter.tabel_target,
            record_id: filter.record_id,
            aktor_user_id: filter.aktor_user_id,
            dari: filter.dari,
            sampai: filter.sampai,
            page: filter.page,
            per_page: filter.per_page,
        }),
    });
}

export async function fetchAdminPdp(filter: AdminPdpFilter = {}) {
    return request<{ persetujuan_pdp: AdminPdpLedger[] }>(
        'admin/persetujuan-pdp',
        {
            searchParams: params({
                user_id: filter.user_id,
                jenis: filter.jenis,
                disetujui: filter.disetujui,
                page: filter.page,
                per_page: filter.per_page,
            }),
        },
    );
}

export async function fetchLaporanBooking(range: LaporanRange) {
    return request<LaporanBooking>('admin/laporan/booking', {
        searchParams: params({
            dari: range.dari,
            sampai: range.sampai,
            dokter_id: range.dokter_id,
        }),
    });
}

export async function fetchLaporanPendapatan(
    range: Pick<LaporanRange, 'dari' | 'sampai'>,
) {
    return request<LaporanPendapatan>('admin/laporan/pendapatan', {
        searchParams: params({ dari: range.dari, sampai: range.sampai }),
    });
}

export async function fetchLaporanKehadiran(
    range: Pick<LaporanRange, 'dari' | 'sampai'>,
) {
    return request<LaporanKehadiran>('admin/laporan/kehadiran', {
        searchParams: params({ dari: range.dari, sampai: range.sampai }),
    });
}

// ============================================================================
// query keys and options
// ============================================================================

export const adminDokterQueryKey = ['v1', 'admin', 'dokter'] as const;
export const adminJadwalQueryKey = ['v1', 'admin', 'jadwal'] as const;
export const adminLiburQueryKey = ['v1', 'admin', 'libur'] as const;
export const adminAuditQueryKey = ['v1', 'admin', 'audit-log'] as const;
export const adminPdpQueryKey = ['v1', 'admin', 'persetujuan-pdp'] as const;
export const adminLaporanQueryKey = ['v1', 'admin', 'laporan'] as const;

export function adminDokterOptions(filter: AdminDokterListFilter) {
    return queryOptions({
        queryKey: [...adminDokterQueryKey, filter],
        queryFn: () => fetchAdminDokter(filter),
    });
}

export function adminDokterDetailOptions(id: number) {
    return queryOptions({
        queryKey: [...adminDokterQueryKey, 'detail', id],
        queryFn: () => fetchAdminDokterDetail(id),
    });
}

export function adminJadwalOptions(dokterId: number) {
    return queryOptions({
        queryKey: [...adminJadwalQueryKey, dokterId],
        queryFn: () => fetchAdminJadwal(dokterId),
    });
}

export function adminLiburOptions(dokterId: number) {
    return queryOptions({
        queryKey: [...adminLiburQueryKey, dokterId],
        queryFn: () => fetchAdminLibur(dokterId),
    });
}

export function adminAuditOptions(filter: AdminAuditFilter) {
    return queryOptions({
        queryKey: [...adminAuditQueryKey, filter],
        queryFn: () => fetchAdminAudit(filter),
    });
}

export function adminPdpOptions(filter: AdminPdpFilter) {
    return queryOptions({
        queryKey: [...adminPdpQueryKey, filter],
        queryFn: () => fetchAdminPdp(filter),
    });
}

export function adminLaporanBookingOptions(range: LaporanRange) {
    return queryOptions({
        queryKey: [...adminLaporanQueryKey, 'booking', range],
        queryFn: () => fetchLaporanBooking(range),
    });
}

export function adminLaporanPendapatanOptions(
    range: Pick<LaporanRange, 'dari' | 'sampai'>,
) {
    return queryOptions({
        queryKey: [...adminLaporanQueryKey, 'pendapatan', range],
        queryFn: () => fetchLaporanPendapatan(range),
    });
}

export function adminLaporanKehadiranOptions(
    range: Pick<LaporanRange, 'dari' | 'sampai'>,
) {
    return queryOptions({
        queryKey: [...adminLaporanQueryKey, 'kehadiran', range],
        queryFn: () => fetchLaporanKehadiran(range),
    });
}

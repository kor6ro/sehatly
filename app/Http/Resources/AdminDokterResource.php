<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Dokter;
use App\Services\Admin\AdminDokterService;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the ADMIN doctor directory: `GET /admin/dokter` and the detail of
 * `GET /admin/dokter/{id}`.
 *
 * ## The allow-list is the point
 *
 * `Dokter` has 23 columns; this resource publishes sixteen of them and computes
 * four more. The ones deliberately absent are the two credential-document URLs
 * (`file_str_url`, `file_sip_url`) and `nomor_ihs_satusehat`:
 *
 * - The URLs point at scans of a licence. The F14 pattern allows them only
 *   behind a `dokter.kelola` grant through a signed URL; that code was not
 *   approved for F14, and `AuditColumnPolicy` denies both columns outright - so
 *   they are not published in any projection here. A URL is also a credential
 *   in its own right: a leaked signed link is a leaked scan.
 * - `nomor_ihs_satusehat` is the doctor's own SATUSEHAT practitioner id, which
 *   the doctor-facing `/me` projection already publishes to its owner and which
 *   no admin decision in F14 consumes.
 *
 * `bio` is absent for the same reason it is absent from the audit policy: it is
 * free-text narrative about a third party, and nothing on this surface needs it.
 *
 * ## Credentials are masked, never raw
 *
 * `nomor_str` and `nomor_sip` go through {@see NikMasker::mask()}, the ONE
 * masker the audit writer uses for `nomor_str` as well. `3334567890123456`
 * becomes `3334••••••••3456`; a value too short to hide an interior is returned
 * unchanged by the masker's own documented rule rather than by a second policy
 * here. A masked value is still enough for the operator to confirm "this is the
 * number the doctor read out" without the response body, the tab title or any
 * log line ever holding the credential itself.
 *
 * ## `str_berlaku_sampai` and the computed warning
 *
 * The STORED date is published verbatim (`Y-m-d`), plus three derived keys:
 *
 * | key | meaning |
 * | --- | --- |
 * | `str_sisa_hari` | signed days from the clinic's today; `0` = expires today, negative = lapsed |
 * | `str_kedaluwarsa` | `str_sisa_hari < 0` |
 * | `str_segera_kedaluwarsa` | `0 <= str_sisa_hari <= 60` |
 *
 * They exist because the F14 UI must not compute the badge itself (server is the
 * source of truth) and because a lifetime STR - which UU 17/2023 permits for new
 * doctors but `dokter.str_berlaku_sampai DATE NOT NULL` cannot represent - has no
 * sentinel here. The stored date is the whole truth; the warning is arithmetic on
 * it. The SIP keys are `null` when no SIP is on file, which is a different fact
 * from "expired" and is published as such.
 *
 * ## `akun_dihapus`
 *
 * The JOIN that loads `nama_lengkap` reads `users` without the `SoftDeletes`
 * scope, so this projection can show a doctor whose account has been soft-deleted.
 * `akun_dihapus` says so: without it an operator sees an active, verified doctor
 * who in fact cannot log in. It is `false` for every normal row.
 *
 * @property-read Dokter $resource
 */
class AdminDokterResource extends JsonResource
{
    /**
     * The row's `users.nama_lengkap`, selected by the ADMIN query's raw join
     * rather than read through the `user` relation, because the relation's
     * `SoftDeletes` scope would hide exactly the deleted-account rows this
     * surface exists to show.
     */
    private function namaLengkap(): ?string
    {
        $nama = $this->resource->getAttribute('nama_lengkap');

        return $nama === null ? null : (string) $nama;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $dokter = $this->resource;

        $sisaStr = AdminDokterService::sisaHari($dokter->str_berlaku_sampai);
        $sisaSip = AdminDokterService::sisaHari($dokter->sip_berlaku_sampai);

        return [
            'id' => $dokter->getKey(),
            'user_id' => $dokter->user_id,
            'nama_lengkap' => $this->namaLengkap(),
            // True when the `users` row behind this doctor is soft-deleted; the
            // admin query joins without the scope so the row is still visible.
            'akun_dihapus' => $dokter->getAttribute('user_dihapus_at') !== null,
            'tipe' => $dokter->tipe,

            // Credentials: masked, never in full.
            'nomor_str' => self::mask($dokter->nomor_str),
            'str_berlaku_sampai' => $dokter->str_berlaku_sampai?->format('Y-m-d'),
            'str_sisa_hari' => $sisaStr,
            'str_kedaluwarsa' => $sisaStr !== null && $sisaStr < 0,
            'str_segera_kedaluwarsa' => $sisaStr !== null
                && $sisaStr >= 0
                && $sisaStr <= AdminDokterService::STR_SEGERA_HARI,
            'nomor_sip' => self::mask($dokter->nomor_sip),
            'sip_berlaku_sampai' => $dokter->sip_berlaku_sampai?->format('Y-m-d'),
            'sip_sisa_hari' => $sisaSip,
            'sip_kedaluwarsa' => $sisaSip !== null && $sisaSip < 0,
            'sip_segera_kedaluwarsa' => $sisaSip !== null
                && $sisaSip >= 0
                && $sisaSip <= AdminDokterService::STR_SEGERA_HARI,

            'status_verifikasi' => $dokter->status_verifikasi,
            'status_aktif' => (bool) $dokter->status_aktif,
            'tersedia_telemedisin' => (bool) $dokter->tersedia_telemedisin,

            'pengalaman_tahun' => $dokter->pengalaman_tahun,
            'biaya_konsultasi_online' => $dokter->biaya_konsultasi_online,
            'rating_rata_rata' => $dokter->rating_rata_rata,
            'jumlah_ulasan' => $dokter->jumlah_ulasan,
            'jumlah_konsultasi' => $dokter->jumlah_konsultasi,
            // One `withCount('booking')` subquery, all statuses: history size,
            // not the future-consuming count of `dampak.booking_aktif`.
            'jumlah_booking' => $dokter->getAttribute('booking_count'),
            'spesialisasi_utama' => $this->spesialisasiUtama(),

            'dibuat_at' => $dokter->dibuat_at?->toISOString(),
            'diubah_at' => $dokter->diubah_at?->toISOString(),
        ];
    }

    /**
     * The doctor's main specialisation, or `null` when none is on file.
     *
     * `dokter_spesialisasi.is_utama` is a flag, not a unique key, so the order is
     * `is_utama DESC` then `spesialisasi_id ASC` - the same total order the
     * public detail resource uses, so the two projections cannot pick different
     * "main" rows for the same doctor.
     *
     * @return array<string, mixed>|null
     */
    private function spesialisasiUtama(): ?array
    {
        if (! $this->resource->relationLoaded('dokterSpesialisasi')) {
            return null;
        }

        $row = $this->resource->dokterSpesialisasi
            ->sortBy([
                fn ($a, $b): int => (int) $b->is_utama <=> (int) $a->is_utama,
                fn ($a, $b): int => (int) $a->spesialisasi_id <=> (int) $b->spesialisasi_id,
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'spesialisasi_id' => $row->spesialisasi_id,
            'kode' => $row->spesialisasi?->kode,
            'nama' => $row->spesialisasi?->nama,
            'is_utama' => (bool) $row->is_utama,
        ];
    }

    /**
     * Mask one credential through the project's single masker.
     *
     * `null` and the empty string stay `null`; {@see NikMasker::mask()} owns the
     * rule for everything else, including the refusal to fake a mask on a value
     * shorter than eight characters.
     */
    private static function mask(?string $nilai): ?string
    {
        if ($nilai === null || trim($nilai) === '') {
            return null;
        }

        return NikMasker::mask($nilai);
    }
}

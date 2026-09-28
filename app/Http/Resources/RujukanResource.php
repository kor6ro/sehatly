<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Rujukan;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One `rujukan` row, as the authenticated endpoints publish it.
 *
 * ## The single source of the referral shape
 *
 * The referral appears in two responses with the same fields: as the zero-or-one
 * element of `surat_keterangan.rujukan` in {@see SuratKeteranganResource}, and as the
 * `data.rujukan` object of the issuing doctor's 201 response. Both go through this
 * class so the two shapes cannot drift - a field added to one is added to both, and
 * the date formatting is decided once.
 *
 * ## Allow-list, and the same two date shapes as the letter
 *
 * `berlaku_sampai` is a `DATE` column (`:608`) and is an Asia/Jakarta wall-clock day -
 * `toDateString()` and never an instant, for the reason `SuratKeteranganResource`
 * gives. `dibuat_at` is a `TIMESTAMP` and is a rule-(1) instant, so `toISOString()`.
 *
 * @property-read Rujukan $resource
 */
class RujukanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Rujukan $row */
        $row = $this->resource;

        return [
            'id' => (int) $row->getKey(),
            'surat_keterangan_id' => $row->surat_keterangan_id,
            'faskes_asal_id' => $row->faskes_asal_id,
            'faskes_tujuan_id' => $row->faskes_tujuan_id,
            'dokter_perujuk_id' => $row->dokter_perujuk_id,
            'diagnosis_kerja' => $row->diagnosis_kerja,
            'icd10_kode' => $row->icd10_kode,
            'alasan_rujukan' => $row->alasan_rujukan,
            'berlaku_sampai' => $this->tanggal($row->berlaku_sampai),
            'nomor_sep' => $row->nomor_sep,
            'status' => $row->status,
            'dibuat_at' => $this->instans($row->dibuat_at),
        ];
    }

    /**
     * A `DATETIME` or `TIMESTAMP` as an ISO-8601 UTC instant, or null.
     *
     * `DateTimeInterface` and NOT `Illuminate\Support\Carbon`: on laravel/framework
     * 13.33 an Eloquent `datetime` cast returns a `Carbon\CarbonImmutable`, a SIBLING
     * of `Carbon\Carbon` and therefore not an `Illuminate\Support\Carbon` either, so an
     * `instanceof Carbon` check would return null and the response would quietly
     * publish no timestamp. `SuratKeteranganResource` documents the same trap.
     */
    private function instans(mixed $nilai): ?string
    {
        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toISOString();
    }

    /**
     * A `DATE` as an Asia/Jakarta wall-clock day, or null.
     *
     * NEVER offset-converted and never an instant - see the class docblock.
     */
    private function tanggal(mixed $nilai): ?string
    {
        if (! $nilai instanceof DateTimeInterface) {
            return null;
        }

        return Carbon::instance($nilai)->toDateString();
    }
}
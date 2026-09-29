<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersetujuanPdp;
use App\Services\Pdp\PdpConsent;
use App\Services\Pdp\PdpConsentService;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One entry of the consent checklist: a `jenis`, and the answer the version rule
 * produces for it.
 *
 * ## Two shapes, one class, and why the checklist needs the nullable one
 *
 * `POST /api/v1/pdp/persetujuan` publishes the row it just wrote, which always
 * exists, so `new PersetujuanPdpResource($row)` is the whole call. The checklist
 * has five slots and at most five rows, so it needs to publish a `jenis` that has
 * NO row at all - and a `JsonResource` wrapping `null` has no way to say which of
 * the five kinds it stands for. {@see untuk()} carries the `jenis` alongside the
 * nullable row, which is the only way a five-slot checklist can have no holes.
 *
 * ## `efektif` is a THREE-state value and the other keys are published as null
 *
 * `efektif` is `true`, `false`, or `null`, and `null` means "no row has ever been
 * recorded for this document" - a different fact from `false`, which means "a row
 * says the person refused".
 * {@see PdpConsent::effective()} is what makes the distinction
 * available and {@see PdpConsent::disetujui()} is what the gates
 * use, where collapsing the two into `false` is the safe direction.
 *
 * The remaining keys are published as `null` rather than omitted, for the same
 * reason: a client rendering five slots should not have to distinguish "the key is
 * missing" from "the value is null", and `json_decode` turns a missing key and an
 * explicit null into the same PHP value anyway - so publishing it is the only shape
 * in which the contract is stable.
 *
 * ## `ip_address` is published, and that is a decision
 *
 * `ip_address VARCHAR(45) NULL` (`:1142`) is the address the decision was taken
 * from, and it is part of what a data subject is entitled to see about the record
 * held about them. It is NOT masked, unlike `pasien.nik` through
 * {@see NikMasker}, because an address collected for a consent row is
 * that row's own evidence rather than an identifier of a clinical subject - and the
 * row is only ever readable by the account it belongs to, because the query is
 * scoped in the service and not by a filter the client controls.
 *
 * ## `disetujui_at` is ISO-8601 UTC
 *
 * `disetujui_at DATETIME NOT NULL` (`:1141`) is a rule-(1) INSTANT under the plan's
 * todo 51 policy. The column is `DATETIME` and the model casts it to
 * `Illuminate\Support\Carbon`, so `Carbon::instance()` is a no-op guard that keeps
 * the resource correct if the cast is ever changed.
 *
 * ## The row is the one the version rule reads
 *
 * Nothing is re-derived here. The resource is handed the row
 * {@see PdpConsentService::ringkasan()} already selected with
 * `versi_dokumen DESC`, and publishing a lower row while calling it `efektif`
 * would be the exact defect this todo exists to prevent, one layer up.
 *
 * @property-read PersetujuanPdp|null $resource
 */
class PersetujuanPdpResource extends JsonResource
{
    /**
     * The `jenis` a nullable checklist entry stands for.
     *
     * A declared property rather than a dynamic one: PHP 8.2 deprecated dynamic
     * properties, and a deprecation notice in a response path is the kind of thing
     * that survives a release.
     */
    private ?string $jenis = null;

    /**
     * A checklist slot, which may have no row behind it.
     */
    public static function untuk(string $jenis, ?PersetujuanPdp $baris): self
    {
        $sumber = new self($baris);
        $sumber->jenis = $jenis;

        return $sumber;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $baris = $this->resource;

        return [
            'jenis' => $this->jenis ?? ($baris === null ? null : (string) $baris->jenis),
            // THE three-state answer: `null` when there is no row at all.
            'efektif' => $baris === null ? null : (bool) $baris->disetujui,
            'versi_dokumen' => $baris === null ? null : (string) $baris->versi_dokumen,
            'disetujui_at' => $baris === null || $baris->disetujui_at === null
                ? null
                : Carbon::instance($baris->disetujui_at)->toISOString(),
            'ip_address' => $baris === null || $baris->ip_address === null
                ? null
                : (string) $baris->ip_address,
        ];
    }
}

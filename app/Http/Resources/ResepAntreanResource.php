<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Resep;
use App\Services\Resep\ResepAccess;
use App\Services\Resep\ResepStateMachine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One queue row for the pharmacist's verification worklist.
 *
 * ## Why this is NOT `ResepResource`
 *
 * `ResepResource` is the DETAIL shape: it publishes `items` (drug names,
 * strengths, dosing rules), `catatan_dokter` (the prescriber's clinical note),
 * `qr_token` (a credential a counter can scan) and five surrogate foreign keys.
 * A queue is an INDEX - its job is to answer "which prescriptions still need a
 * signature, oldest or newest first" - and none of that content is needed to
 * decide which row to open. Publishing it would put every patient's medication
 * list on one screen for the whole pharmacy, which is a bigger disclosure than
 * the decision requires.
 *
 * So the surface is deliberately MINIMAL, and every omission is one:
 *
 * | omitted | why |
 * | --- | --- |
 * | `items` / drug names | medication history. The detail screen reads it, under `ResepAccess::untukBaca()` |
 * | `catatan_dokter` | a clinical note written for a different reader |
 * | `qr_token` | a dispense credential; the queue never dispenses |
 * | `pasien_id`, `dokter_id`, `apotek_id`, `konsultasi_id`, `rekam_medis_id` | surrogates the queue does not need to address a row |
 * | patient name, `nik`, phone, address, allergy | not read, not loaded, not publishable from this resource |
 *
 * No NIK, no contact detail, no medical history, no credential. The detail
 * endpoint remains the one place a pharmacist reads clinical content, and it
 * is already gated per row.
 *
 * ## `is_kedaluwarsa` and `terminal` are on the row for the same reason they are on every ResepResource
 *
 * Both are computed by {@see ResepStateMachine} and not stored, so the queue
 * computes them from the SAME two rules the detail does. A prescription whose
 * paper validity lapsed can still read `aktif` (nothing in the schema reacts to
 * `berlaku_sampai`), and the pharmacist who has to decide whether to reject it
 * must see that at queue time rather than after opening it. `terminal` is the
 * counterpart flag, published for the same "can I still act on this" reason
 * `ResepResource` documents: a client's affordance must not depend on which
 * endpoint answered.
 *
 * ## `jumlah_item` is eager-loaded, never counted per row
 *
 * The value comes from a `withCount('resepItem')` performed in
 * {@see ResepAccess::antrean()}. `resepVerifikasi` is
 * eager-loaded there too, because `ResepStateMachine::terminal()` consults it
 * for any row whose status is not itself terminal - which is every row in this
 * queue. Without the eager loads the queue would issue one query per row for a
 * count and another for a verification flag.
 *
 * @property-read Resep $resource
 */
class ResepAntreanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->resource->getKey(),
            'nomor_resep' => (string) $this->resource->nomor_resep,
            'tipe' => (string) $this->resource->tipe,
            'status' => (string) $this->resource->status,
            'tanggal_resep' => $this->resource->tanggal_resep?->toISOString(),
            'berlaku_sampai' => $this->resource->berlaku_sampai?->toDateString(),
            'is_kedaluwarsa' => ResepStateMachine::kedaluwarsa($this->resource),
            'terminal' => ResepStateMachine::terminal($this->resource),
            'jumlah_item' => (int) ($this->resource->resep_item_count ?? 0),
        ];
    }
}

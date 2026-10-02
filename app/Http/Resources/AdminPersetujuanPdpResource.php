<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersetujuanPdp;
use App\Services\Pdp\PdpConsentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ROW of the admin PDP ledger - a decision as it was recorded, never a
 * per-user summary.
 *
 * ## The minimum disclosure, and what is deliberately absent
 *
 * `persetujuan_pdp` has seven columns. This resource publishes five: the id, the
 * subject's `user_id`, the document kind, the version consented to, the decision
 * and when it was made.
 *
 * | absent | why |
 * | --- | --- |
 * | `ip_address` | the address consent was given from is the data subject's personal data. This surface answers "which consent exists, against which document version, and when" - it is an audit of the RECORD, not a reading of the person. The subject already sees their own IP through `GET /pdp/persetujuan`; an operator does not need it to reconcile a ledger. |
 * | a joined `users.nama_lengkap` | a name is not needed to audit a consent row, and joining one would put a second copy of personal data in a compliance projection. Tickets carry the `user_id`; the identity behind it is resolved on the surfaces that legitimately show a person. |
 *
 * The F14 pattern's privacy section says the PDP page is read-only and shows a
 * status per user; this resource is the most conservative reading of that, and
 * the choice is recorded here rather than left as an omission.
 *
 * ## `disetujui_at` is ISO-8601
 *
 * `disetujui_at DATETIME NOT NULL` (`:1141`) is the moment the decision was
 * recorded and the table's only chronology (it has no `created_at`). It is an
 * instant written by the application clock, so it is published as an ISO string
 * for the client to render in the device's zone.
 *
 * ## This is the LEDGER, not the checklist
 *
 * Every recorded row appears, in append order. The effective current answer for
 * one user is "the latest row per `(user, jenis)`", and that rule belongs to
 * {@see PdpConsentService} - the subject- facing
 * `GET /pdp/persetujuan` publishes it. Collapsing the ledger here would hide the
 * history a compliance read exists to see.
 *
 * @property-read PersetujuanPdp $resource
 */
class AdminPersetujuanPdpResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $baris = $this->resource;

        return [
            'id' => $baris->getKey(),
            'user_id' => $baris->user_id,
            'jenis' => $baris->jenis,
            'versi_dokumen' => $baris->versi_dokumen,
            'disetujui' => (bool) $baris->disetujui,
            'disetujui_at' => $baris->disetujui_at?->toISOString(),
        ];
    }
}

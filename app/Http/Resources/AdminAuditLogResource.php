<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use App\Services\Audit\AuditColumnPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One `audit_log` row, read-only and exactly as stored.
 *
 * ## Nothing here is raw, because nothing raw was ever stored
 *
 * `data_lama` and `data_baru` are already the output of
 * {@see AuditColumnPolicy::redact()} at WRITE time: denied
 * columns were dropped before the row existed, `nomor_str`/`nik`/phone/email
 * were masked, and every stored string was swept for 16-digit runs. This
 * resource therefore publishes the stored JSON verbatim - it cannot "un-redact"
 * it, and re-running the policy here would be a second, separately-driftable
 * copy of a decision that was already made once. The F14 AC-10 assertion (a
 * `nomor_str` change reads back masked) is a statement about the WRITER, and
 * this class is the proof that the reader never had the raw value to begin with.
 *
 * `ip_address`, `user_agent` and `endpoint` are stored as-is by the writer (the
 * policy does not strip them - they are the event's own provenance) and are
 * published as-is. They are not credentials and not clinical content, and an
 * audit viewer without them cannot answer "from where" or "through which route".
 *
 * `user_id` is a HISTORICAL identifier: it is a bare column with no foreign key
 * specifically so the row survives the user's deletion (migration 73), so this
 * resource publishes the id and deliberately does NOT join a name onto it. A
 * name would be a second copy of personal data in a compliance surface, and it
 * would disappear at exactly the moment the trail matters most.
 *
 * ## `dibuat_at` is ISO-8601
 *
 * The column is a `TIMESTAMP` (an instant, UTC under this application's pinned
 * session), and the client renders it in the device's zone. An ISO string is the
 * one form that carries the instant unambiguously.
 *
 * @property-read AuditLog $resource
 */
class AdminAuditLogResource extends JsonResource
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
            'aksi' => $baris->aksi,
            'tabel_target' => $baris->tabel_target,
            'record_id' => $baris->record_id,
            // Already redacted by `AuditColumnPolicy` when the row was written;
            // see the class docblock. `read`, `download` and `export` events
            // legitimately carry null payloads.
            'data_lama' => $baris->data_lama,
            'data_baru' => $baris->data_baru,
            'ip_address' => $baris->ip_address,
            'user_agent' => $baris->user_agent,
            'endpoint' => $baris->endpoint,
            'dibuat_at' => $baris->dibuat_at?->toISOString(),
        ];
    }
}

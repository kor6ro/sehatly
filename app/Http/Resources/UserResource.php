<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `users` projection this API publishes.
 *
 * ## `kata_sandi_hash` is not here, and that is the point
 *
 * `telemedicine_test.sql:138` is `kata_sandi_hash VARCHAR(255) NOT NULL COMMENT
 * 'bcrypt/argon2'`. The `User` model also carries an `#[Hidden(['kata_sandi_hash'])]`
 * attribute, so the column is already excluded from `toArray()`; this resource does not
 * rely on that. It names its own fields explicitly, which means a column added to
 * `users` later is invisible to the API until somebody adds it here on purpose. An
 * allow-list is the only shape that makes "the password hash can never be published"
 * a property of this file rather than a property of a model attribute a future edit
 * could remove.
 *
 * ## The field set is `users` only, and that is a scope decision
 *
 * No relation is loaded and none is exposed. `GET /api/v1/me` and the patient profile
 * are todo 21's, and its plan text asks for `pasien` and `dokter` eager-loaded with
 * `dokter_spesialisasi` and `dokter_pendidikan`, and for `pasien.nik` to be masked
 * rather than raw. Todo 21 should **extend** this resource with those relations rather
 * than write a second one, so there is exactly one `UserResource` in the tree.
 *
 * Register and OTP-verify are the only two endpoints that use it today, and both return
 * the caller's own row.
 *
 * ## Timestamps
 *
 * `toISOString()` rather than a hand-rolled format. The values come out of MySQL
 * `TIMESTAMP`/`DATETIME` columns, which carry no zone, and the application timezone is
 * `UTC` (`config/app.php`), so the emitted string ends in `Z` and is genuinely UTC. The
 * project-wide serialisation policy is todo 51's; this resource states which end of the
 * current state it is on rather than silently picking one.
 *
 * @property-read User $resource
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'uuid' => $this->resource->uuid,
            'nama_lengkap' => $this->resource->nama_lengkap,
            'no_telepon' => $this->resource->no_telepon,
            'email' => $this->resource->email,
            'tipe' => $this->resource->tipe,
            'status' => $this->resource->status,
            'bahasa' => $this->resource->bahasa,
            'foto_profil' => $this->resource->foto_profil,
            'telepon_terverifikasi' => (bool) $this->resource->telepon_terverifikasi,
            'email_terverifikasi' => (bool) $this->resource->email_terverifikasi,
            'last_login_at' => $this->resource->last_login_at?->toISOString(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
        ];
    }
}

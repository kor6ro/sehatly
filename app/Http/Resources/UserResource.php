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
 * ## The field set is `users`, plus two relations that appear only when loaded
 *
 * The scalar keys are `users` columns and nothing else; a column added to `users` later
 * is invisible to the API until somebody adds it here on purpose. The two relation keys
 * - `pasien` and `dokter` - are emitted by `whenLoaded()`, so:
 *
 * | endpoint | relations loaded | body |
 * | --- | --- | --- |
 * | `GET /api/v1/me` | both, eagerly | `pasien` and `dokter` keys present |
 * | `POST /api/v1/auth/sign-up` | neither | neither key present |
 * | `POST /api/v1/auth/otp/verify` | neither | neither key present |
 *
 * `whenLoaded()` rather than a hard `null` because `POST /auth/sign-up` creates the
 * `pasien` row in the same transaction and would have to publish `"pasien": null` - a
 * lie about a row that exists - or eager-load it and pay for a query the response does
 * not use. Omission is the honest answer in both directions, and it keeps the allow-list
 * property: a relation the caller did not ask for cannot appear by accident.
 *
 * The relations are **not** flattened into this resource. `pasien` and `dokter` are
 * different tables with their own rules - `pasien.nik` is masked by
 * {@see PasienResource}, and `dokter.nomor_str` is withheld by
 * {@see DokterAkunResource} - so each has its own projection and this one delegates.
 * That is also why the doctor resource is *not* called `DokterResource`: that name is
 * taken by todo 22's public directory projection, and the collision is documented in
 * {@see DokterAkunResource}.
 *
 * `GET /api/v1/me` is todo 21's and is the reason these two keys exist; register and
 * OTP-verify are the two Module 1 endpoints that use this resource, and both return the
 * caller's own row.
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

            // `GET /api/v1/me` eager-loads both; the Module 1 auth endpoints do not, and
            // `whenLoaded()` is what keeps the two from disagreeing about the shape.
            'pasien' => $this->whenLoaded('pasien', fn (): PasienResource => new PasienResource($this->pasien)),
            'dokter' => $this->whenLoaded('dokter', fn (): ?DokterAkunResource => $this->dokter === null
                ? null
                : new DokterAkunResource($this->dokter)),
        ];
    }
}

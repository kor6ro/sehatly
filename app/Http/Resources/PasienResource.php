<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Pasien;
use App\Support\NikCipher;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `pasien` projection this API publishes.
 *
 * ## `nik` and `nomor_kk` are masked here and nowhere else
 *
 * `telemedicine_test.sql:222` is
 * `nik CHAR(16) NULL UNIQUE COMMENT 'WAJIB dienkripsi (application-level/TDE) sesuai UU PDP'`
 * and the spec's Definition-of-Done this plan's todo 21 quotes is "tidak expose ...
 * `nik` mentah tanpa masking". {@see NikMasker} keeps the first four and the last four
 * characters and replaces everything between them with U+2022 BULLET, so the output is
 * always exactly as long as the input and the plan's example shape
 * `3273........0021` is reproduced without inventing a length.
 *
 * `nomor_kk` (`:223`, `CHAR(16)`) is masked for the same reason and is not named by the
 * DoD. It is the head-of-household family-card number, which is a personal identifier
 * in exactly the sense UU PDP 27/2022 Article 20 means, and publishing it raw beside a
 * masked `nik` would make the masking decorative. **This is a deliberate widening of the
 * plan's literal rule**, recorded here so it is visible rather than inherited: a client
 * that needs the value back has to re-read what it sent, which is the cost of not
 * publishing it.
 *
 * ## Three columns are deliberately NOT published
 *
 * - `user_id` - the row is always the caller's own, so which account owns it is noise.
 *   `UserDeviceResource` omits it for the same reason.
 * - `nomor_ihs_satusehat` (`:224`) - a Kemenkes SATUSEHAT identity. Not named by any
 *   todo's endpoint and not something a self-service screen has a use for.
 * - `catatan_alergi` (`:242`) - **a second, unsynchronised source of truth for
 *   allergies**, alongside the `pasien_alergi` table this API owns an endpoint for.
 *   Publishing both from the same response is how a client ends up showing two disagreeing
 *   allergy lists. `GET /api/v1/pasien/alergi` is the API's allergy surface; this
 *   free-text column is recorded in `docs/schema-notes.md` as out of sync with it.
 *
 * ## `nama_lengkap` comes from `users`, and is included anyway
 *
 * `pasien` has no name column; `users.nama_lengkap` (`:135`) holds it and
 * `PUT /api/v1/pasien/profil` writes it. A profile screen renders one screen, so this
 * resource publishes the name from the eager-loaded `user` relation and
 * `GET /api/v1/pasien/profil` loads it. It is duplicated from `UserResource` on purpose:
 * two projections of one account may each carry the field they need, and the alternative
 * - forcing every caller to fetch `/me` before it can render a profile - is a second
 * round trip for a string.
 *
 * ## Timestamps
 *
 * `toISOString()`, matching `UserResource`. The values come out of MySQL
 * `TIMESTAMP`/`DATETIME` columns that carry no zone; the project-wide serialisation policy
 * is todo 51's and this resource states which end of the current state it is on.
 * `tanggal_lahir` and `tanggal_meninggal` are `DATE` columns and are emitted as `Y-m-d`,
 * never converted - a birth date is a calendar date, not an instant, and shifting it by a
 * zone offset changes the person's birthday.
 *
 * ## The casts are called inline, and that is deliberate
 *
 * `tanggal_lahir` and `tanggal_meninggal` are called through `?->` rather than through a
 * private `date(?Carbon $value)` helper. A `?Illuminate\Support\Carbon` type hint is a
 * `TypeError` waiting to happen: on laravel/framework 13.33 the application boots with
 * `Date::use(CarbonImmutable::class)`, so `Model::asDateTime()` hands back a
 * `Carbon\CarbonImmutable`, which is a *sibling* of `Illuminate\Support\Carbon` and not an
 * instance of it. Inline calls are what `UserResource` and `UserDeviceResource` already
 * do, and a 500 on every `/me` is what the typed helper cost the first time this was run.
 *
 * @property-read Pasien $resource
 */
class PasienResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'nomor_rm' => $this->resource->nomor_rm,
            // `nik_cipher` is the PROPOSED encrypted column and does not exist yet,
            // so it reads as null today and the second argument - the legacy
            // plaintext column - is what gets masked. The call is written for the
            // shape both columns will have: payload first, legacy plaintext as the
            // fallback. See `App\Support\NikCipher` for why `CHAR(16)` cannot hold
            // the payload and the migration somebody has to author.
            'nik' => NikCipher::mask($this->resource->nik_cipher, $this->resource->nik),
            // `nomor_kk` is the family-card number, not the national identity, and
            // no cipher column is proposed for it, so there is nothing to decrypt:
            // it goes straight to the shared rule.
            'nomor_kk' => NikMasker::mask($this->resource->nomor_kk),
            'nama_lengkap' => $this->resource->relationLoaded('user')
                ? $this->resource->user->nama_lengkap
                : null,
            'jenis_kelamin' => $this->resource->jenis_kelamin,
            'tanggal_lahir' => $this->resource->tanggal_lahir?->toDateString(),
            'tempat_lahir' => $this->resource->tempat_lahir,
            'golongan_darah_id' => $this->resource->golongan_darah_id,
            'rhesus' => $this->resource->rhesus,
            'agama_id' => $this->resource->agama_id,
            'pendidikan_id' => $this->resource->pendidikan_id,
            'pekerjaan' => $this->resource->pekerjaan,
            'status_pernikahan_id' => $this->resource->status_pernikahan_id,
            'alamat_lengkap' => $this->resource->alamat_lengkap,
            'provinsi_id' => $this->resource->provinsi_id,
            'kabupaten_kota_id' => $this->resource->kabupaten_kota_id,
            'kecamatan_id' => $this->resource->kecamatan_id,
            'kelurahan_id' => $this->resource->kelurahan_id,
            'rt' => $this->resource->rt,
            'rw' => $this->resource->rw,
            'kode_pos' => $this->resource->kode_pos,
            'tinggi_badan_cm' => $this->resource->tinggi_badan_cm,
            'berat_badan_kg' => $this->resource->berat_badan_kg,
            // Read-only clinical flags. They are the caller's own data and a profile
            // screen shows them; they are not writable, because neither appears in
            // `UpdatePasienProfileRequest`'s rules.
            'is_meninggal' => (bool) $this->resource->is_meninggal,
            'tanggal_meninggal' => $this->resource->tanggal_meninggal?->toDateString(),
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
            'diubah_at' => $this->resource->diubah_at?->toISOString(),
        ];
    }
}

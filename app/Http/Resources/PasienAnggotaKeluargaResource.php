<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PasienAnggotaKeluarga;
use App\Support\NikCipher;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of `pasien_anggota_keluarga`, always the caller's own.
 *
 * ## What the table is for, and why that decides this projection
 *
 * `telemedicine_test.sql:258` is the DDL's own comment: "Anggota keluarga
 * (didaftarkan oleh pasien, TANPA akun sendiri)" - a family member is registered **by**
 * a patient and has **no account of its own**. So this row is never addressable by
 * anybody but the account holder: it is always reached through
 * `PasienRecordAccess::anggotaKeluargaQuery()`, and `pasien_id` is written from the
 * caller's own `pasien` row and is never read from a request. That is why `pasien_id` is
 * not published - echoing which account a row belongs to on a list that can only ever
 * contain one account's rows is noise.
 *
 * ## `nik` is masked
 *
 * `pasien_anggota_keluarga.nik` (`:263`) is a `CHAR(16)` national identifier, the same
 * class of value as `pasien.nik`, and it goes through {@see NikMasker} for the same
 * reason. The plan's masked-`nik` rule is written about `pasien.nik` specifically; this
 * is the consistent extension of it, and it is recorded rather than assumed - see
 * {@see PasienResource} for the wider statement.
 *
 * The cost is that a client cannot read a family member's NIK back in full, so an edit
 * form has to keep what it was given. That is the same trade the plan accepts for the
 * patient's own NIK, and it is the right way round: a value the client must re-submit is
 * a value the server never has to disclose.
 *
 * ## `hubungan` is included only when the relation was eager-loaded
 *
 * The label comes from `master_hubungan_keluarga.nama` (`:111`, "Pasangan/Anak/Orang
 * Tua/Saudara/Lainnya"). The detail endpoint loads it because a form needs to render
 * "Ibu" next to a `hubungan_id` of 3; the list endpoint does not, because a list of
 * family members is small and the id is enough to key the client's own copy of the
 * lookup table.
 *
 * @property-read PasienAnggotaKeluarga $resource
 */
class PasienAnggotaKeluargaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->getKey(),
            'hubungan_id' => $this->resource->hubungan_id,
            'hubungan' => $this->whenLoaded(
                'hubungan',
                fn (): ?string => $this->resource->hubungan?->nama,
            ),
            // `nik_cipher` is the PROPOSED encrypted column and does not exist yet,
            // so it reads as null and the legacy plaintext column is what gets
            // masked. Note that this table gets NO blind index: its `nik`
            // (telemedicine_test.sql:263) carries no UNIQUE, so an index here would
            // buy no integrity guarantee and would still link every relative of
            // every patient. See `App\Support\NikCipher`.
            'nik' => NikCipher::mask($this->resource->nik_cipher, $this->resource->nik),
            'nama_lengkap' => $this->resource->nama_lengkap,
            'jenis_kelamin' => $this->resource->jenis_kelamin,
            'tanggal_lahir' => $this->resource->tanggal_lahir?->toDateString(),
            'no_telepon' => $this->resource->no_telepon,
            'catatan_alergi' => $this->resource->catatan_alergi,
            'dibuat_at' => $this->resource->dibuat_at?->toISOString(),
        ];
    }
}

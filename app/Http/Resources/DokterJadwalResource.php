<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Services\Booking\SlotAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /api/v1/dokter/{dokter}/jadwal` -- the doctor's weekly window template,
 * grouped by `dokter_jadwal.hari`.
 *
 * ## This resource decides NOTHING
 *
 * {@see SlotAvailabilityService::getJadwal()} already owns
 * the grouping, the `status_aktif` filter, the out-of-range `hari` refusal and the
 * per-day ordering. This file only names the keys that go on the wire, for the
 * same reason {@see DokterSlotResource} does.
 *
 * ## Seven keys, always present, and published as a JSON **object**
 *
 * The service builds the map with `array_fill(0, 7, [])`, so a doctor with no
 * `dokter_jadwal` row answers seven empty days rather than an empty list. That is
 * the contract: a client can render a whole week without probing which days
 * exist, and "no rows" is indistinguishable from "no such key" precisely because
 * the key is always there.
 *
 * **The map is cast to an object, and that is a wire decision rather than a
 * cosmetic one.** PHP's `json_encode` renders an array whose keys are the
 * integers `0..6` as a JSON **array**, so a pass-through would publish
 * `[[], []]` while the plan, the service docblock and the web client's
 * `JadwalMinggu = Record<string, JadwalHari[]>` all describe a keyed map. A
 * `(object)` cast on string keys emits `{"0":[], "1":[]}`; the behaviour was
 * measured rather than assumed, and the test file asserts the decoded body is an
 * object with seven keys rather than an array of seven.
 *
 * The keys are the DDL's own numbering: `dokter_jadwal.hari` is
 * `TINYINT UNSIGNED NOT NULL COMMENT '0=Minggu s.d. 6=Sabtu'` at `:475`, which is
 * PHP's `date('w')`, so `0` is Sunday and `6` is Saturday and no translation
 * table exists anywhere in this path.
 *
 * ## The eight keys of one window, and where each comes from
 *
 * | key | DDL |
 * | --- | --- |
 * | `jadwal_id` | `dokter_jadwal.id` `:471` |
 * | `hari` | `dokter_jadwal.hari` `:475` |
 * | `tipe_layanan` | `dokter_jadwal.tipe_layanan` `:474`, the THREE-value ENUM |
 * | `faskes_id` | `dokter_jadwal.faskes_id` `:473`, `NULL` meaning online-only |
 * | `jam_mulai` | `dokter_jadwal.jam_mulai` `:476` |
 * | `jam_selesai` | `dokter_jadwal.jam_selesai` `:477` |
 * | `durasi_slot_menit` | `dokter_jadwal.durasi_slot_menit` `:478`, NOT `dokter.durasi_default_menit` `:422` |
 * | `kuota_per_sesi` | `dokter_jadwal.kuota_per_sesi` `:479`, `NULL` published as `null` and not as 1 |
 *
 * **`kuota_per_sesi` is the DDL's own `null`.** The service substitutes 1 when it
 * *computes* a slot's availability, but the published template must not: a client
 * rendering a weekly grid has to be able to tell a genuinely uncapped window from
 * one it has to assume holds a single seat. Substituting here would make the two
 * indistinguishable, and only the availability answer may make that assumption.
 *
 * ## Why this endpoint is not STR-gated, and what that means here
 *
 * `getJadwal()` applies only `status_aktif`, and that asymmetry is deliberate and
 * asserted by the service's own test: a weekly template is a profile attribute,
 * not an offer to practise medicine. **The eligibility decision is still made, one
 * layer up** -- `DokterController` answers 404 through
 * `DokterDirectoryService::find()` for a doctor who is absent, unverified,
 * inactive, off telemedicine, STR-expired or soft-deleted. So a lapsed licence is
 * a 404 here, and what reaches this resource has already passed that gate; what
 * this resource deliberately does not do is add a *second*, date-scoped STR gate
 * on top of it.
 *
 * The sharp edge is worth stating: a doctor whose licence is valid today but
 * lapses before the date the client goes on to ask about will be served a
 * perfectly good weekly template here, and then get `slots: []` from
 * `GET /slot` for the lapsed date. That is the service's rule 4 doing its job at
 * the only place the consultation date is known, and the test file pins both
 * halves of the pair.
 *
 * ## No timestamps
 *
 * `dibuat_at`/`diubah_at` are `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` at `:483` and
 * `:484` and are not published. A published template is not an audit record, and
 * `dibuat_at` would date the row rather than the week.
 *
 * @property-read array<int, list<array{
 *     jadwal_id: int,
 *     hari: int,
 *     tipe_layanan: string,
 *     faskes_id: int|null,
 *     jam_mulai: string,
 *     jam_selesai: string,
 *     durasi_slot_menit: int,
 *     kuota_per_sesi: int|null
 * }>> $resource
 */
class DokterJadwalResource extends JsonResource
{
    /**
     * @return array{jadwal: object}
     */
    public function toArray(Request $request): array
    {
        return ['jadwal' => $this->minggu()];
    }

    /**
     * The seven-day map, keyed by string and cast to an object.
     *
     * The return type is `object` rather than an array, and that is the whole
     * reason this method exists separately from `toArray()`: the class docblock
     * says why a JSON array would be the wrong thing to publish, and a bare
     * `array` cannot be told apart from one that will serialise as an object.
     *
     * The row allow-list is inline rather than delegated to a per-window resource
     * because a *day* has no shape of its own -- it is a bare list, not an object
     * -- so a resource per day would have to be a resource that publishes nothing
     * of its own. Naming the eight keys in one place is the allow-list the DDL
     * deserves, and `DokterJadwalSlotEndpointTest` re-derives them from the
     * parsed DDL so an added column cannot appear on the wire by accident.
     */
    private function minggu(): object
    {
        $hari = [];

        /** @var array<int, list<array<string, mixed>>> $minggu */
        $minggu = $this->resource;

        foreach ($minggu as $index => $baris) {
            $hari[(string) $index] = array_map(
                fn (array $satu): array => [
                    'jadwal_id' => $satu['jadwal_id'],
                    'hari' => $satu['hari'],
                    'tipe_layanan' => $satu['tipe_layanan'],
                    'faskes_id' => $satu['faskes_id'],
                    'jam_mulai' => $satu['jam_mulai'],
                    'jam_selesai' => $satu['jam_selesai'],
                    'durasi_slot_menit' => $satu['durasi_slot_menit'],
                    'kuota_per_sesi' => $satu['kuota_per_sesi'],
                ],
                $baris,
            );
        }

        return (object) $hari;
    }
}

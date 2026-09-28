<?php

declare(strict_types=1);

namespace App\Http\Requests\RekamMedis;

use App\Services\RekamMedis\RekamMedisService;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The shared base of this todo's four write requests.
 *
 * ## The ENUM lists come from the DDL, generated rather than transcribed
 *
 * {@see enumDdl()} re-parses `telemedicine_test.sql` with the project's own
 * {@see SqlSchemaParser} - the same parser `sehatly:verify-schema` uses - and returns
 * the values of a named `enum(...)` column. Three executors this month shipped a
 * hand-typed literal that had silently become a different string, one of them inside
 * a permission name, and an ENUM list is exactly that shape: a value differing from
 * the schema by one letter still looks right in a diff, would answer 422 for a
 * legitimate request, and would pass review.
 *
 * ## The machine-owned columns are `prohibited`, not merely absent
 *
 * `uuid`, `pasien_id`, `faskes_id`, `dokter_id`, `konsultasi_id`,
 * `satusehat_encounter_id`, `tipe_kunjungan`, `status_dokumen`, `versi`,
 * `ditandatangani_at`, `dibuat_at` and `diubah_at` are all written by the service from
 * the consultation, the state machine and the clock. A doctor who could set
 * `status_dokumen` could sign their own draft by POSTing `final`; one who could set
 * `pasien_id` could write into another patient's record. `prohibited` answers 422 with
 * a message naming the field rather than dropping it silently - the same reasoning
 * `SelesaikanKonsultasiRequest` gives for `mulai_at`.
 */
abstract class RekamMedisRequest extends FormRequest
{
    /**
     * The columns a doctor may never set, whatever the endpoint.
     *
     * @var list<string>
     */
    public const KOLOM_MILIK_SISTEM = [
        'uuid',
        'pasien_id',
        'faskes_id',
        'dokter_id',
        'konsultasi_id',
        'satusehat_encounter_id',
        'tipe_kunjungan',
        'status_dokumen',
        'versi',
        'ditandatangani_at',
        'dibuat_at',
        'diubah_at',
    ];

    /**
     * The ENUM values of one `telemedicine_test.sql` column, straight from the file.
     *
     * A `LogicException` and not a silent empty list, for the same reason the value
     * has to be generated at all: a typo'd table or column name would otherwise
     * produce a `Rule::in([])` that refuses every value, and a caller would be told
     * its request was invalid rather than that this code is broken.
     *
     * @return list<string>
     *
     * @throws LogicException when the table or column does not exist, or is not an ENUM
     */
    public static function enumDdl(string $tabel, string $kolom): array
    {
        $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
        $tabelSpec = $spec->table($tabel);

        if ($tabelSpec === null || ! isset($tabelSpec->columns[$kolom])) {
            throw new LogicException(
                'telemedicine_test.sql has no ['.$tabel.'.'.$kolom.'] column, so its ENUM values cannot be read. A '
                .'renamed table or column must fail here rather than produce an empty validation list.'
            );
        }

        $tipe = $tabelSpec->columns[$kolom]->type;

        if (preg_match("/^enum\((.*)\)$/", $tipe, $cocok) !== 1) {
            throw new LogicException(
                'telemedicine_test.sql column ['.$tabel.'.'.$kolom.'] is ['.$tipe.'] and not an enum(...).'
            );
        }

        return array_map(
            static fn (string $anggota): string => trim($anggota, "'"),
            explode(',', $cocok[1]),
        );
    }

    /**
     * The allow-listed content columns, each with its own ceiling, in DDL order.
     *
     * The ceiling is the DDL's, not a round number: `diagnosis_kerja` is
     * `VARCHAR(255) NULL` (:641) and the rest are `TEXT NULL` (:631-640, :642), so only
     * that one has a length the database itself would enforce. The `TEXT` ceilings are
     * a deliberate loose bound - the point is to refuse a megabyte pasted into a
     * clinical note, not to invent a business limit the schema does not have.
     *
     * @return array<string, array<int, mixed>>
     *
     * @throws LogicException when the allow-list and this table have drifted apart
     */
    public static function kolomIsi(): array
    {
        $aturan = [
            'keluhan_utama' => ['nullable', 'string', 'max:16000'],
            'riwayat_penyakit_sekarang' => ['nullable', 'string', 'max:16000'],
            'riwayat_penyakit_dahulu' => ['nullable', 'string', 'max:16000'],
            'riwayat_keluarga' => ['nullable', 'string', 'max:16000'],
            'riwayat_psikososial' => ['nullable', 'string', 'max:16000'],
            'hasil_pemeriksaan_fisik' => ['nullable', 'string', 'max:16000'],
            'subjektif' => ['nullable', 'string', 'max:16000'],
            'objektif' => ['nullable', 'string', 'max:16000'],
            'asesmen' => ['nullable', 'string', 'max:16000'],
            'plan' => ['nullable', 'string', 'max:16000'],
            'diagnosis_kerja' => ['nullable', 'string', 'max:255'],
            'instruksi_tindak_lanjut' => ['nullable', 'string', 'max:16000'],
            'status_tindak_lanjut' => ['nullable', 'string', Rule::in(self::enumDdl('rekam_medis', 'status_tindak_lanjut'))],
            'jadwal_kontrol' => ['nullable', 'date_format:Y-m-d'],
        ];

        $hasil = [];

        foreach (RekamMedisService::KOLOM_ISI as $kolom) {
            if (! isset($aturan[$kolom])) {
                throw new LogicException(
                    'RekamMedisService::KOLOM_ISI names ['.$kolom.'], which RekamMedisRequest::kolomIsi() has no rule '
                    .'for. Add the rule or remove the column; a field must not be writable and unvalidated at once.'
                );
            }

            $hasil[$kolom] = $aturan[$kolom];
        }

        return $hasil;
    }

    /**
     * `prohibited` for every machine-owned column, spread as a sibling array.
     *
     * @return array<string, array<int, string>>
     */
    public static function kolomMilikSistem(): array
    {
        $hasil = [];

        foreach (self::KOLOM_MILIK_SISTEM as $kolom) {
            $hasil[$kolom] = ['prohibited'];
        }

        return $hasil;
    }

    /**
     * `authorize()` is always true here, and that is not a hole.
     *
     * Authorisation is `auth:sanctum`, then `tipe:dokter`, then the OWNERSHIP rule
     * inside `RekamMedisService` - which is the only thing that can decide it, because
     * it needs the caller's `dokter` row to compare against the record's. A
     * FormRequest answering `false` would render a 403 through a path that has no
     * record id in it, and would refuse a call that is in fact the record's own
     * doctor.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Refuse a key the rules do not declare, at the TOP level of the body.
     *
     * ## Why this is here and not only in the service
     *
     * `RekamMedisService::tolakKolomAsing()` is the service's half of the defence and
     * it is not sufficient on its own: `FormRequest::validated()` returns only the
     * keys the rules declared, so a MISSPELLED key is stripped before the service ever
     * sees it. Without this hook a request carrying `keluhan_utma` would validate,
     * reach the service holding nothing, and be answered 200 having written nothing -
     * which is the exact defect todo 32 found in `KonsultasiService::tulisSoap()`.
     *
     * The check compares the request's top-level keys against the top-level PREFIXES
     * of the declared rules, so `perubahan.keluhan_utama` counts as the top-level key
     * `perubahan` and the nested set is not second-guessed here. The nested set is
     * still checked - by the service, because the request cannot express "any key
     * under `perubahan` must be one of these fourteen" without listing all fourteen
     * twice, and the service is where `KOLOM_ISI` lives.
     *
     * The error is keyed on the offending field name, so the envelope reads
     * `errors.keluhan_utma` - the client is told which key it invented.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $diketahui = [];

            foreach (array_keys($this->rules()) as $aturan) {
                $diketahui[] = str_contains($aturan, '.') ? explode('.', $aturan, 2)[0] : $aturan;
            }

            foreach (array_keys($this->all()) as $kunci) {
                if (! in_array($kunci, $diketahui, true)) {
                    $v->errors()->add(
                        (string) $kunci,
                        'Kolom ['.$kunci.'] tidak dikenal pada endpoint ini. Kolom yang tersedia: '
                        .implode(', ', array_values(array_unique($diketahui))).'.'
                    );
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\SuratKeterangan;

use App\Enums\SuratKeteranganTipe;
use App\Http\Requests\RekamMedis\RekamMedisRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/konsultasi/{id}/surat-keterangan` - issue one medical letter.
 *
 * ## The identity columns are `prohibited`, not merely absent
 *
 * The consultation id is a PATH segment, so the patient is read off the consultation
 * and the doctor off the authenticated account. `pasien_id` and `dokter_id` are
 * therefore listed as `prohibited` alongside `nomor_surat`, `qr_token`, `jumlah_hari`,
 * `file_url` and `dibuat_at`, and the reason is not tidiness: a doctor who could set
 * `pasien_id` would issue a perfectly valid letter about somebody else's patient, and a
 * doctor who could set `qr_token` would point a letter's QR at another document.
 * `prohibited` answers 422 naming the field rather than dropping it silently.
 *
 * ## The referral keys are optional, and the service decides whether they apply
 *
 * They are `nullable` here rather than `required` because whether they are MANDATORY
 * depends on `tipe`, and a conditional rule spelled out per-branch in a FormRequest is
 * a second copy of {@see \App\Services\SuratKeterangan\SuratKeteranganService}'s rule
 * that could disagree with it. The service is the single owner, it collects EVERY
 * violation before throwing, and it also REFUSES a referral key sent on a letter that
 * is not a referral - which no per-branch rule would do, because the offending request
 * is the one that omits a key rather than the one that supplies an extra.
 *
 * ## The `tipe` list comes from the DDL, generated rather than transcribed
 *
 * {@see SuratKeteranganTipe} is asserted against the parsed DDL on every test run, so
 * the four values here cannot drift from `telemedicine_test.sql:585`.
 *
 * ## `isi` is capped at 16000, and the cap is a deliberate loose bound
 *
 * `isi` is `TEXT NULL` (`:591`), whose MySQL limit is 65535 bytes, so the database
 * would accept a very large body. 16000 refuses a pasted document while leaving room
 * for a real letter; the point is to refuse a megabyte in a clinical field, not to
 * invent a business limit the schema does not have. `diagnosis_kerja` is capped at 255
 * because that IS the DDL's own width (`:605`).
 */
class BuatSuratKeteranganRequest extends RekamMedisRequest
{
    /**
     * The columns a doctor may never set, whatever the letter type.
     *
     * A superset of {@see RekamMedisRequest::KOLOM_MILIK_SISTEM} rather than a separate
     * list, because the machine-owned columns of the medical record and of the letter
     * are the same idea written on two tables - and `rekam_medis.uuid` is a real column
     * while `surat_keterangan` has NO uuid column at all, so reusing the list here is
     * free. `prohibited` on a key the table does not have costs nothing: the field
     * cannot be sent, which is the correct answer either way.
     *
     * @var list<string>
     */
    public const KOLOM_MILIK_SISTEM = [
        ...RekamMedisRequest::KOLOM_MILIK_SISTEM,
        'nomor_surat',
        'qr_token',
        'jumlah_hari',
        'file_url',
    ];

    /**
     * The `rujukan` columns a caller may never set.
     *
     * `status` is left out of the REQUEST list as well as the service's, because the
     * DDL default is `'aktif'` (`:610`) and a caller who could send `terpakai` would
     * create a referral that is already spent.
     *
     * @var list<string>
     */
    public const KOLOM_RUJUKAN_MILIK_SISTEM = [
        'surat_keterangan_id',
        'dokter_perujuk_id',
        'status',
    ];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $prohibited = [];

        foreach (self::KOLOM_MILIK_SISTEM as $kolom) {
            $prohibited[$kolom] = ['prohibited'];
        }

        foreach (self::KOLOM_RUJUKAN_MILIK_SISTEM as $kolom) {
            $prohibited[$kolom] = ['prohibited'];
        }

        return array_merge([
            'tipe' => ['required', 'string', Rule::in(SuratKeteranganTipe::nilai())],
            // `date_format` rather than `date`, for the reason
            // `SimpanRekamMedisRequest` gives: `date` accepts `now` and `2026-12-7`,
            // and these two columns are a clinical window whose `jumlah_hari` is
            // computed from them, so a loose rule would compute a period nobody wrote.
            'tanggal_mulai' => ['nullable', 'date_format:Y-m-d'],
            'tanggal_selesai' => ['nullable', 'date_format:Y-m-d'],
            'isi' => ['nullable', 'string', 'max:16000'],

            // The referral block, all optional here and all conditional in the service.
            'faskes_tujuan_id' => ['nullable', 'integer', 'min:1'],
            'diagnosis_kerja' => ['nullable', 'string', 'max:255'],
            'icd10_kode' => ['nullable', 'string', 'max:8'],
            'alasan_rujukan' => ['nullable', 'string', 'max:16000'],
            'berlaku_sampai' => ['nullable', 'date_format:Y-m-d'],
            // `VARCHAR(30)` (:609), the DDL's own width for a BPJS V-Claim number.
            'nomor_sep' => ['nullable', 'string', 'max:30'],
        ], $prohibited);
    }

    /**
     * The three messages the tests pin, and the reason they are here at all.
     *
     * `tipe.in` names the DDL values because a doctor who types a type that does not
     * exist should be told which ones do. `pasien_id` and `dokter_id` are `prohibited`
     * (see the class docblock) and the default "The pasien_id field is prohibited."
     * is already right - but it is pinned here so a future Laravel wording change
     * cannot silently alter a security refusal.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipe.in' => 'Tipe surat harus salah satu dari: '.implode(', ', SuratKeteranganTipe::nilai()).'.',
            'pasien_id.prohibited' => 'The pasien_id field is prohibited.',
            'dokter_id.prohibited' => 'The dokter_id field is prohibited.',
        ];
    }
}

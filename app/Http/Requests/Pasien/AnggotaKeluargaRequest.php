<?php

declare(strict_types=1);

namespace App\Http\Requests\Pasien;

use Illuminate\Validation\Rule;

/**
 * The field set of `pasien_anggota_keluarga`, shared by the create and update requests.
 *
 * ## Two requests, one field set, because a mismatch is a bug nobody would notice
 *
 * `POST /api/v1/pasien/anggota-keluarga` and `PUT /api/v1/pasien/anggota-keluarga/{id}`
 * validate the same seven columns with the same widths and the same ENUM list, and only
 * the "must every one be present" part differs. Deriving the create request from this one
 * and overriding {@see isCreate()} is what stops the two from drifting - a family member
 * that may be created with a 16-digit NIK would silently accept a 9-digit one on update
 * otherwise, and the two would disagree about the same column.
 *
 * ## `pasien_id` is not here and never will be
 *
 * It is written from the caller's own `pasien` row and read from nowhere. It is the
 * tenant key: a client that could set it could attach a family member to another
 * patient's record, which is precisely the cross-patient write the plan forbids. Its
 * absence from the rules **is** the control.
 *
 * ## `nik` is validated as exactly sixteen digits, and is masked on read
 *
 * `pasien_anggota_keluarga.nik` is `CHAR(16) NULL` (`:263`) - the same national
 * identifier as `pasien.nik` (`:222`). A bare `max:16` would accept any 16 characters
 * including 16 letters, which MySQL stores happily into a `CHAR(16)` and which no
 * Indonesian NIK can be, so `digits:16` is used. There is deliberately **no** `unique`
 * rule: the column carries no unique index in the DDL, and a rule the schema does not
 * have would refuse a row the database would have accepted.
 */
abstract class AnggotaKeluargaRequest extends PasienRequest
{
    /**
     * `pasien_anggota_keluarga.jenis_kelamin ENUM('L','P')` at
     * `telemedicine_test.sql:266`.
     *
     * The same two values `pasien.jenis_kelamin` uses (`:225`), listed here rather than
     * imported from `RegisterRequest` so each table's list is asserted against **its own**
     * DDL line by the test suite. That is what catches a transcription which happens to
     * be valid for one table and not the other.
     *
     * @var list<string>
     */
    public const JENIS_KELAMIN = ['L', 'P'];

    /**
     * The DDL's own column order, used to write the row in a stable sequence.
     *
     * `telemedicine_test.sql:262`-`:269`. Writing in DDL order rather than
     * request order costs nothing and makes a generated INSERT diffable against the
     * reference file.
     *
     * @var list<string>
     */
    private const COLUMN_ORDER = [
        'hubungan_id',
        'nik',
        'nama_lengkap',
        'jenis_kelamin',
        'tanggal_lahir',
        'no_telepon',
        'catatan_alergi',
    ];

    /**
     * Is this the create request, where every field must be present?
     *
     * `true` for `POST`: a row cannot be created with a `NOT NULL` column missing, and
     * `telemedicine_test.sql:264`-`:267` declares three of them.
     *
     * `false` for `PUT`, which is implemented as a partial update - only the keys present
     * in the body are written. The plan specifies `PUT` and says nothing about
     * partiality. Full replacement was rejected because a mobile client editing one field
     * of a family member would have to re-send the birth date and NIK to do it, and a
     * client that got one of them wrong would blank a real one. The response is the whole
     * row after the write either way, so a client never has to reason about which fields
     * stuck.
     */
    abstract protected function isCreate(): bool;

    /**
     * The validated keys to write onto `pasien_anggota_keluarga`, in the DDL's order.
     *
     * @return list<string>
     */
    public function anggotaKeys(): array
    {
        $present = array_keys($this->validated());

        return array_values(array_filter(
            self::COLUMN_ORDER,
            static fn (string $key): bool => in_array($key, $present, true),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isCreate() ? 'required' : 'sometimes';

        return [
            // pasien_anggota_keluarga.hubungan_id TINYINT UNSIGNED NOT NULL, FK to
            // master_hubungan_keluarga (:262, :271). The real FK would make a bad id a
            // MySQL 1452 - a 500 - so `exists` turns it into a 422 naming the field. The
            // seven seeded labels come from the reference SQL at :1226-1231.
            'hubungan_id' => [$presence, 'integer', 'exists:master_hubungan_keluarga,id'],
            // pasien_anggota_keluarga.nama_lengkap VARCHAR(150) NOT NULL (:264)
            'nama_lengkap' => [$presence, 'string', 'min:3', 'max:150'],
            // pasien_anggota_keluarga.jenis_kelamin ENUM('L','P') NOT NULL (:266)
            'jenis_kelamin' => [$presence, 'string', Rule::in(self::JENIS_KELAMIN)],
            // pasien_anggota_keluarga.tanggal_lahir DATE NOT NULL (:267)
            'tanggal_lahir' => [
                $presence,
                'string',
                'date_format:Y-m-d',
                'after_or_equal:1900-01-01',
                'before_or_equal:today',
            ],
            // pasien_anggota_keluarga.nik CHAR(16) NULL (:263)
            'nik' => ['nullable', 'string', 'digits:16'],
            // pasien_anggota_keluarga.no_telepon VARCHAR(20) NULL (:268)
            'no_telepon' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{8,20}$/'],
            // pasien_anggota_keluarga.catatan_alergi TEXT NULL (:269)
            'catatan_alergi' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'hubungan_id' => 'hubungan keluarga',
            'nik' => 'nik',
            'nama_lengkap' => 'nama lengkap',
            'jenis_kelamin' => 'jenis kelamin',
            'tanggal_lahir' => 'tanggal lahir',
            'no_telepon' => 'nomor telepon',
            'catatan_alergi' => 'catatan alergi',
        ];
    }
}

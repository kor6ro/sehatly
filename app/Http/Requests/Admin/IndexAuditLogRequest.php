<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\RentangHari;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `GET /admin/audit-log` - the audit trail's structured filters.
 *
 * ## The `aksi` vocabulary is the DDL's EIGHT values, not the writer's five
 *
 * `audit_log.aksi` is
 * `ENUM('create','read','update','delete','login','logout','download','export')`
 * (telemedicine_test.sql:1121). {@see AuditLogWriter::AKSI}
 * lists only the five values the OBSERVER writes, because it is a statement
 * about that one producer; the audit READ surface must be able to filter on
 * every value the column can hold, including `read`, `download` and `export`,
 * which other writers may add. The eight are spelled here in DDL order and a
 * test re-parses the DDL and asserts the list, following the project's rule that
 * a validated ENUM list is DDL-derived rather than transcribed.
 *
 * ## `aktor_user_id` is an integer and NOT `exists:users,id`
 *
 * `audit_log.user_id` is a bare column with no foreign key, deliberately, so the
 * trail survives the deletion of the user it names (migration 73's docblock).
 * `exists:users,id` would 422 a filter for a user who has since been deleted -
 * the very query an incident responder is most likely to run. A numeric filter
 * that matches no row answers an empty page, which is the truth.
 *
 * ## The date range is WIB and has NO maximum span
 *
 * The reports cap a range to keep aggregates bounded; the audit trail may
 * legitimately be read over a long period (NIST SP 800-92 wants a retention
 * window, and "what happened between January and June" is one investigation).
 * `dari`/`sampai` are converted to UTC instants by the controller through
 * {@see RentangHari}, because `dibuat_at` is an instant;
 * either bound may be omitted.
 */
class IndexAuditLogRequest extends FormRequest
{
    /**
     * Every `audit_log.aksi` value, in the DDL's order (`:1121`).
     *
     * @var list<string>
     */
    public const AKSI = ['create', 'read', 'update', 'delete', 'login', 'logout', 'download', 'export'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'aksi' => ['nullable', 'string', Rule::in(self::AKSI)],
            'tabel_target' => ['nullable', 'string', 'max:64'],
            'record_id' => ['nullable', 'string', 'max:64'],
            'aktor_user_id' => ['nullable', 'integer', 'min:1'],
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $dari = $this->input('dari');
                $sampai = $this->input('sampai');

                if (! is_string($dari) || ! is_string($sampai)) {
                    return;
                }

                if ($validator->errors()->has('dari') || $validator->errors()->has('sampai')) {
                    return;
                }

                if ($sampai < $dari) {
                    $validator->errors()->add('sampai', 'Tanggal sampai tidak boleh lebih awal dari tanggal mulai.');
                }
            },
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\PersetujuanPdpJenis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /admin/persetujuan-pdp` - the PDP ledger's read filters.
 *
 * `jenis` draws the DDL's five value ENUM through
 * {@see PersetujuanPdpJenis::nilai()}, which the PDP suite asserts is the
 * parsed DDL list in order; a value outside it is a 422 on `jenis` rather than a
 * silently empty ledger.
 *
 * `user_id` is an integer and NOT `exists:users,id`: `persetujuan_pdp.user_id`
 * cascades from `users`, so a row's subject always existed, but a filter for a
 * soft-deleted account must still work - the account row is still there and its
 * consent history is exactly what a compliance read is for. A soft-deleted
 * account is invisible to an `exists` rule, so the rule would make history
 * unfilterable.
 *
 * `disetujui` is a three-state filter in the request and a two-state filter
 * here: absent means "every decision", `true`/`false` mean the recorded answer.
 * There is deliberately no `versi_dokumen` filter: versions are a small server-
 * owned vocabulary and a client filtering by one would be re-implementing the
 * version authority `GET /pdp/dokumen` publishes.
 */
class IndexPersetujuanPdpRequest extends FormRequest
{
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
            'user_id' => ['nullable', 'integer', 'min:1'],
            'jenis' => ['nullable', 'string', Rule::in(PersetujuanPdpJenis::nilai())],
            'disetujui' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/admin/pengguna/{user}/tolak` - reject a pending registration.
 *
 * `alasan` is mandatory: the applicant must be told why they were refused.
 */
class TolakPenggunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['alasan' => 'alasan penolakan'];
    }
}

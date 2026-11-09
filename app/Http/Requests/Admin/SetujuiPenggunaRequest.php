<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/admin/pengguna/{user}/setujui` - approve a pending registration.
 *
 * No body fields required; the route carries the party gate and
 * `permission:pengguna.kelola`.
 */
class SetujuiPenggunaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}

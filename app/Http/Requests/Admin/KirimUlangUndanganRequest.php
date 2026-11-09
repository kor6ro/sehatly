<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/admin/undangan/{id}/kirim-ulang
 *
 * No body parameters — the invitation id is a path parameter and the only
 * write is a token rotation on the existing row. An empty FormRequest is
 * required by the project's Definition of Done (every POST/PUT/PATCH must
 * validate through a FormRequest injected into the controller method).
 */
class KirimUlangUndanganRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminPenggunaService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/admin/pengguna/{id}/status` - activate or deactivate an account.
 *
 * ## Two values, not four
 *
 * The ENUM holds `pending_verifikasi` and `ditangguhkan` too, and neither is a
 * value this endpoint may invent: `pending_verifikasi` is the registration
 * flow's state machine (only `otp/verify` advances it to `aktif`), and no
 * operator decision has been defined for `ditangguhkan` yet. The vocabulary is
 * therefore the two poles the service documents
 * ({@see AdminPenggunaService::STATUS_UBAH}), and a caller asking for either
 * other state gets a 422 on `status` rather than a row in a state nothing
 * validates.
 *
 * The SELF-deactivation refusal and the superadmin-for-admin-accounts rule are
 * in the CONTROLLER: both depend on the route's `{id}` and on who is calling,
 * neither of which is this request's to answer.
 *
 * `authorize()` is always true: the route carries the party gate and
 * `permission:pengguna.kelola`.
 */
class UbahStatusPenggunaRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself - see the class docblock.
     */
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
            'status' => ['required', 'string', Rule::in(AdminPenggunaService::STATUS_UBAH)],
        ];
    }

    /**
     * Field labels, so a 422 the client renders does not have to ship a second
     * copy of this project's column names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'status' => 'status akun',
        ];
    }
}

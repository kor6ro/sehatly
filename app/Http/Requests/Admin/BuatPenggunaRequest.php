<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Admin\AdminPenggunaService;
use App\Support\Telepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/admin/pengguna` - creating a staff account from the admin UI.
 *
 * ## Four fields, because the account has exactly four decisions
 *
 * `nama_lengkap`, `no_telepon`, `password` and `tipe` mirror `RegisterRequest`'s
 * identity triple plus the one field registration deliberately does NOT accept
 * (`tipe` is that request's "most dangerous field", literal `'pasien'` there).
 * Everything else has a value here rather than a question: `status` is `'aktif'`
 * by the service's own reasoning (an operator verified the holder out of band),
 * `telepon_terverifikasi` likewise, `uuid` comes from the model's `HasUuid`,
 * `bahasa` defaults to `'id'`, and the consents are not this flow's business -
 * a staff account is not a patient registering under UU PDP's registration
 * ledger.
 *
 * ## The phone folds BEFORE the rules, exactly as registration does
 *
 * `prepareForValidation()` runs `Telepon::normalisasi()` over the input so
 * `08**`, `+628**` and `628**` are one value for the `unique` rule and one value
 * for the row the write persists - the same fold `AuthRequest` and
 * `UbahTeleponPasienRequest` use. Without it a number that differs only in
 * country-code spelling could pass validation as "new" and hit MySQL's 1062 as a
 * 500; the controller additionally catches that race and answers the SAME 422.
 *
 * ## `password`, not `kata_sandi`
 *
 * The plaintext field is `password` everywhere the API accepts one
 * (`RegisterRequest`, `LoginRequest`), hashed once into
 * `users.kata_sandi_hash` by the controller. `confirmed` is absent for the same
 * reason it is absent at registration: a JSON client sending the same value
 * twice is ceremony, not safety.
 *
 * ## `tipe` may not name every type
 *
 * `Rule::in(TIPE_BUAT)` refuses `superadmin` (seed-only), `dokter` (the
 * credential rows `POST /admin/dokter` owns), `pasien` (registers itself over
 * OTP) and `perawat` (no role to grant) with a 422 on `tipe`. Creating an
 * `admin` is additionally gated in the CONTROLLER on a `superadmin` actor -
 * a cross-field rule that depends on who is calling cannot live in a rule.
 *
 * `authorize()` is always true: the route carries the party gate and
 * `permission:pengguna.kelola`.
 */
class BuatPenggunaRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself - see the class docblock.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fold `+62**` / `62**` into the canonical local `08**` before any rule runs.
     */
    protected function prepareForValidation(): void
    {
        $nomor = $this->input('no_telepon');

        if (is_string($nomor)) {
            $this->merge(['no_telepon' => Telepon::normalisasi($nomor)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // users.nama_lengkap VARCHAR(150) NOT NULL (:135) - the same triple
            // RegisterRequest uses against the same column.
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:150'],
            // users.no_telepon VARCHAR(20) NOT NULL UNIQUE (:137). The regex is
            // the DDL's width with an optional international `+` (folded away in
            // practice) and 8 digits as the floor; `unique` is the fast path to
            // a good 422 and the controller closes the race it cannot.
            'no_telepon' => [
                'required', 'string', 'max:20',
                'regex:/^\+?[0-9]{8,20}$/',
                'unique:users,no_telepon',
            ],
            // users.kata_sandi_hash VARCHAR(255) NOT NULL (:138). The plaintext
            // exists only for this request; the column holds a Hash::make digest.
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'tipe' => ['required', 'string', Rule::in(AdminPenggunaService::TIPE_BUAT)],
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
            'nama_lengkap' => 'nama lengkap',
            'no_telepon' => 'nomor telepon',
            'password' => 'kata sandi',
            'tipe' => 'tipe akun',
        ];
    }
}

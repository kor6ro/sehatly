<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Pasien;
use App\Support\Telepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /api/v1/admin/pasien/{id}/telepon` - the support path that corrects a
 * patient's registered phone number.
 *
 * ## One field, because the endpoint has exactly one job
 *
 * `users.no_telepon VARCHAR(20) NOT NULL UNIQUE` (`telemedicine_test.sql:137`) is
 * the account's login identifier and its OTP destination, so a correction is a
 * security-relevant identity write rather than a profile edit. The request accepts
 * the new number and nothing else: name, email, status and `tipe` are not this
 * endpoint's business, and `validated()` silently drops anything else the caller
 * sends before the controller sees it.
 *
 * ## Normalisation runs BEFORE the rules, so the unique check cannot miss
 *
 * `prepareForValidation()` folds `+62…` / `62…` through {@see Telepon}, exactly as
 * `AuthRequest` does for login and registration. That is what makes `08…` and
 * `+628…` one value for the `unique` rule below: without it, support could "change"
 * a number to the international spelling of a number another account already owns,
 * pass validation, and hit MySQL 1062 as a 500. After the fold, the regex validates
 * the canonical form the database stores and the uniqueness guard compares the same
 * form the write will persist.
 *
 * ## The unique rule ignores the account being changed, not a hard-coded id
 *
 * A support correction that re-submits the SAME number must be a no-op success, not
 * a 422 about the patient's own row. `pacientUserId()` resolves the route's
 * `{id}` - a `pasien.id` - to its `users.id` and passes it to `ignore()`, so the
 * only collision the rule refuses is a number held by a DIFFERENT account. The
 * lookup respects `Pasien`'s soft-delete scope, so a soft-deleted patient resolves
 * no target and the controller's 404 stands rather than the rule guessing.
 *
 * `authorize()` is always true: the route carries `tipe:admin,superadmin` AND
 * `permission:pasien.kelola`, and the request itself has no per-row ownership
 * question to answer (an admin may correct any patient's number; the capability is
 * the gate).
 */
class UbahTeleponPasienRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself - see the class docblock.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fold `+62…` / `62…` into the canonical local `08…` before any rule runs.
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
            // users.no_telepon VARCHAR(20) NOT NULL UNIQUE (:137). Same shape as
            // `RegisterRequest`: the column's width, an optional international `+`
            // (already folded away by prepareForValidation in practice), and 8
            // digits as the floor. The unique rule names the account being changed
            // so a patient's own number is not a collision with itself.
            'no_telepon' => [
                'required', 'string', 'max:20',
                'regex:/^\+?[0-9]{8,20}$/',
                // `ignore(null)` is the rule's documented "ignore nobody" form, so a
                // missing patient still validates against the whole table.
                Rule::unique('users', 'no_telepon')->ignore($this->penggunaYangDiubah()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'no_telepon' => 'nomor telepon',
        ];
    }

    /**
     * The `users.id` behind the route's `pasien.id`, or `null` when the route does
     * not name a live patient.
     *
     * `null` is correct rather than an error: the unique rule then guards the whole
     * table, and the controller answers 404 for the missing patient, which is the
     * status the contract publishes for that case.
     *
     * The `LogicException` is the framework's "Route is not bound." signal, raised
     * when `sehatly:openapi` reads this class's `rules()` against a route object
     * whose parameters were never bound. At document-generation time there is no
     * specific row being corrected, so the `ignore(null)` form is the honest one -
     * and without the catch the generator refuses to publish, which is how this was
     * found.
     */
    private function penggunaYangDiubah(): ?int
    {
        try {
            $id = $this->route('id');
        } catch (\LogicException) {
            return null;
        }

        if (! is_numeric($id)) {
            return null;
        }

        $userId = Pasien::query()->whereKey((int) $id)->value('user_id');

        return $userId === null ? null : (int) $userId;
    }
}

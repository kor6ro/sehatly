<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Support\Telepon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Base for the Module 1 auth requests that identify an account by phone number or by
 * email address.
 *
 * ## Why the "one of the two" rule lives here
 *
 * `POST /auth/login` and `POST /auth/otp/verify` both accept **either**
 * `users.no_telepon` **or** `users.email`, because `telemedicine_test.sql:136-137`
 * makes one of the two mandatory and the other optional:
 *
 * ```
 * email      VARCHAR(255) NULL UNIQUE,     -- :136
 * no_telepon VARCHAR(20)  NOT NULL UNIQUE, -- :137
 * ```
 *
 * A patient who registered with a phone number has `email IS NULL`, so a rule that
 * demanded `email` could not log them in; and MySQL's "many NULLs in a UNIQUE index"
 * semantics exist precisely so a second patient may register with no email at all.
 * Requiring either one, and refusing both, is therefore the only rule the schema
 * supports.
 *
 * The alternative -- a single `identifier` field matched against both columns -- was
 * rejected because `no_telepon` is `VARCHAR(20)` and `email` is `VARCHAR(255)`: one
 * untyped field would have to be matched with an `OR` that cannot use either index,
 * and a phone number containing an `@` would be ambiguous. Two named fields keep both
 * lookups indexed and make the 422 name the field the client actually sent wrong.
 */
abstract class AuthRequest extends FormRequest
{
    /**
     * Nobody is authorised by the request itself.
     *
     * These routes are reached either anonymously (register, login, otp, refresh) or
     * behind `auth:sanctum` (logout, devices), and the *authorisation* decision is
     * made by `permission:` / `tipe:` and by the controller's own ownership scoping.
     * Returning `true` here is the framework's documented "the request is the
     * authorisation" answer; returning false would produce a 403 for a request that
     * has not yet been authenticated, which is the wrong status.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fold `+62…` / `62…` into the canonical local `08…` before any rule runs.
     *
     * This is what closes F01's P0 defect: `0812…` and `+62812…` used to be two
     * accounts for one phone, because both the `unique:` rule and the lookup in
     * `AuthController::resolveUser()` matched the submitted string exactly. Running
     * the normaliser in `prepareForValidation()` means the rules, the controller and
     * the `unique` check all see one canonical value, and `validated()` returns it,
     * so the write and the lookup cannot disagree. {@see Telepon} owns the rule and
     * records why the local form is canonical.
     *
     * `email` is deliberately untouched: it is case-folded by neither MySQL's
     * `utf8mb4_unicode_ci` unique index nor this application, and changing address
     * case here would be a second, unrelated decision.
     */
    protected function prepareForValidation(): void
    {
        $nomor = $this->input('no_telepon');

        if (is_string($nomor)) {
            $this->merge(['no_telepon' => Telepon::normalisasi($nomor)]);
        }
    }

    /**
     * Rules for the two identifier fields, shared so they cannot drift.
     *
     * Both are `nullable` on their own -- an absent field is not a validation failure
     * when the other one is present -- and the "at least one" requirement is added by
     * {@see requireAtLeastOneIdentifier()}. `email` is validated as an address even
     * when it is the field that was not sent, so a caller cannot smuggle an arbitrary
     * string into the email lookup.
     *
     * `no_telepon` reaches these rules already canonical: {@see prepareForValidation()}
     * folded `+62…`/`62…` into `08…` first, so the regex below is checked against the
     * stored form and the `unique` rule cannot miss a duplicate that differs only by
     * country-code spelling.
     *
     * @return array<string, list<string>>
     */
    protected function identifierRules(): array
    {
        return [
            'no_telepon' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Add the "at least one identifier" error to both fields.
     *
     * The message is attached to *both* keys so a client that sends neither can see
     * which two fields it was expected to send, whichever it was looking at.
     */
    protected function requireAtLeastOneIdentifier(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('no_telepon') || $this->filled('email')) {
                return;
            }

            $message = 'Isi no_telepon atau email.';

            $validator->errors()->add('no_telepon', $message);
            $validator->errors()->add('email', $message);
        });
    }
}

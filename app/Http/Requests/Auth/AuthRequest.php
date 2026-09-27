<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

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
     * Rules for the two identifier fields, shared so they cannot drift.
     *
     * Both are `nullable` on their own -- an absent field is not a validation failure
     * when the other one is present -- and the "at least one" requirement is added by
     * {@see requireAtLeastOneIdentifier()}. `email` is validated as an address even
     * when it is the field that was not sent, so a caller cannot smuggle an arbitrary
     * string into the email lookup.
     *
     * `no_telepon` is matched exactly, never normalised. Storing and looking up the
     * caller's own spelling is the predictable behaviour; a normaliser that rewrote
     * `+62` to `0` would silently make an account unreachable for anyone who typed the
     * form the other way round.
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

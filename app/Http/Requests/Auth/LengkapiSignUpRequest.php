<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/auth/sign-up/lengkapi` - the screen that finishes an account
 * whose phone number has already been proved.
 *
 * ## It is a sign-up, not a profile edit, and the consents are what make it one
 *
 * `POST /auth/login` mints a SHELL for a number with no account: a `users` row with an
 * empty name, no `pasien` row, no role grant and no ledger. This request turns that
 * shell into a patient, and it carries the two mandatory UU PDP consents for the same
 * reason {@see RegisterRequest} does - they are the difference between an account that
 * may process personal data and one that may not. They are taken here, by a checkbox on
 * the form, rather than at `login`, because a box nobody has ticked is not consent.
 *
 * ## What it deliberately does NOT ask for
 *
 * - **`no_telepon`** - already proved over the OTP round trip and shown locked on the
 *   form. Accepting it would let a verified session re-point its own account at a number
 *   nobody has demonstrated control of, which is a different operation needing a
 *   different guard.
 * - **`password`** - this door admits on the code alone.
 * - **`tipe`, `status`, `telepon_terverifikasi`** - state the controller owns. They are
 *   absent from `rules()` entirely, so `validated()` drops them before any write.
 *
 * Every field rule below is {@see RegisterRequest}'s own, minus those three, so the two
 * screens cannot drift apart on what a patient's profile is made of.
 */
class LengkapiSignUpRequest extends AuthRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The caller's own row has to be excluded from the `unique` check. The shell
        // always arrives with `email IS NULL`, so the exclusion never fires on the first
        // (the only) completion - but without it, a repeat call would be refused for
        // colliding with the address this very account set a moment ago, and the 422
        // would name the wrong problem.
        $pemilik = $this->user();
        $unikEmail = $pemilik === null
            ? 'unique:users,email'
            : 'unique:users,email,'.$pemilik->getKey();

        return [
            // users.nama_lengkap VARCHAR(150) NOT NULL (:135). The shell fills it with
            // '' to satisfy the column without inventing a name; this is where the real
            // one lands, and `min:3` is what stops `''` from being a legal answer here.
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:150'],
            // users.email VARCHAR(255) NULL UNIQUE (:136). Optional by design - halodoc
            // marks it `(Opsional)` and a phone-only patient has no address to enter.
            'email' => ['nullable', 'string', 'email', 'max:255', $unikEmail],
            // pasien.jenis_kelamin ENUM('L','P') NOT NULL (:77). Closes the reason the
            // shell did not write this row: it is a fact only the owner can supply, and
            // the only alternative was fabricating one in a medical record.
            'jenis_kelamin' => ['required', 'string', Rule::in(RegisterRequest::JENIS_KELAMIN)],
            // pasien.tanggal_lahir DATE NOT NULL (:78). Same reasoning - age drives
            // dosing, so a placeholder birth date is a safety defect, not a blank field.
            'tanggal_lahir' => [
                'required', 'string', 'date_format:Y-m-d',
                'after_or_equal:1900-01-01', 'before_or_equal:today',
            ],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            // pasien.alamat_lengkap TEXT NOT NULL (:90).
            'alamat_lengkap' => ['required', 'string', 'min:5'],
            // Both are the owner's mandatory consents. `accepted` is an affirmative act;
            // a client sending `false` gets a 422 naming the field, rather than an
            // account that proceeds with a "declined" ledger row nobody asked it to write.
            'persetujuan_syarat_ketentuan' => ['required', 'accepted'],
            'persetujuan_kebijakan_privasi' => ['required', 'accepted'],
        ];
    }

    /**
     * Field labels, so the 422 a client renders does not have to ship a second copy of
     * this project's column names.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nama_lengkap' => 'nama lengkap',
            'email' => 'email',
            'jenis_kelamin' => 'jenis kelamin',
            'tanggal_lahir' => 'tanggal lahir',
            'tempat_lahir' => 'tempat lahir',
            'alamat_lengkap' => 'alamat lengkap',
            'persetujuan_syarat_ketentuan' => 'persetujuan syarat dan ketentuan',
            'persetujuan_kebijakan_privasi' => 'persetujuan kebijakan privasi',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/v1/auth/register`.
 *
 * ## Why the patient demographics are collected here and not at `PUT /pasien/profil`
 *
 * The plan considered deferring the `pasien` row to the profile endpoint and settled on
 * collecting the fields at registration. The reason is in the DDL, and it is not a
 * preference: `telemedicine_test.sql:225`, `:226` and `:234` are
 *
 * ```
 * jenis_kelamin  ENUM('L','P') NOT NULL,   -- :225
 * tanggal_lahir  DATE NOT NULL,           -- :226
 * alamat_lengkap TEXT NOT NULL,           -- :234
 * ```
 *
 * None of the three has a default, so under MySQL 8's default
 * `STRICT_TRANS_TABLES` an insert that omits any of them fails with error 1364,
 * "Field doesn't have a default value". A `pasien` row therefore cannot be created
 * empty, which means the row cannot be deferred past the register call either. A
 * patient with no `pasien` row cannot book, and every downstream query joins through
 * `pasien`, so the alternative is a half-created account rather than a smaller form.
 */
class RegisterRequest extends AuthRequest
{
    /**
     * `pasien.jenis_kelamin ENUM('L','P')` at `telemedicine_test.sql:225`.
     *
     * @var list<string>
     */
    public const JENIS_KELAMIN = ['L', 'P'];

    /**
     * `users.bahasa ENUM('id','en')` at `telemedicine_test.sql:142`.
     *
     * @var list<string>
     */
    public const BAHASA = ['id', 'en'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // users.nama_lengkap VARCHAR(150) NOT NULL (:135)
            'nama_lengkap' => ['required', 'string', 'min:3', 'max:150'],
            // users.no_telepon VARCHAR(20) NOT NULL UNIQUE (:137). The regex is the
            // DDL's width with an optional international `+`, and 8 digits as the floor
            // for Indonesian landline and mobile numbers alike. `unique` is the fast
            // path that produces a good 422; the controller additionally catches the
            // QueryException from the insert, because a uniqueness rule is a check and
            // two simultaneous registrations of one number are a race it cannot close.
            'no_telepon' => [
                'required', 'string', 'max:20',
                'regex:/^\+?[0-9]{8,20}$/',
                'unique:users,no_telepon',
            ],
            // users.email VARCHAR(255) NULL UNIQUE (:136). Nullable, and MySQL permits
            // many NULLs in a UNIQUE index, which is what lets a patient register with
            // a phone number only.
            'email' => ['nullable', 'string', 'email', 'max:255', 'unique:users,email'],
            // users.kata_sandi_hash VARCHAR(255) NOT NULL (:138). The column holds a
            // password_hash() digest; the plaintext exists only for this request and is
            // hashed once, in the controller. `confirmed` is deliberately absent: it is
            // a server-rendered-form convention and a JSON client sends the same value
            // twice for no benefit.
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'jenis_kelamin' => ['required', 'string', Rule::in(self::JENIS_KELAMIN)],
            'tanggal_lahir' => [
                'required', 'string', 'date_format:Y-m-d',
                'after_or_equal:1900-01-01', 'before_or_equal:today',
            ],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'alamat_lengkap' => ['required', 'string', 'min:5'],
            'bahasa' => ['nullable', 'string', Rule::in(self::BAHASA)],
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
            'no_telepon' => 'nomor telepon',
            'jenis_kelamin' => 'jenis kelamin',
            'tanggal_lahir' => 'tanggal lahir',
            'tempat_lahir' => 'tempat lahir',
            'alamat_lengkap' => 'alamat lengkap',
            'bahasa' => 'bahasa',
        ];
    }
}

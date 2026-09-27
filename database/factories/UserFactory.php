<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Factory for the DDL `users` table.
 *
 * This factory was the single root cause of 21 pre-existing Feature test errors
 * (independently reported by the todo 4 and todo 19 executors): it was still the
 * stock Laravel/Fortify/Inertia scaffold and wrote `name`, `email_verified_at`,
 * `password`, `remember_token` and the three `two_factor_*` columns, none of
 * which exist in the contract. Every one of those 21 failures surfaced as
 * `Unknown column 'name'`.
 *
 * The contract names these columns differently on purpose, so the factory
 * follows the DDL rather than Laravel's defaults:
 *
 * | Laravel scaffold     | `telemedicine_test.sql` `users`      |
 * |----------------------|---------------------------------------|
 * | `name`               | `nama_lengkap` (line 135)            |
 * | `password`           | `kata_sandi_hash` (line 137)         |
 * | `email_verified_at`  | `email_terverifikasi` (line 146)    |
 * | `remember_token`     | *does not exist*                     |
 * | `two_factor_*`       | *do not exist*                       |
 * | *absent*             | `uuid` (134), `no_telepon` (136)     |
 *
 * `dibuat_at` / `diubah_at` / `dihapus_at` are filled by Eloquent itself
 * (`HasIndonesianTimestamps` plus `SoftDeletes`), so this factory must not set
 * them.
 *
 * @see telemedicine_test.sql:132-149 for the authoritative definition.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password hash every generated user shares, so a test may authenticate
     * as any factory user using the plaintext `password`.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * `tipe` and `status` are deliberately left to their DDL defaults
     * (`'pasien'` and `'pending_verifikasi'`) rather than restated here, so the
     * factory cannot drift from the schema.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'nama_lengkap' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'no_telepon' => fake()->unique()->numerify('08##########'),
            'kata_sandi_hash' => static::$password ??= Hash::make('password'),
        ];
    }

    /**
     * Indicate that the model's email address is unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_terverifikasi' => false,
        ]);
    }

    /**
     * Indicate that the model's email address is verified.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_terverifikasi' => true,
        ]);
    }

    /**
     * Indicate that the account has a verified phone number.
     */
    public function teleponTerverifikasi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'telepon_terverifikasi' => true,
        ]);
    }
}

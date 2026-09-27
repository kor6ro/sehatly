# UserFactory repair (orchestrator, between todo 4/19 and todo 20)

## Why this file existed in my queue
The todo 4 and todo 19 executors ran CONCURRENTLY and independently reported the
same thing: the full suite was already red before either of them started, and the
single root cause was `database/factories/UserFactory.php`. Neither was allowed
to touch it (each was scoped to its own files), so it survived both. Left alone it
would have made todo 20's "happy + failure feature tests green" criterion
unsatisfiable for a reason that has nothing to do with auth.

## The defect
The factory was the stock Laravel/Fortify/Inertia scaffold. It wrote `name`,
`email_verified_at`, `password`, `remember_token` and three `two_factor_*`
columns. **None of those exist in the contract.** Every one of the 21 pre-existing
Feature errors surfaced as `Unknown column 'name'`.

| Laravel scaffold | DDL `users` (telemedicine_test.sql:132-149) |
|---|---|
| `name` | `nama_lengkap` (135) |
| `password` | `kata_sandi_hash` (137) |
| `email_verified_at` | `email_terverifikasi` (146) |
| `remember_token` | *does not exist* |
| `two_factor_*` | *do not exist* |
| *absent* | `uuid` (134), `no_telepon` (136) |

## The repair
Rewrote `definition()` to the DDL: `uuid` (Str::uuid), `nama_lengkap`,
`email`, `no_telepon` (unique, `08##########`), `kata_sandi_hash`.
`tipe` and `status` are deliberately NOT set, so they keep their DDL defaults
(`pasien` / `pending_verifikasi`) and the factory cannot drift from the
schema. `dibuat_at`/`diubah_at`/`dihapus_at` are left to Eloquent.

`unverified()` now sets `email_terverifikasi => false` (was
`email_verified_at => null`). Added `verified()` and `teleponTerverifikasi()`.

**`withTwoFactor()` was DELETED, not stubbed.** The DDL has no 2FA columns, so
the method could only ever throw. Deleting it means any caller fails loudly with
"undefined method" instead of a confusing SQL error. Two-factor authentication is
not a feature of this contract; `config/fortify.php` must stop advertising it.

## Measured delta

| | before | after |
|---|---|---|
| `php artisan test` | 207 tests, 186 passed, **21 failing** (1 failure + 20 errors) | 207 tests, **196 passed**, **11 failing** |
| root cause of the 21 | `Unknown column 'name'` via this factory | resolved |

All 10 recovered tests are `Auth\*` and `DashboardTest`.

## The 11 that remain are NOT this factory

They are the Fortify/Inertia **web scaffold**, and they reference columns and
helpers that the contract does not have:

- `Settings\ProfileController.php:39` writes `email_verified_at` directly
  (5 of the 11).
- Fortify's password confirmation / password reset authenticate against
  `password`, which is now `kata_sandi_hash` (4 of the 11).
- `EmailVerificationTest` waits for an `Illuminate\Auth\Events\Verified`
  event that this contract does not define; verification is the
  `email_terverifikasi` column plus OTP (2 of the 11).

**These belong to todo 30**, whose stated scope is "strip Inertia from the Laravel
root". Fixing them here would mean either editing `config/fortify.php` and the
`Settings\*` controllers - files two other todos own - or deleting tests, which
is forbidden. So they are recorded, not worked around.

## Hygiene
- `telemedicine_test.sql` untouched: SHA-256 `AEFE2247E00F09ACB02235168AC289CDFA74F762D604ADA71F68E328574B27F5`.
- `sehatly` never opened for writing.
- One file authored, one file deleted (the 2FA state method, inside the file I authored).
- No migration, model, seeder, controller, route or doc touched.
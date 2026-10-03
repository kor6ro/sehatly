<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The base class every test in this project extends.
 *
 * `tests/Pest.php` binds it with `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`,
 * so the class itself is load-bearing: it is where every test's NIK cipher key
 * comes from.
 *
 * ## Why it now declares a `setUp()`
 *
 * `pasien` stores its NIK as a `NikCipher` payload in `nik_cipher`
 * (migration `2026_10_01_000079`), so every test that writes a NIK - directly or
 * through a fixture, a seeder, or `Artisan::call('migrate:fresh', ['--seed' => true])`
 * - needs a usable `nik.key` or it raises `MissingNikCipherKeyException`.
 *
 * The key lives here, in the base class, for three reasons:
 *
 * 1. `phpunit.xml` is off limits, so there is nowhere else to set an env
 *    default that every suite inherits. `config()` in `setUp()` is the one place
 *    that reaches PHPUnit-style tests, Pest `beforeEach` hooks, and in-process
 *    `Artisan::call()` runs alike.
 * 2. `NikCipher` reads `nik.key` on every call rather than memoising it
 *    (see `config/nik.php`), so a per-test value is genuinely per-test and
 *    cannot leak into the next one.
 * 3. Each test gets its OWN key. Sharing one would let a payload written in
 *    one test decrypt in another, which would hide exactly the key-mismatch
 *    bug the key-id header exists to catch.
 *
 * A test that asserts the no-key behaviour must still null it explicitly, and
 * the ones that do (`NikCipherTest`) go through `t50DenganKunci(['nik.key' => null])`
 * or an explicit `setUp()`/`beforeEach()` that runs after this one.
 *
 * ## What was removed from it in todo 30, and why
 *
 * This class used to carry a `skipUnlessFortifyHas(string $feature)` helper whose
 * entire body was:
 *
 *     if (! Laravel\Fortify\Features::enabled($feature)) {
 *         $this->markTestSkipped(...);
 *     }
 *
 * Three reasons it is gone rather than left:
 *
 * 1. **It was dead.** Nothing in `app/`, `tests/`, `routes/` or `database/`
 *    called it, once and at the time of writing. A helper with no caller is not
 *    coverage.
 * 2. **It could not have worked.** The package it consulted was uninstalled in
 *    todo 30, so `Laravel\Fortify\Features` no longer exists. PHP resolves a
 *    `use` import lazily, so the stale import did not fatal and the suite stayed
 *    green - which is exactly why it was worth finding. The first test to call
 *    the helper would have died with a class-not-found error rather than a skip.
 * 3. **It was the only `markTestSkipped` in the repository.** The plan's final
 *    gate requires zero skipped tests, and a helper whose whole purpose is to
 *    skip a test is a mechanism for hiding a red, not for avoiding one.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fresh 32-byte key per test, so a payload written by one test cannot
        // be decrypted by another. `nik.previous_keys` is reset alongside it so
        // a key listed by an earlier test cannot stay in the ring.
        config([
            'nik.key' => base64_encode(random_bytes(32)),
            'nik.previous_keys' => [],
        ]);
    }
}

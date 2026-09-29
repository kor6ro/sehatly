<?php

declare(strict_types=1);

/*
 |--------------------------------------------------------------------------
 | Contract suite bootstrap
 |--------------------------------------------------------------------------
 |
 | ## This file is NOT loaded by Pest, and that is deliberate documentation
 |
 | It was written by an interrupted executor of this todo believing
 | `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Contract')`
 | here would bind the suite. **It does not, and it never did.** The reason is in
 | the framework: `Pest\Bootstrappers\BootFiles::boot()`
 | (`vendor/pestphp/pest/src/Bootstrappers/BootFiles.php:39-68`) walks a FIXED
 | STRUCTURE of five names -- `Expectations`, `Expectations.php`, `Helpers`,
 | `Helpers.php`, `Pest.php` -- at the ROOT of the test directory only. It never
 | recurses into a subdirectory to look for a second `Pest.php`.
 |
 | That is not a matter of taste, and it is not a version accident. The proof is
 | that this exact file, sitting in `tests/Contract/`, left the suite unbound:
 | every test in the directory raised
 | `Call to undefined method ...::getJson(). Did you forget to use the
 | [pest()->extend()] function?` and
 * `A facade root has not been set.`, because no `Tests\TestCase` was ever
 | applied. Both were captured in `.omo/evidence/task-49-sehatly.md` before this
 | file was corrected.
 |
 | The same applies to the second claim this file used to make: that helpers live
 | in a `contract-helpers.php` auto-loaded through composer's `autoload-dev`.
 | There is no `files` entry in `composer.json`'s `autoload-dev` (it holds a
 | `psr-4` map only), and no such file existed. Both statements were false.
 |
 | ## Where the binding actually happens
 |
 | Every test file in this directory opens with its own, explicit binding:
 |
 |     uses(TestCase::class, DatabaseTransactions::class);
 |
 | That is the same thing `tests/Pest.php` does for `Feature`, stated once per
 | file instead of once per directory. It is duplication of two lines across
 | five files, and the alternative was five files that cannot boot.
 |
 | ## Why `DatabaseTransactions` and not `RefreshDatabase`
 |
 | `RefreshDatabase` runs `php artisan migrate:fresh` once per process on the
 | configured database before the first test's transaction. This project's
 | guardrails forbid `migrate:fresh`, and the shared `telemedisin_db_test` that
 | `phpunit.xml` pins is used by the 1152-test main suite. `DatabaseTransactions`
 | opens a transaction per test and rolls it back, and never migrates -- so the
 | suite reads the schema it is pointed at and writes nothing that survives.
 |
 | The suite is run against a per-executor database supplied through
 | `$env:DB_DATABASE`, never against `telemedisin_db_test`.
 |
 | ## Why the suite is not in `phpunit.xml`
 |
 | `phpunit.xml` declares exactly two testsuites, `Unit` and `Feature`. Adding a
 | third is not this todo's to do, so `php artisan test` does not run this
 | directory and the suite is invoked explicitly:
 |
 |     php artisan test tests/Contract
 |
 | `composer contract` runs it, and that is the gate. The consequence is stated
 | in `docs/contract-conformance.md` rather than hidden: a bare `php artisan test`
 | on this repository does NOT execute the conformance suite.
 |
 */

declare(ticks=1);

/*
 * Deliberately empty.
 *
 * This file exists because it was created by an earlier attempt at this todo and
 * must not be deleted from under a reader who has read its claims. Keeping it,
 * emptied of its false claims, costs nothing and stops the same mistake being
 * made twice. The authoritative binding is the `uses(...)` line at the top of
 * each test file in this directory.
 */
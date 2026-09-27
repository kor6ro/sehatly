<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The base class every test in this project extends.
 *
 * `tests/Pest.php` binds it with `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`,
 * so the class itself is load-bearing even though it declares nothing.
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
abstract class TestCase extends BaseTestCase {}

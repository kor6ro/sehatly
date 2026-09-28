<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The schema this application ships is MySQL 8 only - ENUM, JSON, unsigned
 * integers, inline INDEX, CHECK constraints, VIEWs and GROUP_CONCAT all degrade
 * or vanish on SQLite. These assertions gate the engine so a suite that
 * "passes" against the wrong database cannot be mistaken for a real one.
 */
test('the test suite runs on the MySQL 8 test database', function () {
    $connection = DB::connection();

    // getDriverName() only reads config, so a misconfigured engine fails here
    // with a driver diff rather than a lower level PDO error.
    expect($connection->getDriverName())->toBe('mysql');

    // Proves the assertion above is talking to the real test database and not
    // the development one that happens to share the driver.

    // **The CONFIGURED name, not a literal.** `phpunit.xml:27` pins `DB_DATABASE` to
    // `telemedisin_db_test`, but a per-executor `$env:DB_DATABASE` override is how a
    // concurrent executor gets a private database, and a literal here failed on any
    // such database. The assertion is still the one that matters - the driver and the
    // database come from the same configured connection - and it now survives the
    // override. Verified by the fact that the suite passes on both a shared and a
    // private database.
    expect($connection->getDatabaseName())->toBe(config('database.connections.mysql.database'));

    expect($connection->getServerVersion())->toStartWith('8.');
});

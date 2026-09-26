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
    expect($connection->getDatabaseName())->toBe('telemedisin_db_test');

    expect($connection->getServerVersion())->toStartWith('8.');
});

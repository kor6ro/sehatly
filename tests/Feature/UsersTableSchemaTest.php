<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch-B (todo 8) negative QA for the SQL `users` table
 * (`telemedicine_test.sql:132-149`).
 *
 * `tests/Pest.php` binds `RefreshDatabase` to every Pest test in this
 * directory, so running this file migrates `telemedisin_db_test` to the
 * current batch-B state (then the trait rolls each insert back). That is
 * why this file lives under `tests/Feature` rather than `tests/Unit`:
 * a Unit test has no fixture and would insert into whatever `users` happens
 * to exist. **Run it as a single file**
 * (`php artisan test tests/Feature/UsersTableSchemaTest.php`) so none of
 * the pre-existing broken Feature auth tests enter scope.
 *
 * Intended end state of `telemedisin_db_test` after a run: fully migrated to
 * the batch-B schema, every table empty (each test's inserts roll back).
 */
test('two users with NULL email coexist under the nullable-UNIQUE', function () {
    // A `function` at this file's top level would be global, and a second test
    // file declaring the same name would fatal the ENTIRE run at include time
    // rather than failing one test. Keep it inside the closures that use it.
    $payload = fn (string $uuid, ?string $email, string $noTelepon): array => [
        'uuid' => $uuid,
        'nama_lengkap' => 'Pasien Uji Skema',
        'email' => $email,
        'no_telepon' => $noTelepon,
        'kata_sandi_hash' => '$2y$04$S1gn7ur3Unu54bl3H45hF0rT35t1ng0nlyxxxxxxxx',
        'tipe' => 'pasien',
        'status' => 'pending_verifikasi',
        'bahasa' => 'id',
    ];

    DB::table('users')->insert($payload('11111111-1111-4111-8111-111111111111', null, '081100000001'));
    DB::table('users')->insert($payload('22222222-2222-4222-8222-222222222222', null, '081100000002'));

    expect(DB::table('users')->count())->toBe(2);
});

test('a second insert with a duplicate no_telepon raises QueryException', function () {
    $payload = fn (string $uuid, ?string $email, string $noTelepon): array => [
        'uuid' => $uuid,
        'nama_lengkap' => 'Pasien Uji Skema',
        'email' => $email,
        'no_telepon' => $noTelepon,
        'kata_sandi_hash' => '$2y$04$S1gn7ur3Unu54bl3H45hF0rT35t1ng0nlyxxxxxxxx',
        'tipe' => 'pasien',
        'status' => 'pending_verifikasi',
        'bahasa' => 'id',
    ];

    DB::table('users')->insert($payload('33333333-3333-4333-8333-333333333333', 'pertama@example.test', '081100000003'));

    DB::table('users')->insert($payload('44444444-4444-4444-8444-444444444444', 'kedua@example.test', '081100000003'));
})->throws(QueryException::class, 'Duplicate entry');

test('users carries the SQL column list, not the scaffold one', function () {
    // getColumnListing reads the *migrated* table, so this proves the live
    // schema — not this file — matches :132-148 and drops the scaffold.
    $columns = Schema::getColumnListing('users');

    expect($columns)->toContain('kata_sandi_hash')
        ->not->toContain('name')
        ->not->toContain('password')
        ->not->toContain('email_verified_at')
        ->not->toContain('remember_token');
});

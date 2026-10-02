<?php

declare(strict_types=1);

use App\Models\AuditLog;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| The citations this design rests on, asserted against the parser
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| Every claim in the audit design about the shape of `audit_log` is a claim
| about `telemedicine_test.sql` at a specific line. This file asserts each one
| through `SqlSchemaParser`, so a line that moves, a column that is renamed, or
| a type that widens fails here instead of quietly invalidating the redaction
| reasoning built on top of it.
|
| The three citations that carry the most weight:
|
|   :1120  user_id     NULL, no foreign key  -> an audit row outlives its user
|   :1123  record_id   VARCHAR(64), no FK    -> an audit row outlives its record
|   :1129  dibuat_at   the ONLY timestamp    -> the table is append-only
|
| @see \App\Support\Schema\SqlSchemaParser
*/

test('the audit_log shape this design assumes is the one the parser reports', function () {
    $audit = audSpec()->table('audit_log');

    expect($audit)->not->toBeNull();
    expect($audit->line)->toBe(1118, 'telemedicine_test.sql:1118 declares audit_log');
    expect($audit->engine)->toBe('innodb', 'telemedicine_test.sql:1132');

    $expected = [
        'user_id' => [1120, 'bigint', true],
        'aksi' => [1121, "enum('create','read','update','delete','login','logout','download','export')", false],
        'tabel_target' => [1122, 'varchar(64)', true],
        'record_id' => [1123, 'varchar(64)', true],
        'data_lama' => [1124, 'json', true],
        'data_baru' => [1125, 'json', true],
        'ip_address' => [1126, 'varchar(45)', true],
        'user_agent' => [1127, 'varchar(255)', true],
        'endpoint' => [1128, 'varchar(200)', true],
        'dibuat_at' => [1129, 'timestamp', false],
    ];

    foreach ($expected as $name => [$line, $type, $nullable]) {
        $column = $audit->columns[$name] ?? null;

        expect($column)->not->toBeNull("audit_log.{$name} is claimed at :{$line}");
        expect($column->line)->toBe($line, "audit_log.{$name}");
        expect($column->type)->toBe($type, "audit_log.{$name}");
        expect($column->nullable)->toBe($nullable, "audit_log.{$name}");
    }
});

test('audit_log is append-only, because it has no changed-at column to write to', function () {
    $columns = audSpec()->table('audit_log')->columns;

    // telemedicine_test.sql:1129 declares dibuat_at and nothing after it.
    expect($columns)->not->toHaveKey('diubah_at');
    expect($columns)->not->toHaveKey('updated_at');
    expect($columns)->toHaveKey('dibuat_at');

    // And the Eloquent model is told so, so a mass assignment cannot invent
    // one either.
    expect((new AuditLog)->usesTimestamps())->toBeTrue();
    expect((new AuditLog)->getCreatedAtColumn())->toBe('dibuat_at');
    expect((new AuditLog)->getUpdatedAtColumn())->toBeNull();
});

test('audit_log declares no foreign key, therefore no relation can exist', function () {
    $audit = audSpec()->table('audit_log');

    // user_id (:1120) and record_id (:1123) are bare BIGINTs on purpose: a log
    // row has to survive the deletion of both the user and the record it names.
    expect($audit->foreignKeys)->toBe([]);
    expect($audit->checks)->toBe([]);

    $relations = array_values(array_filter(
        get_class_methods(AuditLog::class),
        fn (string $method): bool => in_array($method, [
            'user', 'record', 'userAccount', 'targetRecord', 'actor', 'author',
        ], true)
    ));

    expect($relations)->toBe([], 'AuditLog must not declare a relation the DDL cannot back');
});

test('the two indexes the brief names are the two the DDL declares', function () {
    $indexed = array_map(
        fn ($index): string => implode(' ', $index->columns),
        audSpec()->table('audit_log')->indexes
    );

    // Pest's toContain is variadic, so a second argument is read as another
    // needle rather than as a message. Assert on the needle alone.
    expect($indexed)->toContain('user_id dibuat_at');
    expect($indexed)->toContain('tabel_target record_id dibuat_at');
});

test('the two credential tables excluded from the scope really hold nothing but a credential', function () {
    $spec = audSpec();

    foreach (['user_otp', 'user_refresh_tokens'] as $table) {
        $columns = $spec->table($table)->columns;

        expect($columns)->not->toBeNull($table);

        // Every remaining column is credential or lifecycle bookkeeping, which
        // is the whole reason the table is out of scope rather than merely
        // denied column by column. The residual is pinned exactly, so a column
        // that carries real data (a name, an address, a clinical value) fails
        // here and forces the exclusion decision to be reopened instead of
        // riding along.
        //
        // F01 added `device_id` (see docs/schema-notes.md). It is an opaque,
        // client-supplied installation identifier: it names no person, carries
        // no clinical value, and is not a credential either, so the table still
        // holds nothing but credential/lifecycle bookkeeping and the exclusion
        // stands.
        expect(array_keys($columns))->toBe($table === 'user_otp'
            ? ['id', 'user_id', 'kode_hash', 'tujuan', 'kedaluwarsa_at', 'sudah_dipakai', 'dibuat_at']
            : ['id', 'user_id', 'device_id', 'token_hash', 'kedaluwarsa_at', 'dicabut', 'dibuat_at'],
            $table.' gained a column; re-justify the exclusion');
    }

    // user_otp.kode_hash is the canonical case (telemedicine_test.sql:182
    // COMMENT 'Simpan hash, bukan OTP asli'): an allow-list would have to
    // deny every column, and a deny-list that denies every column is a
    // statement that the table should not be audited at all.
    expect($spec->table('user_otp')->columns)->toHaveKey('kode_hash');
    expect($spec->table('user_refresh_tokens')->columns)->toHaveKey('token_hash');
});

test('every telemedicine_test.sql citation in app/ points at a real line', function () {
    $lines = (int) count(file(base_path('telemedicine_test.sql')));
    $checked = 0;

    foreach (audPhpFiles('app') as $path) {
        preg_match_all('~telemedicine_test\.sql:(\d+)~', (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $cited) {
            expect((int) $cited)->toBeGreaterThan(0, $path)
                ->and((int) $cited)->toBeLessThanOrEqual($lines, $path.' cites :'.$cited);

            $checked++;
        }
    }

    // A vacuous pass would be the failure mode here, so the scan is required to
    // have found citations to check.
    expect($checked)->toBeGreaterThan(10);
});

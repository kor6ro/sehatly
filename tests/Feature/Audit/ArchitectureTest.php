<?php

declare(strict_types=1);

use App\Services\Audit\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| One writer, no controller writes, and the captured request context
|--------------------------------------------------------------------------
|
| APPENDED by todo 43.
|
| A global observer is only trustworthy if it is the ONLY thing that can write
| an audit row. If a controller can also insert one, then the redaction gates
| are bypassable by anyone who finds the easier path, and "no direct audit
| writes" becomes a review comment rather than a property.
|
| So the write path is a single class, and the suite proves it by TOKENISING
| `app/` and looking for the string `audit_log` in CODE, with every comment and
| docblock stripped. Comments are dropped because `audit_log` is named in prose
| in more than a dozen docblocks across this application, and a raw substring
| search would report the citation and not the write.
|
| The three files allowed to mention it are: the writer, the model, and this
| test. Anything else that names it is a second path in.
|
| @see \App\Services\Audit\AuditWriter
*/

test('no controller writes an audit row, in any spelling', function () {
    expect(audFilesMentioning('app/Http/Controllers', 'audit_log'))->toBe([]);
    expect(audFilesMentioning('app/Http/Controllers', 'AuditLog'))->toBe([]);

    // And the resource responses do not expose one either: `tabel_target` is
    // nullable so a caller can address an audit row, but a caller must not be
    // able to create one through an endpoint.
    $routes = collect(app('router')->getRoutes())->map(
        fn ($route) => $route->uri().' '.implode('|', $route->methods())
    );

    expect($routes->filter(fn (string $route) => str_contains($route, 'audit'))->values()->all())
        ->toBe([], 'the audit log is not an API resource');
});

test('no service writes an audit row except the one writer', function () {
    $hits = audFilesMentioning('app/Services', 'audit_log');
    $auditLog = audFilesMentioning('app/Services', 'AuditLog');

    expect(array_merge($hits, $auditLog))
        ->toBe(['app/Services/Audit/AuditWriter.php'], 'AuditWriter is the only write path');
});

test('the writer is the only place in app/ that names the table, apart from the model', function () {
    $mentions = array_merge(
        audFilesMentioning('app', "'audit_log'"),
        audFilesMentioning('app', '"audit_log"')
    );

    sort($mentions);

    // app/Models/AuditLog.php names it in `protected $table`, and
    // app/Services/Audit/AuditWriter.php names it in the insert. Nothing else.
    expect($mentions)->toBe([
        'app/Models/AuditLog.php',
        'app/Services/Audit/AuditWriter.php',
    ]);
});

test('the writer only ever inserts, so no code path can rewrite a log row', function () {
    $code = audCode(base_path('app/Services/Audit/AuditWriter.php'));

    expect($code)->toContain('insertGetId');
    expect($code)->not->toContain('->update(');
    expect($code)->not->toContain('->delete(');
    expect($code)->not->toContain('->upsert(');
    expect($code)->not->toContain('->truncate(');

    // The model side is symmetric: `audit_log` carries no `diubah_at`
    // (telemedicine_test.sql:1129), so Eloquent has no timestamp to maintain on
    // a second write, and there is no fillable attribute list to abuse.
    $modelCode = audCode(base_path('app/Models/AuditLog.php'));

    expect($modelCode)->not->toContain('function booted');
    expect($modelCode)->not->toContain('$fillable');
});

test('the writer encodes the payloads itself, so the stored JSON is readable', function () {
    // `DB::table()->insert()` applies no casts, unlike an Eloquent `create()`.
    // That is the point of the query builder here - the audit row is written
    // once, by one class, with no model lifecycle to hook - and the cost is
    // that the JSON must be encoded explicitly. This asserts the round trip.
    $id = (new AuditWriter)->catat(
        'update',
        'pasien',
        '4242',
        ['status' => 'draft'],
        ['status' => 'final'],
    );

    $row = DB::table('audit_log')->where('id', $id)->first();

    expect($id)->toBeGreaterThan(0);
    expect($row->tabel_target)->toBe('pasien');
    expect($row->record_id)->toBe('4242');
    expect(json_decode((string) $row->data_lama, true, 512, JSON_THROW_ON_ERROR))->toBe(['status' => 'draft']);
    expect(json_decode((string) $row->data_baru, true, 512, JSON_THROW_ON_ERROR))->toBe(['status' => 'final']);

    // The writer does not redact: the observer hands it an already-redacted
    // payload, and this asserts the writer stores what it was given rather than
    // reaching for the row and reading it back itself.
    expect(json_decode((string) $row->data_baru, true))->toBe(['status' => 'final']);
});

test('the request context is captured and truncated to the widths the DDL allows', function () {
    app()->instance('request', Request::create('/api/v1/booking', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => str_repeat('aud-agent/', 80),
    ]));

    $id = (new AuditWriter)->catat('create', 'booking', '1', null, ['status' => 'terjadwal']);
    $row = DB::table('audit_log')->where('id', $id)->first();

    expect($row->ip_address)->toBe('203.0.113.9');
    expect($row->endpoint)->toBe('api/v1/booking');

    // telemedicine_test.sql:1127 and :1128 are VARCHAR(255) and VARCHAR(200).
    // A 640 character user agent must not become a truncated-by-the-database
    // row or a failed insert, so the writer cuts it itself.
    expect(strlen((string) $row->user_agent))->toBe(255);
    expect(strlen((string) $row->endpoint))->toBeLessThanOrEqual(200);
});

test('a long endpoint is cut by the writer rather than by the column', function () {
    $long = 'a/'.str_repeat('b', 400);

    app()->instance('request', Request::create('/'.$long, 'GET'));

    $id = (new AuditWriter)->catat('read', 'rekam_medis', '1', null, null);
    $row = DB::table('audit_log')->where('id', $id)->first();

    expect(strlen((string) $row->endpoint))->toBe(200);
});

test('a write outside any request records a null context rather than a guess', function () {
    // A queued job, a console command, and a seeder all run with no request. A
    // fabricated IP or endpoint would be worse than none: it would look like
    // evidence.
    app()->instance('request', Request::create('/', 'GET'));

    $id = (new AuditWriter)->catat('create', 'users', '1', null, ['tipe' => 'pasien']);
    $row = DB::table('audit_log')->where('id', $id)->first();

    expect($row->ip_address)->toBeNull('Request::create sets no REMOTE_ADDR');
    expect($row->user_agent)->toBeNull();
    expect($row->user_id)->toBeNull('no authenticated actor outside a request');
});

test('the authenticated actor is recorded when there is one', function () {
    $user = App\Models\User::query()->whereKey(audUserRow())->firstOrFail();

    $this->actingAs($user);

    $id = (new AuditWriter)->catat('update', 'users', (string) $user->getKey(), null, ['status' => 'aktif']);
    $row = DB::table('audit_log')->where('id', $id)->first();

    expect((int) $row->user_id)->toBe((int) $user->getKey());
});

<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Audit\AuditColumnPolicy;
use App\Services\Audit\AuditLogWriter;
use App\Support\NikMasker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/audit-helpers.php';

/*
|--------------------------------------------------------------------------
| One writer, no controller writes, and the captured request context
|--------------------------------------------------------------------------
|
| A global observer is only trustworthy if it is the ONLY thing that can write
| an audit row. If a controller can also insert one, then the redaction gates
| are bypassable by anyone who finds the easier path, and "no direct audit
| writes" becomes a review comment rather than a property.
|
| So the write path is a single class, and the suite proves it by TOKENISING
| `app/` and looking for the table name in CODE, with every comment and
| docblock stripped. Comments are dropped because the name is cited in prose in
| more than a dozen docblocks across this application, and a raw substring
| search would report the citation and not the write.
|
| The request-context tests drive a REAL audited write under a bound Request
| rather than calling a helper directly, so they cover the whole chain
| observer -> writer -> insert. A test of a private insert helper would prove
| the helper works, not that a log row ever gets an IP address.
|
| @see \App\Services\Audit\AuditLogWriter
*/

test('no controller writes an audit row, in any spelling', function () {
    // The whole of app/Http/Controllers, generated from the filesystem, so the
    // scan cannot go stale the moment somebody adds a controller.
    //
    // The needles are the WRITE FORMS, not the bare identifier. `AuthController`
    // legitimately imports `AuditLogWriter` and calls `->login()` and `->logout()`
    // on it: the token API has no session event to hook, so that write has to be
    // an explicit service call. A needle of `AuditLog` alone would flag that
    // import and force the choice between a missing session log and a rule
    // bent to accommodate a legitimate call. What must be impossible is a
    // controller reaching the TABLE or the MODEL, so that is what is asserted.
    expect(audFilesMentioning('app/Http/Controllers', 'audit_log'))->toBe([]);
    expect(audFilesMentioning('app/Http/Controllers', 'AuditLog::'))->toBe([]);
    expect(audFilesMentioning('app/Http/Controllers', 'Models\\AuditLog'))->toBe([]);

    // Spelled the other way, so a rename of the service cannot reopen the door.
    foreach (audFilesMentioning('app/Http/Controllers', 'insert') as $relative) {
        $code = audCode(base_path($relative));

        expect(str_contains($code, 'audit_log'))
            ->toBeFalse($relative.' inserts an audit row directly');
    }

    // And the log is not an API resource: `tabel_target` is nullable so a
    // caller can address an audit row, but a caller must not be able to create
    // one through an endpoint.
    $routes = collect(app('router')->getRoutes())->map(
        fn ($route) => $route->uri().' '.implode('|', $route->methods())
    );

    expect($routes->filter(fn (string $route) => str_contains($route, 'audit'))->values()->all())
        ->toBe([], 'the audit log is not an API resource');
});

test('the writer is the only file in app/ that names the table, apart from the model', function () {
    $mentions = array_values(array_unique(array_merge(
        audFilesMentioning('app', "'audit_log'"),
        audFilesMentioning('app', '"audit_log"'),
    )));

    sort($mentions);

    // app/Models/AuditLog.php names it in `protected $table`; the writer names
    // it in the insert. Nothing else - not the observer, not the registrar, not
    // the policy. A second mention is a second way in.
    expect($mentions)->toBe([
        'app/Models/AuditLog.php',
        'app/Services/Audit/AuditLogWriter.php',
    ]);
});

test('the writer only ever inserts, so no code path can rewrite a log row', function () {
    $code = audCode(base_path('app/Services/Audit/AuditLogWriter.php'));

    expect($code)->toContain('DB::table');
    expect($code)->not->toContain('->update(');
    expect($code)->not->toContain('->delete(');
    expect($code)->not->toContain('->upsert(');
    expect($code)->not->toContain('->truncate(');
    expect($code)->not->toContain('insertOrIgnore');

    // The model side is symmetric: `audit_log` carries no `diubah_at`
    // (telemedicine_test.sql:1129), so Eloquent has no timestamp to maintain on
    // a second write, and there is no fillable list to mass-assign through.
    $modelCode = audCode(base_path('app/Models/AuditLog.php'));

    expect($modelCode)->not->toContain('function booted');
    expect($modelCode)->not->toContain('$fillable');
});

test('the log row is unreachable through Eloquent from any other model', function () {
    // `audit_log.user_id` and `audit_log.record_id` are deliberately BARE, so
    // there is no foreign key and therefore no relation to traverse. If any
    // model declared one it would be a guess the database cannot back.
    $spec = audSpec()->table('audit_log');

    expect($spec->foreignKeys)->toBe([]);

    $guilty = [];

    foreach (audPhpFiles('app/Models') as $path) {
        $code = audCode($path);

        foreach (['belongsTo(AuditLog', 'hasMany(AuditLog', 'hasOne(AuditLog', 'morphTo(AuditLog'] as $needle) {
            if (str_contains($code, $needle)) {
                $guilty[] = $path;
            }
        }
    }

    expect(array_values(array_unique($guilty)))->toBe([]);
});

test('the writer encodes the payloads itself, so the stored JSON is readable', function () {
    // `DB::table()->insert()` applies no casts, unlike an Eloquent `create()`.
    // That is the point of the query builder here - the audit row is written
    // once, by one class, with no model lifecycle to hook - and the cost is
    // that the JSON must be encoded explicitly. This asserts the round trip.
    $pasien = audPasienModel();

    $row = audOne('pasien', (int) $pasien->getKey());
    $payload = audPayload($row, 'data_baru');

    expect($payload)->toBeArray();
    expect($payload)->toHaveKey('jenis_kelamin');
    expect($payload['jenis_kelamin'])->toBe('P');
});

test('the request context is captured and truncated to the widths the DDL allows', function () {
    app()->instance('request', Request::create('/api/v1/booking', 'POST', [], [], [], [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => str_repeat('aud-agent/', 80),
    ]));

    $pasien = audPasienModel();
    $row = audOne('pasien', (int) $pasien->getKey());

    expect($row->ip_address)->toBe('203.0.113.9');
    expect($row->endpoint)->toBe('api/v1/booking');

    // telemedicine_test.sql:1127 is VARCHAR(255). A 640 character user agent
    // must not become a truncated-by-the-database row or a failed insert, so
    // the writer cuts it itself.
    expect(strlen((string) $row->user_agent))->toBe(255);
});

test('a long endpoint is cut by the writer rather than by the column', function () {
    $long = 'a/'.str_repeat('b', 400);

    app()->instance('request', Request::create('/'.$long, 'GET'));

    $pasien = audPasienModel();
    $row = audOne('pasien', (int) $pasien->getKey());

    // telemedicine_test.sql:1128 is VARCHAR(200).
    expect(strlen((string) $row->endpoint))->toBe(200);
});

test('a write outside any authenticated request records a null actor, not a guess', function () {
    // A queued job, a console command and a seeder all run with no request and
    // no actor. A fabricated IP or user id would be worse than none: it would
    // look like evidence.
    //
    // `Request::create()` seeds REMOTE_ADDR with 127.0.0.1, so "no request
    // context" has to be asked for explicitly by blanking it. Without that the
    // test would pass a real address and assert nothing.
    app()->instance('request', Request::create('/', 'GET', [], [], [], [
        'REMOTE_ADDR' => '',
        'HTTP_USER_AGENT' => '',
    ]));

    $pasien = audPasienModel();
    $row = audOne('pasien', (int) $pasien->getKey());

    expect($row->ip_address)->toBeNull();
    expect($row->user_agent)->toBeNull();
    expect($row->user_id)->toBeNull('no authenticated actor outside a request');
});

test('the authenticated actor is recorded when there is one', function () {
    $userId = audUserRow();

    $this->actingAs(User::query()->whereKey($userId)->firstOrFail());

    $pasien = audPasienModel();
    $row = audOne('pasien', (int) $pasien->getKey());

    expect((int) $row->user_id)->toBe($userId);
});

test('the writer never re-uses the project masker for anything but identifiers', function () {
    // Guards against a second masker creeping back in: the only masking
    // primitive available to the audit path is the one the API already
    // publishes NIK through, so a masked NIK looks identical whether it came
    // from a response body or from a log row.
    $code = audCode(base_path('app/Services/Audit/AuditColumnPolicy.php'));

    expect($code)->toContain('NikMasker::mask');
    expect($code)->toContain('NikMasker::PENGGANTI');

    // And the two forms agree, which is the property that makes one masker
    // necessary rather than merely tidy.
    $nik = '3273010101900001';

    expect(AuditColumnPolicy::redactValue($nik, 'nik'))->toBe(NikMasker::mask($nik));
});

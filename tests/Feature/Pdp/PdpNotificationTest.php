<?php

declare(strict_types=1);

use App\Enums\NotifikasiTipe;
use App\Enums\PersetujuanPdpJenis;
use App\Models\Notifikasi;
use App\Models\PersetujuanPdp;
use App\Models\Rujukan;
use App\Models\SuratKeterangan;
use App\Models\User;
use App\Services\Audit\AuditObserverRegistrar;
use App\Services\Notifikasi\NotificationService;
use App\Services\Pdp\PdpConsent;
use App\Services\Pdp\PdpConsentService;
use App\Services\Pdp\PerubahanVersiException;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

require_once __DIR__.'/pdp47-helpers.php';

beforeEach(function (): void {
    // Declared in THIS file, not in `pdp47-helpers.php`: that file is
    // `require_once`d by two test files, so a hook declared in it is registered
    // for the FIRST one only. The same trap todo 46 recorded as 35 errors.
    pd47KunciJam();
    $this->seed(RbacSeeder::class);
});

afterEach(function (): void {
    pd47LepasJam();
});

/*
|--------------------------------------------------------------------------
| Todo 47 - PDP consent versioning and the notification centre
|--------------------------------------------------------------------------
|
| ## THE VERSION RULE, stated before the code and pinned by the tests below
|
| `persetujuan_pdp` is `telemedicine_test.sql:1134`-`:1145`. Two of its columns
| decide everything:
|
| ```
| :1139  versi_dokumen VARCHAR(20) NOT NULL,
| :1140  disetujui      TINYINT(1) NOT NULL,
| :1144  UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)
| ```
|
| The unique key is over THREE columns, and `disetujui` is `NOT NULL` with no
| nullable twin. So a second row carrying the same document version cannot exist,
| and an existing row's `disetujui` cannot be nulled. **A revocation of a given
| document version is therefore not representable in this schema at all.** The
| only way to record a withdrawal is a NEW `versi_dokumen` carrying
| `disetujui = 0`.
|
| ### The rule: EFFECTIVE = the `disetujui` of the HIGHEST `versi_dokumen`
|
| For one `(user_id, jenis)` pair, the answer to "has this person consented?" is
| read off the single row with the maximum `versi_dokumen` - never the first
| row, never the last row written. Concretely, six cases, and the three that
| matter are the three boundaries:
|
| | # | situation | outcome |
| | --- | --- | --- |
| | V1 | no row at all | `effective() === null`: never answered. Every gate treats it as a refusal. |
| | V2 | highest version, `disetujui = 1` | consented |
| | V3 | highest version, `disetujui = 0` | refused - this is the withdrawal |
| | V4 | a row arrives at a version LOWER than the current maximum | **REFUSED, nothing written** (422 on `versi_dokumen`) |
| | V5 | a row arrives at a version HIGHER than the current maximum | written, and it supersedes every lower version at once |
| | V6 | a row arrives at the version ALREADY present, with the same `disetujui` | 200, the existing row, byte-identical (idempotent re-send) |
| | V6' | a row arrives at the version ALREADY present, with a DIFFERENT `disetujui` | **REFUSED, nothing written** (422 on `versi_dokumen`) - the collision |
|
| **The defect this prevents.** V4 and V6' are the two ways a consent store
| silently resurrects a revoked document. A check that read the FIRST row, or
| that read "any row with `disetujui = 1`", would honour the consent at v1 after
| the person withdrew it at v2. So the write path refuses V4 and V6' LOUDLY
| instead of accepting a row that could never be honoured.
|
| **V4 in one sentence: a revocation at version N is never superseded by a
| consent at version N-1.** Nothing below the maximum can change the answer.
|
| ## THE COLLISION (V6'), which is the acceptance criterion
|
| "Revoke consent for version v2 when v2 is already recorded as approved."
|
| The honest answer is that **this operation does not exist**, and the API says
| so rather than inventing a way. Specifically:
|
| 1. It is **refused**, with 422 and TWO messages on the single field
|    `versi_dokumen` - "already recorded, cannot be changed" and "withdraw by
|    sending a higher version". Both are preserved, in order, because a
|    concatenated single string cannot be asserted per position.
| 2. **Nothing is written.** No `UPDATE`, no second row. The test compares the
|    existing row byte for byte, `id` and `disetujui_at` and `ip_address`
|    included.
| 3. **The effective answer does not move.** The row count and the effective
|    value are asserted afterwards, so a "fixed" implementation that upserted
|    would fail here.
| 4. **No index and no column is added to make it representable.** The
|    acceptance criterion for this todo is to handle the collision as a
|    documented outcome, and the DDL is read-only law in this project.
|
| ### What a client does on receiving the 422
|
| Read the two messages as one instruction: the decision about THAT document
| version is already on record and is immutable, and a withdrawal is a NEW
| version. Concretely, in the Dart client:
|
| - On 422 with `errors.versi_dokumen`, do NOT retry. A retry is byte-identical
|   and will be refused identically.
| - `GET /api/v1/pdp/persetujuan` and read `data.persetujuan[jenis].efektif`.
|   That is the effective answer, already resolved through the version rule, so
|   the client never has to re-implement "highest version" itself.
* - To actually withdraw, the client must send a `versi_dokumen` STRICTLY
|   GREATER than the one in that response - and a client cannot invent a
|   version, because the version is a property of the document being consented
|   to, not of the account. So a real withdrawal is driven by the document
|   catalogue advancing, not by the patient typing a string.
| - Show the failure as "this version is already recorded" and point at the
|   recorded `versi_dokumen` and `disetujui_at`, which the same response
|   carries. The patient can see what is on record about them.
|
| ## The layer under the rule: `uq_consent` itself
|
| The pre-check above is an APPLICATION rule, and an application rule can be
| wrong or racy. `uq_consent` is the DDL's own rule and it fires regardless, so
| the service catches a duplicate-entry violation and maps it to the SAME 422
| the pre-check produces. Two tests cover the two layers: one drives a raw
| INSERT and asserts the real MySQL 1062 (proving the limitation is real rather
| than assumed, which the plan's acceptance criteria demand), and one forces a
| genuine two-connection race and asserts the racing request also answers 422
| rather than 500.
|
| ## `effective()` and `disetujui()` are NOT the same method
|
| `effective()` is `?bool`: `null` means "no row", which is a different fact from
| `false`, which means "a row says no". The GET endpoint has to publish that
| difference or its checklist has a hole in it. `disetujui()` is the boolean the
| gates use, and collapses `null` into `false` - the safe direction, because a
| gate with no row must refuse.
|
| ## A 422 here carries MULTIPLE messages on ONE field
|
| Both refusals put two messages on `versi_dokumen`, and each is asserted by its
| position in the array. A concatenation into one string would pass the same
| `assertJsonPath('errors.versi_dokumen', '...')`.
|
| ## The DDL citations are asserted, not quoted
|
| Every line number below is read back out of `telemedicine_test.sql` by
| {@see pd47AssertLine()} / {@see pd47AssertLineLacks()}, because the plan's own
| `:NNN` citations are off by one in places: it names `notifikasi.idx_notif` at
| `:1045`, and `:1045` is `dibuat_at` - the index is on `:1047`.
*/

// =====================================================================
// The DDL, read from the file
// =====================================================================

test('the three tables this todo reads and writes are cited from the file, ranges included', function (): void {
    // CREATE and the closing ENGINE for each, so a citation cannot drift past
    // the table it names. The ranges are derived, not typed: {@see pd47Blok()}
    // walks the file from the CREATE to the first `) ENGINE=InnoDB;`.
    $persetujuan = pd47Blok('persetujuan_pdp');
    $notifikasi = pd47Blok('notifikasi');
    $perangkat = pd47Blok('user_devices');

    expect($persetujuan[0])->toStartWith('CREATE TABLE persetujuan_pdp')
        ->and(end($persetujuan))->toBe(') ENGINE=InnoDB;')
        ->and($notifikasi[0])->toStartWith('CREATE TABLE notifikasi')
        ->and(end($notifikasi))->toBe(') ENGINE=InnoDB;')
        ->and($perangkat[0])->toStartWith('CREATE TABLE user_devices')
        ->and(end($perangkat))->toBe(') ENGINE=InnoDB;');

    // Twelve lines, CREATE and ENGINE included. Asserted so that a future edit
    // to the contract fails HERE rather than silently re-basing every citation
    // in this file.
    expect(count($persetujuan))->toBe(12)
        ->and(count($notifikasi))->toBe(13)
        ->and(count($perangkat))->toBe(13);

    // The three columns the version rule reads, at the lines cited.
    pd47AssertLine(1136, 'user_id BIGINT UNSIGNED NOT NULL');
    pd47AssertLine(1139, 'versi_dokumen VARCHAR(20) NOT NULL');
    pd47AssertLine(1140, 'disetujui TINYINT(1) NOT NULL');
    pd47AssertLine(1141, 'disetujui_at DATETIME NOT NULL');
    pd47AssertLine(1142, 'ip_address VARCHAR(45) NULL');
    pd47AssertLine(1143, 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    pd47AssertLine(1144, 'UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)');

    // The WRAPPED five-value ENUM. Line 1137 alone is a truncated list, which is
    // the plan's appendix rule 6; both lines are read, and the value list is
    // asserted as a whole against the parsed DDL further down.
    pd47AssertLine(1137, "jenis ENUM('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis',");
    pd47AssertLine(1138, "'pemasaran','komunikasi_tindak_lanjut') NOT NULL");

    // The three ABSENCES this todo is built around. A positive search cannot
    // prove a column is missing.
    $isiPersetujuan = implode("\n", $persetujuan);
    $isiNotifikasi = implode("\n", $notifikasi);

    expect($isiPersetujuan)->not->toContain('dibuat_at')
        ->and($isiPersetujuan)->not->toContain('diubah_at')
        ->and($isiPersetujuan)->not->toContain('dibatalkan_at')
        ->and($isiNotifikasi)->not->toContain('dikirim_at')
        ->and($isiNotifikasi)->not->toContain('status_kirim')
        ->and($isiNotifikasi)->not->toContain('channel');

    // `uq_consent` names EXACTLY three columns. A fourth would change what the
    // collision means, and `str_contains` alone would not notice.
    expect(substr_count($isiPersetujuan, 'UNIQUE KEY'))->toBe(1)
        ->and($isiPersetujuan)->toContain('UNIQUE KEY uq_consent (user_id, jenis, versi_dokumen)');

    // The notifikasi columns the centre reads and the ONE it writes.
    pd47AssertLine(1038, 'user_id BIGINT UNSIGNED NOT NULL');
    pd47AssertLine(1039, 'judul VARCHAR(200) NOT NULL');
    pd47AssertLine(1040, 'isi VARCHAR(500) NOT NULL');
    pd47AssertLine(1041, "tipe ENUM('booking','pembayaran','resep','chat','lab','promo','sistem') NOT NULL");
    pd47AssertLine(1042, 'tautan VARCHAR(500) NULL');
    pd47AssertLine(1043, 'payload JSON NULL');
    pd47AssertLine(1044, 'dibaca_at DATETIME NULL');
    pd47AssertLine(1045, 'dibuat_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    pd47AssertLine(1046, 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE');
    // The plan cites `idx_notif` at `:1045`; that is `dibuat_at`. It is `:1047`.
    pd47AssertLine(1047, 'INDEX idx_notif (user_id, dibaca_at)');

    // The push token the notification service dispatches through.
    pd47AssertLine(192, 'user_id BIGINT UNSIGNED NOT NULL');
    pd47AssertLine(194, "platform ENUM('android','ios','web') NOT NULL");
    pd47AssertLine(195, 'fcm_token VARCHAR(255) NULL');
    pd47AssertLine(197, 'aktif TINYINT(1) NOT NULL DEFAULT 1');
});

// =====================================================================
// The route census
// =====================================================================

test('the five routes are registered with exactly the guards this todo claims', function (): void {
    $routes = pd47Routes();

    // The closed set, keyed by `METHOD uri`.
    foreach ([
        'GET api/v1/pdp/persetujuan',
        'POST api/v1/pdp/persetujuan',
        'GET api/v1/notifikasi',
        'PUT api/v1/notifikasi/{id}/baca',
        'PUT api/v1/notifikasi/baca-semua',
    ] as $diumi) {
        // `toHaveKey`'s second argument is the EXPECTED VALUE, not a message -
        // passing a sentence there asserts the middleware list equals that
        // sentence, which fails with "does not match expected type string" and
        // tells the reader nothing. The first draft of this line did exactly
        // that, and the resulting message named no route at all.
        expect(array_key_exists($diumi, $routes))->toBeTrue('route '.$diumi.' is not registered');
    }

    // The consent routes carry `auth:sanctum` and NOTHING else. See the guard
    // table in the docblock of `PdpConsentController` for why zero is the
    // answer rather than an omission.
    expect(pd47Guards($routes, 'GET api/v1/pdp/persetujuan'))
        ->toBe(['api', 'auth:sanctum'])
        ->and(pd47Guards($routes, 'POST api/v1/pdp/persetujuan'))
        ->toBe(['api', 'auth:sanctum']);

    // The notification routes carry `permission:notifikasi.lihat`. The two writes
    // also carry the F-009 throttle: both write rows, and the bulk one writes every
    // unread row the caller owns, so the budget is per user and shared between them.
    expect(pd47Guards($routes, 'GET api/v1/notifikasi'))
        ->toBe(['api', 'auth:sanctum', 'permission:notifikasi.lihat']);

    foreach ([
        'PUT api/v1/notifikasi/{id}/baca',
        'PUT api/v1/notifikasi/baca-semua',
    ] as $diumi) {
        expect(pd47Guards($routes, $diumi))
            ->toBe(['api', 'auth:sanctum', 'permission:notifikasi.lihat', 'throttle:notifikasi-baca']);
    }

    // `pdp.kelola` is a catalogue code with NO consumer, and that is a decision
    // rather than an oversight - the reason is asserted in the guard test below.
    foreach ($routes as $uri => $middleware) {
        // `not->toContain` with ONE needle: Pest's `toContain` is variadic, so a
        // second argument is a second needle and would quietly make the assertion
        // pass for the wrong reason.
        expect($middleware)->not->toContain('permission:pdp.kelola');
    }

    // A non-numeric id never reaches the controller at all: `whereNumber`
    // compiles the segment to a regex, so the router 404s it. The CONSTRAINT is
    // asserted as present, and the segment as rejected, rather than against a
    // literal pattern string - the exact regex Laravel compiles is a framework
    // detail and pinning it would make this test fail on a harmless upgrade
    // instead of on a real regression. The rejection itself is driven over HTTP
    // in the cross-account test below.
    $rute = Route::getRoutes()->getByName('notifikasi.baca');

    expect($rute)->not->toBeNull()
        ->and($rute->wheres)->toHaveKey('id');
});

// =====================================================================
// THE VERSION RULE - the three boundaries
// =====================================================================

test('BOUNDARY 1 of 3: a HIGHER version supersedes every lower one, immediately', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $userId = (int) $akun['user']->getKey();

    // V1: no row at all. `effective()` is NULL, which is a different fact from
    // `false`, and a gate still refuses.
    expect($consent->effective($akun['user'], $jenis))->toBeNull()
        ->and($consent->disetujui($akun['user'], $jenis))->toBeFalse()
        ->and($consent->ringkasan($akun['user']))->toHaveCount(5);

    // V2: the first version. Approved.
    $service->catat($akun['user'], $jenis, 'v1', true, '198.51.100.4');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v1')
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->ip_address)->toBe('198.51.100.4');

    // THE SUPERSESSION. A LOWER version arrives afterwards and is REFUSED - this
    // is the case that silently resurrects a revoked document if the rule is
    // read as "any approved row" or as "the first row".
    $e = pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v0', true, '198.51.100.5'),
        PerubahanVersiException::class,
    );

    expect($e->errors()['versi_dokumen'])->toBe([
        'Versi dokumen ini lebih lama dari versi yang sudah tercatat.',
        'Kirim versi_dokumen yang lebih tinggi agar persetujuan yang baru berlaku.',
    ]);

    // Nothing was written and the answer did not move.
    expect(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(1)
        ->and($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v1');

    // V3: the representable withdrawal. A HIGHER version carrying
    // `disetujui = 0`, and the effective answer flips on the same request.
    $service->catat($akun['user'], $jenis, 'v2', false, '198.51.100.6');

    expect($consent->effective($akun['user'], $jenis))->toBeFalse()
        ->and($consent->disetujui($akun['user'], $jenis))->toBeFalse()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v2')
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(2);

    // And a gate refuses on the withdrawal rather than silently proceeding.
    expect(fn () => $consent->require($akun['user'], $jenis))
        ->toThrow(AccessDeniedHttpException::class);

    // V5 again in the other direction: re-approving at a higher version
    // supersedes the withdrawal. Both rows stay on the table - the table is a
    // HISTORY, and the version rule is what reads it.
    $service->catat($akun['user'], $jenis, 'v3', true, '198.51.100.7');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v3')
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(3)
        // `pluck()` on an ELOQUENT builder returns the CAST value, not the raw
        // column, so these are booleans rather than the `TINYINT(1)` integers the
        // raw builder would hand back - and the cast is the model's, so this also
        // asserts `disetujui` is cast to boolean at all.
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->orderBy('versi_dokumen')->pluck('disetujui')->all())
        ->toBe([true, false, true]);
});

test('BOUNDARY 2 of 3: a LOWER version arriving after a higher one is refused and changes nothing', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = 'kebijakan_privasi';
    $userId = (int) $akun['user']->getKey();

    // The withdrawal lands FIRST, at a high version, and the approval behind it
    // is what a naive "any approved row exists" check would keep honouring.
    pd47ConsentRaw($userId, $jenis, 'v09', false);
    pd47ConsentRaw($userId, $jenis, 'v05', true);

    // The maximum is v09 and it says no.
    expect($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v09')
        ->and($consent->effective($akun['user'], $jenis))->toBeFalse();

    // A client holding a STALE document tries to approve it at a version it has
    // not answered yet - `v07`, which is below the recorded `v09`. The service
    // refuses, and refuses the same way it refuses a collision - loudly.
    //
    // `v05` is deliberately NOT what is sent here: `v05` is ALREADY on record with
    // `disetujui = 1`, so sending it again with the same answer is the IDEMPOTENT
    // case and is boundary 3's subject. Sending an existing version with a
    // CONTRADICTING answer is the collision, which is the subject of the next
    // test. This one is about a version that does not exist and could not win.
    $e = pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v07', true, '198.51.100.8'),
        PerubahanVersiException::class,
    );

    expect($e->errors())->toHaveKey('versi_dokumen')
        ->and($e->errors()['versi_dokumen'])->toHaveCount(2)
        ->and($e->errors()['versi_dokumen'][0])->toStartWith('Versi dokumen ini lebih lama');

    // A version still lower than v09 is refused too, so "not equal to the
    // maximum" is not enough - the comparison is a real ordering.
    pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v08', true, '198.51.100.9'),
        PerubahanVersiException::class,
    );

    // The version already on record, re-sent with the SAME answer, is the
    // idempotent case and writes nothing - which is why boundary 3 uses it and
    // this test does not.
    $ulang = $service->catat($akun['user'], $jenis, 'v05', true, '198.51.100.8');

    expect((string) $ulang->versi_dokumen)->toBe('v05')
        ->and($ulang->ip_address)->toBeNull();

    // The stored pair is byte-identical and the answer has not moved.
    $baris = DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->where('jenis', $jenis)
        ->orderBy('versi_dokumen')
        ->get();

    expect($baris)->toHaveCount(2)
        ->and($baris->pluck('versi_dokumen')->all())->toBe(['v05', 'v09'])
        ->and($baris->pluck('disetujui')->all())->toBe([1, 0])
        ->and($baris->pluck('ip_address')->all())->toBe([null, null])
        ->and($consent->effective($akun['user'], $jenis))->toBeFalse();

    // A version ABOVE the maximum is accepted, and only then does the answer
    // move - so the refusal above was a real gate and not a blanket denial.
    $service->catat($akun['user'], $jenis, 'v10', true, '198.51.100.10');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v10')
        // WHY THE VERSIONS ARE ZERO-PADDED IN THIS TEST, which is the writer-side
        // convention `PdpConsent`'s docblock names. `versi_dokumen` is
        // `VARCHAR(20)` (`:1139`), so "highest" is the column's own STRING order:
        // 'v10' sorts BELOW 'v9' because '1' (0x31) < '9' (0x39) at the second
        // position. An unpadded 'v10' against a stored 'v9' is therefore a LOWER
        // version and is refused - asserted here rather than described, because
        // writing this test with 'v9' and 'v10' unpadded produced a refusal that
        // read exactly like a bug in the version rule and was not one.
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->orderBy('versi_dokumen')->pluck('versi_dokumen')->all())
        ->toBe(['v05', 'v09', 'v10']);
});

test('BOUNDARY 3 of 3: the SAME version twice is idempotent when the answer agrees', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = 'syarat_ketentuan';
    $userId = (int) $akun['user']->getKey();

    $pertama = $service->catat($akun['user'], $jenis, 'v1', true, '198.51.100.11');

    // The client's request never got its response and it retries. Byte-identical
    // body, so the server CAN tell this from a contradictory second decision -
    // and must, because refusing it would make every retry a hard failure while
    // accepting it would be indistinguishable from a re-approval.
    $kedua = $service->catat($akun['user'], $jenis, 'v1', true, '198.51.100.99');

    expect((int) $kedua->getKey())->toBe((int) $pertama->getKey())
        ->and($kedua->ip_address)->toBe('198.51.100.11', 'the retry overwrote the recorded address')
        ->and($kedua->disetujui_at->toDateTimeString())->toBe(pd47Jam()->toDateTimeString())
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(1);

    // The same for a withdrawal, and the second call wrote no second row.
    $tarik = $service->catat($akun['user'], $jenis, 'v2', false, '198.51.100.12');
    $tarikUlang = $service->catat($akun['user'], $jenis, 'v2', false, '198.51.100.99');

    expect((int) $tarikUlang->getKey())->toBe((int) $tarik->getKey())
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(2)
        ->and($consent->effective($akun['user'], $jenis))->toBeFalse();

    // The idempotent re-send produced NO second write and therefore no second
    // `audit_log` row: the audit is a record of DECISIONS, not of HTTP calls.
    $audit = DB::table('audit_log')
        ->where('tabel_target', 'persetujuan_pdp')
        ->where('record_id', (string) $tarik->getKey())
        ->count();

    expect($audit)->toBe(1);
});

// =====================================================================
// THE COLLISION - the acceptance criterion
// =====================================================================

test('the revoked-same-version collision is refused, writes nothing, and the effective answer does not move', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $userId = (int) $akun['user']->getKey();
    $jenisLain = 'pemasaran';

    // A patient approved v1 of the data-sharing terms, then withdrew at v2, and
    // a stale client now tries to REVOKE v1 - the version it happens to be
    // holding. Both directions of the collision are the same defect, so both
    // are driven: un-revoking, and re-approving.
    pd47ConsentRaw($userId, $jenis, 'v1', true, ['ip_address' => '198.51.100.20', 'disetujui_at' => '2026-01-02 03:04:05']);
    pd47ConsentRaw($userId, $jenis, 'v2', false);
    pd47ConsentRaw($userId, $jenisLain, 'v1', true);

    $sebelum = DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(static fn ($baris): array => (array) $baris)
        ->all();

    // The collision: same version, different answer. Two directions, two rows.
    $e1 = pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v1', false, '198.51.100.21'),
        PerubahanVersiException::class,
    );
    $e2 = pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenisLain, 'v1', false, '198.51.100.22'),
        PerubahanVersiException::class,
    );

    // TWO messages on ONE field, in order. Position-asserted, so a concatenation
    // into a single string fails here.
    $pesan = [
        'Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah.',
        'Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi.',
    ];

    expect($e1->errors())->toBe(['versi_dokumen' => $pesan])
        ->and($e2->errors())->toBe(['versi_dokumen' => $pesan]);

    // NOTHING was written, and the stored rows are byte-identical - every
    // column, not just `disetujui`. An `upsert` would change `disetujui_at` and
    // `ip_address` even when `disetujui` matched.
    $sesudah = DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->orderBy('id')
        ->get()
        ->map(static fn ($baris): array => (array) $baris)
        ->all();

    expect($sesudah)->toBe($sebelum)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(3);

    // And the effective answer did not move, in EITHER direction: the
    // withdrawal still stands, and the approval was not turned into a refusal.
    expect($consent->effective($akun['user'], $jenis))->toBeFalse()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe('v2')
        ->and($consent->effective($akun['user'], $jenisLain))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenisLain)?->versi_dokumen)->toBe('v1');

    // THE COLLATERAL OF DOING THE COMPARISON IN SQL, and it is a boundary of the
    // collision rather than a curiosity.
    //
    // `telemedicine_test.sql:11`-`:13` creates the database as
    // `COLLATE utf8mb4_unicode_ci` and no `CREATE TABLE` overrides it, so
    // `versi_dokumen` is compared CASE-INSENSITIVELY. `uq_consent` therefore
    // treats `V1` and `v1` as the same value - and a pre-check written in PHP's
    // `strcmp()` would not, because `'V' (0x56) < 'v' (0x76)`, so a strcmp
    // implementation would call `V1` a NEW HIGHER version, try to INSERT it, and
    // be killed by a raw 1062. The store would answer 500 for a value the
    // database had already decided was the same value.
    //
    // So this is the same collision, spelled with a capital letter.
    pd47ConsentRaw($userId, 'komunikasi_tindak_lanjut', 'v1', true);

    $sebelumV = DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->where('jenis', 'komunikasi_tindak_lanjut')
        ->sole();

    $eV = pd47Tangkap(
        fn () => $service->catat($akun['user'], 'komunikasi_tindak_lanjut', 'V1', false, '198.51.100.23'),
        PerubahanVersiException::class,
    );

    expect($eV->errors())->toBe(['versi_dokumen' => $pesan]);

    // Nothing was written under either spelling.
    expect(DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->where('jenis', 'komunikasi_tindak_lanjut')
        ->sole())
        ->toEqual($sebelumV);

    // And the same spelling with the SAME answer is the idempotent case, not a
    // new row - which is the only reason a client retrying under a different case
    // gets a 200 rather than a 500.
    $ulangV = $service->catat($akun['user'], 'komunikasi_tindak_lanjut', 'V1', true, '198.51.100.24');

    expect((int) $ulangV->getKey())->toBe((int) $sebelumV->id)
        ->and(DB::table('persetujuan_pdp')
            ->where('user_id', $userId)
            ->where('jenis', 'komunikasi_tindak_lanjut')
            ->count())->toBe(1);
});

test('a same-version insert really is rejected by uq_consent, at the database', function (): void {
    // The plan's acceptance criterion, and the point of this test: prove the
    // limitation is REAL rather than assumed. Nothing here goes through the
    // application, so a green run means MySQL refused the write - not that this
    // todo's own pre-check noticed.
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();

    pd47ConsentRaw($userId, PdpConsent::JENIS_BERBAGI_DATA, 'v1', true);

    $e = pd47Tangkap(
        fn () => DB::table('persetujuan_pdp')->insert([
            'user_id' => $userId,
            'jenis' => PdpConsent::JENIS_BERBAGI_DATA,
            'versi_dokumen' => 'v1',
            'disetujui' => 0,
            'disetujui_at' => pd47Jam(),
        ]),
        UniqueConstraintViolationException::class,
    );

    expect(pd47AdalahDuplikat($e))->toBeTrue('the driver code is not MySQL 1062')
        ->and((int) $e->errorInfo[1])->toBe(PD47_KODE_DUPLIKAT)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(1)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->value('disetujui'))->toBe(1);

    // The unique key is (user_id, jenis, versi_dokumen): a DIFFERENT jenis at the
    // same version is legal, and a different user at the same version is legal.
    // That is what makes the key a per-DECISION key rather than a per-account one.
    pd47ConsentRaw($userId, 'pemasaran', 'v1', true);
    $lain = pd47AkunPasien('Pasien Lain PDP');

    pd47ConsentRaw((int) $lain['user']->getKey(), PdpConsent::JENIS_BERBAGI_DATA, 'v1', true);

    expect(DB::table('persetujuan_pdp')->where('versi_dokumen', 'v1')->count())->toBe(3);
});

test('a writer that trips uq_consent BETWEEN the pre-check and the insert gets the same 422, not a 500', function (): void {
    // The pre-check is an APPLICATION rule and `uq_consent` is the DDL's own;
    // between the pre-check's SELECT and the INSERT a second writer can land a row
    // the pre-check never saw. This drives exactly that window, and without the
    // `UniqueConstraintViolationException` catch in `PdpConsentService::catat()` the
    // request would be a 500 - so the acceptance criterion would hold only for a
    // single writer.
    //
    // WHY ONE CONNECTION AND NOT TWO, and the reason is instructive. The obvious
    // choreography - a second connection committing the competing row - cannot work
    // here: `RefreshDatabase` holds its wrapper transaction open, so the `users`
    // row this test just created is UNCOMMITTED, and the second connection's INSERT
    // blocks on the foreign key's shared lock against that uncommitted parent row
    // until MySQL's 1205 lock-wait timeout. The first run of this test failed with
    // precisely that, in 8 seconds of waiting. The competing row is therefore
    // written on the SAME connection: same transaction, so it is visible to the
    // unique key immediately and there is no lock to wait for, and the property
    // under test - the mapping from a duplicate-entry violation to the documented
    // 422 - is produced by the identical 1062.
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;

    $sudahMasuk = false;
    $armed = true;

    // `Connection::beforeExecuting()` APPENDS to `$beforeExecutingCallbacks`, offers
    // no way to remove one, and hands the callback the statement's SQL as a STRING
    // plus the bindings plus the connection. So the hook is DISARMED by a captured
    // flag in the `finally` rather than uninstalled, and `DB::purge('mysql')` is
    // not an option either: it would drop the PDO holding `RefreshDatabase`'s
    // wrapper transaction, whose teardown then sets `migrated = false` and forces
    // a `migrate:fresh` for every remaining test in the process. The same trap
    // `po46Selesai()` exists to handle.
    DB::connection('mysql')->beforeExecuting(function (string $sql) use (&$sudahMasuk, &$armed, $userId, $jenis): void {
        if (! $armed || $sudahMasuk || ! str_contains(strtolower($sql), 'insert into `persetujuan_pdp`')) {
            return;
        }

        $sudahMasuk = true;

        // The competing writer wins the window. It is written BEFORE the service's
        // own INSERT reaches the server, so `uq_consent` sees two rows with the same
        // `(user_id, jenis, versi_dokumen)` and rejects the second.
        DB::table('persetujuan_pdp')->insert([
            'user_id' => $userId,
            'jenis' => $jenis,
            'versi_dokumen' => 'v1',
            'disetujui' => 0,
            'disetujui_at' => pd47Jam(),
        ]);
    });

    try {
        $response = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
            'jenis' => $jenis,
            'versi_dokumen' => 'v1',
            'disetujui' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The given data was invalid.')
            // The SAME two messages the pre-check produces, so a racing writer and
            // a single writer are indistinguishable to a client.
            ->assertJsonPath('errors.versi_dokumen', [
                'Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah.',
                'Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi.',
            ]);

        // The competing row is the only row, and the caller's own decision was NOT
        // written on top of it. The effective answer is therefore the competitor's
        // `disetujui = 0` - a refusal, not a silent approval.
        expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(1)
            ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->value('disetujui'))->toBe(0)
            ->and(app(PdpConsent::class)->effective($akun['user'], $jenis))->toBeFalse();
    } finally {
        $armed = false;
    }
});

// =====================================================================
// The consent endpoints
// =====================================================================

test('GET /pdp/persetujuan answers all five kinds with a tri-state resolved through the version rule', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();

    // One of each of the three effective states, plus a two-version pair whose
    // answer can only be got right by reading the maximum.
    pd47ConsentRaw($userId, 'syarat_ketentuan', 'v1', true, ['ip_address' => '198.51.100.30']);
    pd47ConsentRaw($userId, 'kebijakan_privasi', 'v1', true);
    pd47ConsentRaw($userId, 'kebijakan_privasi', 'v2', false, ['disetujui_at' => '2026-02-03 04:05:06']);
    // `pemasaran` and `komunikasi_tindak_lanjut` are deliberately left with no
    // row, so the response has a `null` in it.

    $response = pd47As($akun['user'])->getJson('/api/v1/pdp/persetujuan');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar persetujuan PDP berhasil dimuat.');

    // `meta` is a TOP-LEVEL SIBLING of `data`, produced by
    // `ApiResponse::singlePageMeta()` - five rows, never paginated, so the
    // degenerate single page every unpaginated list in this project uses.
    $response->assertJsonStructure([
        'success', 'data', 'message', 'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
    ])->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.from', 1)
        ->assertJsonPath('meta.to', 5);

    $isi = $response->json('data.persetujuan');

    expect($isi)->toHaveCount(5);

    // One entry per DDL member, IN THE DDL'S ORDER, so a client renders a
    // checklist with no holes and no client-side ordering rule.
    expect(array_column($isi, 'jenis'))->toBe(PersetujuanPdpJenis::nilai());

    $olehJenis = array_column($isi, null, 'jenis');

    // tri-state: true, false, and null for "no row"
    expect($olehJenis['syarat_ketentuan']['efektif'])->toBeTrue()
        ->and($olehJenis['syarat_ketentuan']['versi_dokumen'])->toBe('v1')
        ->and($olehJenis['syarat_ketentuan']['ip_address'])->toBe('198.51.100.30')
        ->and($olehJenis['kebijakan_privasi']['efektif'])->toBeFalse()
        ->and($olehJenis['kebijakan_privasi']['versi_dokumen'])->toBe('v2')
        // `disetujui_at` is a rule-(1) INSTANT, so it is ISO-8601 UTC.
        ->and($olehJenis['kebijakan_privasi']['disetujui_at'])->toEndWith('Z')
        ->and($olehJenis['pemasaran']['efektif'])->toBeNull()
        ->and($olehJenis['pemasaran']['versi_dokumen'])->toBeNull()
        ->and($olehJenis['pemasaran']['disetujui_at'])->toBeNull()
        ->and($olehJenis['pemasaran']['ip_address'])->toBeNull()
        ->and($olehJenis['komunikasi_tindak_lanjut']['efektif'])->toBeNull()
        // FIVE slots and no sixth, so a client can index the checklist by
        // position against a list it read at build time.
        ->and(array_column($isi, 'jenis'))->toHaveCount(5);

    // The keys are EXACTLY these six - a client that reads a key this response
    // does not publish is reading a hallucination.
    expect(array_keys($olehJenis['syarat_ketentuan']))->toBe([
        'jenis', 'efektif', 'versi_dokumen', 'disetujui_at', 'ip_address',
    ]);

    // It is the CALLER's answer and nobody else's: another account's rows are
    // not in it, and a second account sees its own nulls.
    $lain = pd47AkunPasien('Pasien Second PDP');

    pd47As($lain['user'])->getJson('/api/v1/pdp/persetujuan')
        ->assertOk()
        ->assertJsonPath('data.persetujuan.0.efektif', null)
        ->assertJsonPath('data.persetujuan.0.versi_dokumen', null);
});

test('POST /pdp/persetujuan records a decision, derives the instant and the address, and 409s nothing', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;

    $created = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v1',
        'disetujui' => true,
    ]);

    $created->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.persetujuan.jenis', $jenis)
        ->assertJsonPath('data.persetujuan.efektif', true)
        ->assertJsonPath('data.persetujuan.versi_dokumen', 'v1')
        ->assertJsonPath('data.persetujuan.ip_address', '127.0.0.1');

    // `disetujui_at` is DERIVED from the application clock, never from the
    // request: the column is `DATETIME NOT NULL` (:1141) and the instant the
    // decision was made is not a client-supplied fact.
    $baris = DB::table('persetujuan_pdp')->where('user_id', $userId)->sole();

    expect($baris->disetujui_at)->toStartWith('2026-03-11 10:00')
        ->and($baris->disetujui)->toBe(1)
        ->and($baris->ip_address)->toBe('127.0.0.1');

    // The idempotent re-send is a 200, not a 201 and not a 422, and it changes
    // nothing - asserted by comparing every column including `id`.
    $sebelum = (array) DB::table('persetujuan_pdp')->where('user_id', $userId)->sole();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v1',
        'disetujui' => true,
    ])->assertOk()
        ->assertJsonPath('data.persetujuan.efektif', true);

    expect((array) DB::table('persetujuan_pdp')->where('user_id', $userId)->sole())->toBe($sebelum);

    // The superseding version is a 201 and the effective answer flips.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v2',
        'disetujui' => false,
    ])->assertCreated()
        ->assertJsonPath('data.persetujuan.efektif', false)
        ->assertJsonPath('data.persetujuan.versi_dokumen', 'v2');

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2);

    // The client may not set the server-owned columns, and each is named rather
    // than ignored - a caller who believes they set `ip_address` is a caller
    // building a false audit trail.
    $prohibited = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v3',
        'disetujui' => true,
        'ip_address' => '10.0.0.1',
        'disetujui_at' => '2020-01-01 00:00:00',
        'user_id' => (int) $lain = 999999,
        'id' => 4242,
    ]);

    $prohibited->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.');

    $errors = $prohibited->json('errors');

    expect($errors)->toHaveKeys(['ip_address', 'disetujui_at', 'user_id', 'id'])
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2);

    // A `jenis` outside the ENUM and a `versi_dokumen` wider than the column are
    // both ordinary field errors, not a 500.
    $buruk = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => 'berbagi_data',
        'versi_dokumen' => Str::repeat('x', 21),
        'disetujui' => 'ya',
    ]);

    $buruk->assertStatus(422);

    expect($buruk->json('errors'))->toHaveKeys(['jenis', 'versi_dokumen', 'disetujui']);

    // An anonymous caller is 401 on both consent routes. TWO things have to be
    // undone first, and missing either one makes the "anonymous" request
    // authenticated - which is how a green 401 assertion can be testing nothing:
    //
    // - `pd47As()` installs the bearer token as a DEFAULT header on the shared
    //   test case, so `flushHeaders()`.
    // - `Illuminate\Auth\AuthManager` MEMOISES the resolved guard, and a
    //   `RequestGuard` memoises the user it resolved, so the guard has to be
    //   forgotten as well. This is the same `forgetGuards()` `pd47As()` itself
    //   calls, for the same reason, and the first run of this test answered 201
    //   because only the headers were cleared.
    app('auth')->forgetGuards();
    test()->flushHeaders();

    test()->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v3', 'disetujui' => true,
    ])->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');

    app('auth')->forgetGuards();
    test()->flushHeaders();
    test()->getJson('/api/v1/pdp/persetujuan')->assertStatus(401);
});

test('the two refusals answer 422 with two messages on one field, and the envelope is the standard one', function (): void {
    $akun = pd47AkunPasien();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;

    pd47ConsentRaw((int) $akun['user']->getKey(), $jenis, 'v2', false);

    // The collision, over HTTP.
    $tabrak = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v2',
        'disetujui' => true,
    ]);

    $tabrak->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.versi_dokumen', [
            'Persetujuan untuk versi dokumen ini sudah tercatat dan tidak dapat diubah.',
            'Tarik persetujuan dengan mengirim versi_dokumen yang lebih tinggi.',
        ]);

    // The envelope has EXACTLY three keys on a failure - no `data`, no `meta`.
    expect(array_keys($tabrak->json()))->toBe(['success', 'message', 'errors'])
        ->and(array_keys($tabrak->json('errors')))->toBe(['versi_dokumen']);

    // The out-of-order write, over HTTP.
    $lambat = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v1',
        'disetujui' => true,
    ]);

    $lambat->assertStatus(422)->assertJsonPath('errors.versi_dokumen', [
        'Versi dokumen ini lebih lama dari versi yang sudah tercatat.',
        'Kirim versi_dokumen yang lebih tinggi agar persetujuan yang baru berlaku.',
    ]);

    // Neither refusal wrote anything, and the effective answer is unchanged.
    expect(DB::table('persetujuan_pdp')->where('user_id', $akun['user']->getKey())->count())->toBe(1)
        ->and(app(PdpConsent::class)->effective($akun['user'], $jenis))->toBeFalse();
});

// =====================================================================
// Audit: the observers already cover this, so the controller writes nothing
// =====================================================================

test('consent and notifications are audited by the GLOBAL observer, once, with no second writer', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;

    // The registration is INSTALLED, read out of Eloquent's own listener table
    // rather than off a list somebody maintains. Both tables hang off `users`
    // (`:1143` and `:1046`) and `AuditScope` derives the closure from the DDL's
    // foreign keys, so both are in the person closure by construction.
    foreach ([PersetujuanPdp::class, Notifikasi::class] as $model) {
        foreach (['created', 'updated', 'deleted'] as $event) {
            expect(AuditObserverRegistrar::isAudited($model, $event))
                ->toBeTrue("{$model} is not observed for {$event}");
        }
    }

    expect(AuditObserverRegistrar::auditedModels())
        ->toContain(PersetujuanPdp::class)
        ->toContain(Notifikasi::class);

    // A fixture written through the MODEL proves the observer fires for this
    // table at all.
    $viaModel = pd47ConsentModel($userId, $jenis, 'v0', true);

    expect(DB::table('audit_log')
        ->where('tabel_target', 'persetujuan_pdp')
        ->where('record_id', (string) $viaModel->getKey())
        ->where('aksi', 'create')
        ->count())->toBe(1);

    // Now the endpoint. THREE requests, THREE decisions, THREE audit rows -
    // one per DECISION. The idempotent re-send (the fourth request) is not a
    // decision and writes no row, so the count is not the request count.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v1', 'disetujui' => true,
    ])->assertCreated();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v1', 'disetujui' => true,
    ])->assertOk();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v2', 'disetujui' => false,
    ])->assertCreated();

    $audit = DB::table('audit_log')->where('tabel_target', 'persetujuan_pdp')->orderBy('id')->get();

    // The fixture's one row, then ONE PER DECISION. Four requests, three of which
    // were decisions: the idempotent re-send is not a decision and writes no row,
    // so the count is not the request count - which is the whole point of making
    // the audit a record of decisions rather than of HTTP calls.
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('aksi')->all())->toBe(['create', 'create', 'create'])
        ->and($audit->last()->record_id)->toBe(
            (string) DB::table('persetujuan_pdp')
                ->where('user_id', $userId)
                ->where('versi_dokumen', 'v2')
                ->value('id')
        )
        ->and($audit->last()->user_id)->toBe($userId)
        ->and($audit->last()->endpoint)->toBe('api/v1/pdp/persetujuan');

    // The refusal wrote no row: `aksi` has no member for "rejected" and the
    // refusal is not a change to a consent.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v2', 'disetujui' => true,
    ])->assertStatus(422);

    expect(DB::table('audit_log')->where('tabel_target', 'persetujuan_pdp')->count())->toBe(3);
});

test('marking a notification read writes ONE audit row through the observer, and a second call writes none', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $id = pd47Notifikasi($userId, 'resep');

    // The fixture was written RAW, so it contributed no audit row and the count
    // below is attributable to the endpoint alone.
    expect(DB::table('audit_log')->where('tabel_target', 'notifikasi')->count())->toBe(0);

    pd47As($akun['user'])->putJson('/api/v1/notifikasi/'.$id.'/baca')
        ->assertOk()
        ->assertJsonPath('data.notifikasi.id', $id)
        ->assertJsonPath('data.notifikasi.tipe', 'resep')
        ->assertJsonPath('data.notifikasi.dibaca_at', '2026-03-11T10:00:00.000000Z');

    $audit = DB::table('audit_log')->where('tabel_target', 'notifikasi')->get();

    expect($audit)->toHaveCount(1)
        ->and($audit->first()->aksi)->toBe('update')
        ->and($audit->first()->record_id)->toBe((string) $id)
        ->and($audit->first()->user_id)->toBe($userId);

    // A second call is IDEMPOTENT: the first-read instant is the fact, and
    // re-stamping it would make "when did they see this" unknowable. It also
    // writes no second audit row, because no column changed.
    $sebelum = (array) DB::table('notifikasi')->where('id', $id)->sole();

    pd47As($akun['user'])->putJson('/api/v1/notifikasi/'.$id.'/baca')
        ->assertOk()
        ->assertJsonPath('data.notifikasi.dibaca_at', '2026-03-11T10:00:00.000000Z');

    expect((array) DB::table('notifikasi')->where('id', $id)->sole())->toBe($sebelum)
        ->and(DB::table('audit_log')->where('tabel_target', 'notifikasi')->count())->toBe(1);
});

// =====================================================================
// The referral gate, end to end through this todo's endpoint
// =====================================================================

test('a referral is 403 without consent and 201 once this endpoint has recorded it', function (): void {
    $akun = pd47DoctorAccount();
    $sesi = pd47Konsultasi($akun['pasien'], $akun['dokter']);
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $body = [
        'tipe' => 'surat_rujukan',
        'tanggal_mulai' => PD47_HARI,
        'tanggal_selesai' => PD47_HARI,
        'faskes_tujuan_id' => $akun['faskes'],
        'alasan_rujukan' => 'Perlu rujukan kardiologi.',
    ];

    // No consent row at all: V1, and every gate treats `null` as a refusal.
    pd47As($akun['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $body)
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(SuratKeterangan::query()->count())->toBe(0)
        ->and(Rujukan::query()->count())->toBe(0);

    // The PATIENT grants it through the endpoint todo 47 added - the gate is
    // checked against the patient's account, not the doctor's.
    pd47As($akun['pasienUser'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v1',
        'disetujui' => true,
    ])->assertCreated();

    pd47As($akun['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $body)
        ->assertCreated()
        ->assertJsonPath('data.surat_keterangan.tipe', 'surat_rujukan');

    expect(SuratKeterangan::query()->count())->toBe(1)
        ->and(Rujukan::query()->count())->toBe(1);

    // The patient WITHDRAWS it at a higher version, and the same request is
    // refused again. The gate reads the maximum, so a withdrawal is a
    // withdrawal - this is the end-to-end proof of the version rule.
    pd47As($akun['pasienUser'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v2',
        'disetujui' => false,
    ])->assertCreated()
        ->assertJsonPath('data.persetujuan.efektif', false);

    pd47As($akun['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $body)
        ->assertStatus(403);

    expect(Rujukan::query()->count())->toBe(1);
});

// =====================================================================
// The notification centre
// =====================================================================

test('the list pages, filters on unread, and reports the badge count independently of the page', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $lain = pd47AkunPasien('Pasien Notifikasi Lain');

    for ($i = 1; $i <= 5; $i++) {
        pd47Notifikasi($userId, 'sistem', $i <= 2, [
            'judul' => 'Notifikasi '.$i,
            'dibuat_at' => '2026-03-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT).' 10:00:00',
        ]);
    }

    // Another account's rows exist and must not appear, must not be counted,
    // and must not be marked.
    pd47Notifikasi((int) $lain['user']->getKey(), 'sistem');

    // The full list, newest first.
    $semua = pd47As($akun['user'])->getJson('/api/v1/notifikasi');

    $semua->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Daftar notifikasi berhasil dimuat.')
        ->assertJsonStructure(['success', 'data', 'message', 'meta' => [
            'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'unread',
        ]]);

    // `meta` is a TOP-LEVEL SIBLING, not nested inside `data`.
    expect($semua->json())->toHaveKeys(['success', 'data', 'message', 'meta'])
        ->and($semua->json('data'))->toHaveKeys(['notifikasi']);

    $semua->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 1)
        // THE BADGE COUNT. 3 unread, and it is the TOTAL - not the number of
        // rows on this page and not the number matching the filter.
        ->assertJsonPath('meta.unread', 3);

    expect(array_column($semua->json('data.notifikasi'), 'judul'))->toBe([
        'Notifikasi 5', 'Notifikasi 4', 'Notifikasi 3', 'Notifikasi 2', 'Notifikasi 1',
    ]);

    // The resource publishes EXACTLY these keys, so a client cannot be built on
    // a key the API does not send.
    expect(array_keys($semua->json('data.notifikasi.0')))->toBe([
        'id', 'judul', 'isi', 'tipe', 'tautan', 'payload', 'dibaca_at', 'dibuat_at',
    ]);

    // `?unread=true` filters, and the badge count is UNCHANGED - it is a
    // property of the account, not of the query.
    $belum = pd47As($akun['user'])->getJson('/api/v1/notifikasi?unread=true');

    $belum->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.unread', 3);

    expect($belum->json('data.notifikasi'))->toHaveCount(3);

    foreach ($belum->json('data.notifikasi') as $baris) {
        expect($baris['dibaca_at'])->toBeNull();
    }

    // `?unread=false` is the complement, and the badge count STILL does not move.
    $sudah = pd47As($akun['user'])->getJson('/api/v1/notifikasi?unread=false');

    $sudah->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.unread', 3);

    // PAGING, with the badge count still the total unread: this is the
    // distinction a per-page count would get wrong.
    $halaman = pd47As($akun['user'])->getJson('/api/v1/notifikasi?per_page=2&page=2');

    $halaman->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.from', 3)
        ->assertJsonPath('meta.to', 4)
        ->assertJsonPath('meta.unread', 3);

    expect($halaman->json('data.notifikasi'))->toHaveCount(2);

    // The per_page cap is the project's 100 and `page` must be a positive
    // integer; a client sending nonsense is a 422 with field errors, not a 500.
    pd47As($akun['user'])->getJson('/api/v1/notifikasi?page=0')
        ->assertStatus(422)
        ->assertJsonPath('errors.page', ['Nilai page tidak valid.']);

    pd47As($akun['user'])->getJson('/api/v1/notifikasi?unread=maybe')
        ->assertStatus(422)
        ->assertJsonPath('errors.unread', ['Nilai unread harus true atau false.']);

    // An empty inbox is a 200 with an empty list and a null range - not a 404
    // and not a 200 with a null list.
    $kosong = pd47AkunPasien('Pasien Kosong PDP');

    pd47As($kosong['user'])->getJson('/api/v1/notifikasi')
        ->assertOk()
        ->assertJsonPath('data.notifikasi', [])
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.unread', 0)
        ->assertJsonPath('meta.from', null)
        ->assertJsonPath('meta.to', null);
});

test('another account cannot read or mark another account notification, and the id is not disclosed', function (): void {
    $milik = pd47AkunPasien('Pemilik Notifikasi');
    $asing = pd47AkunPasien('Asing Notifikasi');
    $id = pd47Notifikasi((int) $milik['user']->getKey(), 'booking');

    // A 404, not a 403: over a sequential BIGINT key a 403 would confirm the
    // row exists, which is a cross-tenant existence oracle. The router's 404
    // for a non-numeric id and this 404 must be indistinguishable.
    pd47As($asing['user'])->putJson('/api/v1/notifikasi/'.$id.'/baca')
        ->assertStatus(404)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Resource not found.')
        ->assertJsonPath('errors', []);

    pd47As($asing['user'])->putJson('/api/v1/notifikasi/999999/baca')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Resource not found.');

    // A non-numeric id never reaches the controller at all.
    pd47As($asing['user'])->putJson('/api/v1/notifikasi/abc/baca')->assertStatus(404);

    // The row is UNTOUCHED, and the bulk route does not reach across either.
    expect(DB::table('notifikasi')->where('id', $id)->value('dibaca_at'))->toBeNull();

    pd47As($asing['user'])->putJson('/api/v1/notifikasi/baca-semua')->assertOk();

    expect(DB::table('notifikasi')->where('id', $id)->value('dibaca_at'))->toBeNull()
        ->and(DB::table('audit_log')->where('tabel_target', 'notifikasi')->count())->toBe(0);

    // The owner can, and the id is not echoed in the failure body of a stranger.
    pd47As($milik['user'])->putJson('/api/v1/notifikasi/'.$id.'/baca')
        ->assertOk()
        ->assertJsonPath('data.notifikasi.id', $id);
});

test('bulk mark-all stamps only the callers unread rows, reports the count, and is idempotent', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $lain = pd47AkunPasien('Asing Bulk');

    $sudahDibaca = pd47Notifikasi($userId, 'sistem', true, [
        'dibaca_at' => '2026-01-01 00:00:00',
    ]);
    $baruSatu = pd47Notifikasi($userId, 'booking');
    $baruDua = pd47Notifikasi($userId, 'chat');
    pd47Notifikasi((int) $lain['user']->getKey(), 'resep');

    $sebelum = (array) DB::table('notifikasi')->where('id', $sudahDibaca)->sole();

    pd47As($akun['user'])->putJson('/api/v1/notifikasi/baca-semua')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.ditandai', 2)
        ->assertJsonPath('message', 'Semua notifikasi berhasil ditandai sudah dibaca.');

    // The already-read row kept its ORIGINAL first-read instant rather than
    // being re-stamped, and the two unread ones carry the application clock.
    expect((array) DB::table('notifikasi')->where('id', $sudahDibaca)->sole())->toBe($sebelum)
        ->and(DB::table('notifikasi')->where('id', $baruSatu)->value('dibaca_at'))->toStartWith('2026-03-11 10:00')
        ->and(DB::table('notifikasi')->where('id', $baruDua)->value('dibaca_at'))->toStartWith('2026-03-11 10:00')
        ->and(DB::table('notifikasi')->where('user_id', $lain['user']->getKey())->value('dibaca_at'))->toBeNull()
        // One audit row per MARKED row, through the observer, and none for the
        // row that was already read.
        ->and(DB::table('audit_log')->where('tabel_target', 'notifikasi')->where('aksi', 'update')->count())->toBe(2);

    // A second run changes nothing and reports zero.
    $kedua = pd47As($akun['user'])->putJson('/api/v1/notifikasi/baca-semua');

    $kedua->assertOk()->assertJsonPath('data.ditandai', 0);

    expect(DB::table('audit_log')->where('tabel_target', 'notifikasi')->where('aksi', 'update')->count())->toBe(2);

    // An inbox with nothing in it is still a 200 with a count of zero, not a 404.
    pd47As(pd47AkunPasien('Kosong Bulk')['user'])->putJson('/api/v1/notifikasi/baca-semua')
        ->assertOk()
        ->assertJsonPath('data.ditandai', 0);
});

test('notifikasi is READ-ONLY from a clients perspective, and no request can write a column', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $routes = pd47Routes();

    // There is NO create route and NO delete route. The collection answers GET
    // and PUT only, and the two PUTs are the read stamps. Read off the router
    // rather than asserted by hand, so a sixth method on either path fails here.
    foreach (array_keys(pd47Routes()) as $uri) {
        if (! str_contains($uri, 'api/v1/notifikasi')) {
            continue;
        }

        [$metode] = explode(' ', $uri);

        expect($metode)->toBeIn(['GET', 'PUT']);

        if ($metode === 'PUT') {
            $adalahCap = str_ends_with($uri, '/baca');
            $adalahSemua = str_ends_with($uri, '/baca-semua');

            // `expect($a)->toBeTrue()->or($b)->toBeTrue()` was the first draft and
            // does not compose: `toBeTrue()` returns the EXPECTATION, `or()` is
            // not on it, and the failure was `Method "or" does not exist in
            // string`. Two named booleans read the same and compose.
            expect($adalahCap || $adalahSemua)->toBeTrue($uri.' is not a read stamp');
        }
    }

    // A POST to the collection is a ROUTER 405, not a 404 and not a 201. The
    // first draft of this test asserted 404 on the reasoning that the URI would
    // match nothing - and the router answered 405 with
    // `The POST method is not supported for route api/v1/notifikasi. Supported
    // methods: GET, HEAD`, which is a BETTER answer than the one asserted: it
    // names the methods that do exist, so a client learns the collection is
    // read-only and what it may do instead.
    pd47As($akun['user'])->postJson('/api/v1/notifikasi', [
        'judul' => 'Palsu', 'isi' => 'Palsu', 'tipe' => 'sistem', 'dibuat_at' => '2020-01-01 00:00:00',
    ])->assertStatus(405)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errors', []);

    // And the attempt created NOTHING: the inbox is still empty, so a caller that
    // tried to plant a notification did not manage it.
    expect(DB::table('notifikasi')->where('user_id', $userId)->count())->toBe(0);

    // Neither read stamp carries a body, so a client cannot set `dibaca_at` to
    // a time of its choosing, and cannot smuggle `dibuat_at`, `user_id`, `tipe`
    // or `payload` in either.
    $id = pd47Notifikasi($userId, 'sistem');

    pd47As($akun['user'])->putJson('/api/v1/notifikasi/'.$id.'/baca', [
        'dibaca_at' => '2020-01-01 00:00:00',
        'dibuat_at' => '2020-01-01 00:00:00',
        'user_id' => (int) $akun['user']->getKey() + 1,
        'tipe' => 'pemasaran',
        'judul' => 'Diubah',
        'dibaca' => false,
    ])->assertOk();

    $baris = (array) DB::table('notifikasi')->where('id', $id)->sole();

    expect($baris['dibaca_at'])->toStartWith('2026-03-11 10:00')
        ->and($baris['dibuat_at'])->toBe('2026-03-11 10:00:00')
        ->and($baris['tipe'])->toBe('sistem')
        ->and($baris['judul'])->toBe('Uji notifikasi PDP')
        ->and($baris['user_id'])->toBe($userId);

    // The bulk route is the same: a body on it changes nothing.
    pd47Notifikasi($userId, 'booking');

    pd47As($akun['user'])->putJson('/api/v1/notifikasi/baca-semua', [
        'dibaca_at' => '2020-01-01 00:00:00', 'user_id' => 1,
    ])->assertOk();

    expect(DB::table('notifikasi')->where('id', $id)->value('dibaca_at'))->toStartWith('2026-03-11 10:00');

    // The controller surface never names an audit table and never writes one:
    // `AuditLogWriter` is the only producer, and the architecture test in
    // `tests/Feature/Audit/ArchitectureTest.php` already enforces that. What is
    // asserted here is the positive counterpart - the rows arrive - so the
    // property is not resting on a single test in another todo.
    expect(DB::table('audit_log')->where('tabel_target', 'notifikasi')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('tabel_target', 'notifikasi')->distinct()->count('tabel_target'))->toBe(1);
});

// =====================================================================
// The guards, one at a time
// =====================================================================

test('a nurse and a courier are refused by permission:notifikasi.lihat, and the consent routes are ungated by design', function (): void {
    // `notifikasi.lihat` is granted to all FIVE roles and to no sixth, so
    // `perawat` and `kurir` - real `users.tipe` values (`:139`) that hold NO role
    // in `RbacCatalog::ROLES` - are permanently locked out of these three
    // routes. That is a real gap, it is asserted rather than described, and
    // the fix is a data change in `RbacCatalog` plus a re-seed, not a code
    // change. The alternative - dropping the guard - would make the only
    // permission code naming this action decorative, and would put the allowlist
    // in a controller where it could not be revoked.
    foreach (['perawat', 'kurir'] as $tipe) {
        $user = pd47User('Peran '.$tipe, $tipe);

        expect($user->getKey())->toBeInt();

        pd47As($user)->getJson('/api/v1/notifikasi')->assertStatus(403)
            ->assertJsonPath('message', 'This action is unauthorized.');

        pd47As($user)->putJson('/api/v1/notifikasi/1/baca')->assertStatus(403);
        pd47As($user)->putJson('/api/v1/notifikasi/baca-semua')->assertStatus(403);

        // The CONSENT routes are not gated, and the same two account types may
        // read and record their OWN consent. Consent belongs to a person
        // whoever that person is at work, and `persetujuan_pdp.user_id` (`:1136`)
        // is a `users` foreign key, not a `pasien` one.
        pd47As($user)->getJson('/api/v1/pdp/persetujuan')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        pd47As($user)->postJson('/api/v1/pdp/persetujuan', [
            'jenis' => 'syarat_ketentuan', 'versi_dokumen' => 'v1', 'disetujui' => true,
        ])->assertCreated();
    }

    // The four roles that DO hold `notifikasi.lihat` pass, and `dokter` and
    // `apoteker` are on the list because the catalogue puts them there - a
    // doctor's booking and prescription notifications are real.
    foreach (['pasien', 'dokter', 'apoteker', 'admin', 'superadmin'] as $role) {
        $user = pd47User('Pemegang '.$role, $role, $role);

        expect(RbacCatalog::permissionsFor($role))->toContain('notifikasi.lihat');

        pd47As($user)->getJson('/api/v1/notifikasi')->assertOk();
    }

    // `pdp.kelola` is granted to `admin` and `superadmin` and is used by NO
    // route. That is deliberate: recording a data subject's consent is the data
    // subject's act, and a route that let an admin write it would be a
    // compliance defect wearing a permission code. The catalogue keeps the code
    // for the future admin READ surface; this todo declines to invent it.
    expect(RbacCatalog::ROLE_PERMISSIONS['admin'])->toContain('pdp.kelola')
        ->and(RbacCatalog::ROLE_PERMISSIONS['superadmin'])->toContain('pdp.kelola')
        ->and(RbacCatalog::isPermission('pdp.kelola'))->toBeTrue();

    // An anonymous caller is 401 on all five, which is the guard ORDER rather
    // than the permission: "send a token" and "you may not do this" are
    // different answers. BOTH the default headers and the memoised guard have to
    // be undone, for the reason the other anonymous block in this file spells
    // out - `pd47As()` leaves the bearer token as a default header and
    // `AuthManager` caches the user it resolved.
    foreach ([
        ['GET', '/api/v1/pdp/persetujuan'],
        ['POST', '/api/v1/pdp/persetujuan'],
        ['GET', '/api/v1/notifikasi'],
        ['PUT', '/api/v1/notifikasi/1/baca'],
        ['PUT', '/api/v1/notifikasi/baca-semua'],
    ] as [$method, $uri]) {
        app('auth')->forgetGuards();
        test()->flushHeaders();

        test()->json($method, $uri)
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('errors', []);
    }
});

// =====================================================================
// The notification service
// =====================================================================

test('the five events write the right tipe, tautan and payload, and push is logged per ACTIVE device', function (): void {
    $akun = pd47AkunPasien('Penerima Notifikasi');
    $user = $akun['user'];
    $userId = (int) $user->getKey();
    $service = app(NotificationService::class);

    // Three devices: an active one with a token, an active one WITHOUT a token
    // (`:195` is nullable), and an INACTIVE one. Only the first may be pushed to.
    $aktif = pd47Perangkat($userId, ['platform' => 'android']);
    $tanpaToken = pd47Perangkat($userId, ['platform' => 'ios', 'fcm_token' => null]);
    $nonaktif = pd47Perangkat($userId, ['platform' => 'web', 'aktif' => 0]);

    $log = pd47RekamLog();

    $booking = $service->bookingDibuat($user, 1001);
    $batal = $service->bookingDibatalkan($user, 1001, 'Jadwal berubah.');
    $bayar = $service->pembayaranSelesai($user, 2002);
    $resep = $service->resepSiap($user, 3003);
    $chat = $service->pesanBaru($user, 4004, 5);

    // Every row is the shape the DDL allows, and the `tipe` is a member of the
    // seven-value ENUM at :1041.
    $baris = Notifikasi::query()->where('user_id', $userId)->orderBy('id')->get();

    expect($baris)->toHaveCount(5)
        ->and($baris->pluck('tipe')->all())->toBe([
            'booking', 'booking', 'pembayaran', 'resep', 'chat',
        ]);

    foreach ($baris as $satu) {
        expect(NotifikasiTipe::nilai())->toContain($satu->tipe)
            ->and($satu->judul)->not->toBeEmpty()
            ->and($satu->isi)->not->toBeEmpty();
    }

    // `tautan` is the API path of the record the notification is about, and
    // `payload` carries the identifier as JSON - both, so a client can route on
    // `tautan` and read the identifier without parsing a path.
    expect($booking->tautan)->toBe('/api/v1/booking/1001')
        ->and($booking->payload)->toBe(['booking_id' => 1001])
        ->and($batal->tautan)->toBe('/api/v1/booking/1001')
        ->and($batal->payload)->toBe(['booking_id' => 1001, 'alasan' => 'Jadwal berubah.'])
        ->and($bayar->tautan)->toBe('/api/v1/invoice/2002')
        ->and($bayar->payload)->toBe(['invoice_id' => 2002])
        ->and($resep->tautan)->toBe('/api/v1/resep/3003')
        ->and($resep->payload)->toBe(['resep_id' => 3003])
        ->and($chat->tautan)->toBe('/api/v1/konsultasi/4004/chat')
        ->and($chat->payload)->toBe(['konsultasi_id' => 4004, 'pengirim_user_id' => 5])
        ->and($booking->dibaca_at)->toBeNull()
        ->and((int) $booking->user_id)->toBe($userId);

    // FIVE events, ONE active device with a token, therefore FIVE pushes - and
    // the delivery state is LOGGED rather than stored, because `notifikasi` has
    // no delivery column and no channel column (both absences asserted above).
    //
    // The message is matched for EQUALITY, not `str_contains`: the three push
    // messages share a prefix, and a substring filter would count the
    // confirmation line as a push and report 10 where there are 5.
    $terkirim = $log->dengan('notifikasi.push');

    expect($terkirim)->toHaveCount(5);

    foreach ($terkirim as $satu) {
        expect($satu['konteks']['token'] ?? null)->toBeString()
            ->and($satu['konteks']['notifikasi_id'] ?? null)->toBeInt()
            // The log line says IN ITS OWN BODY that nothing went over the wire,
            // so the line is evidence rather than a claim.
            ->and($satu['konteks']['dikirim'] ?? null)->toBeFalse()
            // The dispatcher's contract carries the NOTIFICATION's fields, so
            // `platform` is on the confirmation line below rather than here -
            // asserted there, against the device id, so the two lines together
            // name the token and the platform that token belongs to.
            ->and(array_keys($satu['konteks']))->toBe([
                'notifikasi_id', 'token', 'judul', 'isi', 'tipe', 'tautan', 'dikirim', 'catatan',
            ]);
    }

    // Five events x TWO unusable devices (no token, and inactive) = TEN skips,
    // and every one is named rather than silently dropped. The two reasons are
    // compared as a SET with `toEqualCanonicalizing` because the first skip of
    // each event depends on the order `user_devices` returns its rows, and an
    // order-sensitive assertion would be a test of MySQL's index order.
    $lewat = $log->dengan('notifikasi.push.lewat');
    $alasan = array_values(array_unique(array_map(
        static fn (array $satu): mixed => $satu['konteks']['alasan'] ?? null,
        $lewat,
    )));

    expect($lewat)->toHaveCount(10)
        ->and($alasan)->toEqualCanonicalizing(['tidak ada fcm_token', 'perangkat tidak aktif']);

    // And five confirmations, one per push, naming the device and the platform so
    // "did patient 42's phone get it" is answerable from a log search.
    $konfirmasi = $log->dengan('notifikasi.push.terkirim');

    expect($konfirmasi)->toHaveCount(5);

    foreach ($konfirmasi as $satu) {
        expect($satu['konteks']['perangkat_id'] ?? null)->toBe($aktif)
            ->and($satu['konteks']['user_id'] ?? null)->toBe($userId)
            ->and($satu['konteks']['platform'] ?? null)->toBe('android');
    }

    // Nothing outside the three push messages was logged, so the recorder is
    // measuring this service and not the framework.
    $pesanUniq = array_values(array_unique(array_map(
        static fn (array $satu): string => $satu['pesan'],
        $log->baris,
    )));

    expect($log->dengan('notifikasi.push'))->toHaveCount(5)
        ->and($pesanUniq)->toEqualCanonicalizing(['notifikasi.push', 'notifikasi.push.terkirim', 'notifikasi.push.lewat']);

    // The service wrote through the MODEL, so the observer audited every one of
    // the five - and NOT through a controller.
    expect(DB::table('audit_log')->where('tabel_target', 'notifikasi')->where('aksi', 'create')->count())->toBe(5)
        ->and((int) $tanpaToken)->toBeInt()
        ->and((int) $nonaktif)->toBeInt()
        ->and(DB::table('user_devices')->where('user_id', $userId)->count())->toBe(3);

    // The five deep links resolve to routes this application actually registers
    // at their own prefixes, or to the nearest real one. Asserted as a SET so a
    // typo in a path cannot ship.
    expect(collect($baris)->pluck('tautan')->unique()->values()->all())->toBe([
        '/api/v1/booking/1001', '/api/v1/invoice/2002', '/api/v1/resep/3003', '/api/v1/konsultasi/4004/chat',
    ]);
});

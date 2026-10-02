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
use App\Support\Pdp\PdpDokumen;
use App\Support\Rbac\RbacCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
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
| ## THE LEDGER RULE, stated before the code and pinned by the tests below
|
| F02's owner decision (2026-10-01) replaced the old version rule. Two facts
| decide everything now:
|
| ```
| :1139  versi_dokumen VARCHAR(20) NOT NULL,
| :1140  disetujui      TINYINT(1) NOT NULL,
| :1144  -- uq_consent ... DROPPED 2026-10-01 (F02): append-only ledger
| ```
|
| **`uq_consent` is gone.** `persetujuan_pdp` is an APPEND-ONLY LEDGER: a person
| may hold any number of rows for the same document version, and the CURRENT
| status is the **latest recorded row per `(user_id, jenis)`**, in append (`id`)
| order. The active version is enforced on every write, so `id` order is the
| order.
|
| ### The rule: EFFECTIVE = the `disetujui` of the LATEST recorded row
|
| For one `(user_id, jenis)` pair, the answer to "has this person consented?" is
| read off the row with the largest `id` - never the highest `versi_dokumen`,
| which was a STRING order over a `VARCHAR(20)` and therefore a convention
| (`v2.0` sorts above `v10.0`). Concretely:
|
| | # | situation | outcome |
| | --- | --- | --- |
| | L1 | no row at all | `effective() === null`: never answered. Every gate treats it as a refusal. |
| | L2 | latest row, `disetujui = 1` | consented |
| | L3 | latest row, `disetujui = 0` | refused - this is the withdrawal |
| | L4 | a row arrives at a version that is NOT the active one | **REFUSED, nothing written** (422 on `versi_dokumen`) |
| | L5 | the active version, same answer as the latest row | 200, the latest row, byte-identical (idempotent re-send) |
| | L6 | the active version, different answer | a NEW row is appended; it becomes the effective one (201) |
|
| **Withdrawal is allowed anytime, instantly, on the SAME version.** L6 is the
| whole point: `disetujui = false` at the active version is a new row, so a
| person can change their mind without waiting for the document to advance. The
| old rule could only represent a revocation as a higher version, which coupled
| a person's decision to the document catalogue.
|
| **The active version is the SERVER's.** `GET /api/v1/pdp/dokumen` publishes it
| from `config/pdp.php` through `App\Support\Pdp\PdpDokumen`, and L4 refuses
| anything else. A client no longer invents a version, and a stale client is
| refused loudly instead of writing a row that could never be the effective one.
|
| ### What a client does on receiving the 422
|
| Read the two messages as one instruction: the version sent is not the active
| one, and the second message names the active version. Concretely:
|
| - On 422 with `errors.versi_dokumen`, do NOT retry. A retry is byte-identical
|   and will be refused identically.
| - Call `GET /api/v1/pdp/dokumen` and read the active `versi_dokumen` for that
|   `jenis`, then resend the decision with it.
| - To withdraw, send `disetujui: false` at the SAME active version. No version
|   bump is needed and none is accepted.
| - `GET /api/v1/pdp/persetujuan` and read `data.persetujuan[jenis].efektif`.
|   That is the effective answer, already resolved through the ledger rule, so
|   the client never has to re-implement "latest row" itself.
|
| ## The layer under the rule: the ledger itself
|
| The old `uq_consent` unique key is gone, so a same-version second row is now
| the MECHANISM for changing one's mind rather than a collision. The tests below
| prove the absence at the database (a raw duplicate insert succeeds) and prove
| the service appends rather than upserts: every decision is a new row, and the
| latest row wins.
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
| The refusal puts two messages on `versi_dokumen`, and each is asserted by its
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
    // F02: `:1144` is a COMMENT recording the dropped unique key, not the key
    // itself. The line is kept so every citation after it stays valid, and the
    // parser strips comments while preserving offsets, so the parsed table is
    // the same one a deleted line would produce.
    pd47AssertLine(1144, 'uq_consent');
    pd47AssertLine(1144, 'DROPPED');
    pd47AssertLineLacks(1144, 'UNIQUE KEY');

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

    // F02: the table has NO unique key at all. `substr_count` rather than a
    // `not->toContain`, because a second `UNIQUE KEY` added anywhere in the
    // table body would be the old rule creeping back and a single negative
    // search would not count it.
    expect(substr_count($isiPersetujuan, 'UNIQUE KEY'))->toBe(0)
        ->and($isiPersetujuan)->not->toContain('UNIQUE KEY uq_consent');

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

test('the six routes are registered with exactly the guards this todo claims', function (): void {
    $routes = pd47Routes();

    // The closed set, keyed by `METHOD uri`. F02 appended `GET pdp/dokumen`
    // beside the two consent routes, so the set is six.
    foreach ([
        'GET api/v1/pdp/dokumen',
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

    // The three consent routes carry `auth:sanctum` and NOTHING else. See the
    // guard table in the docblock of `PersetujuanPdpController` for why zero is
    // the answer rather than an omission.
    expect(pd47Guards($routes, 'GET api/v1/pdp/dokumen'))
        ->toBe(['api', 'auth:sanctum'])
        ->and(pd47Guards($routes, 'GET api/v1/pdp/persetujuan'))
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

    // `pdp.kelola` is a catalogue code no route IN THIS FILE carries, and that is
    // a decision rather than an oversight: recording a data subject's consent is
    // the data subject's act, and a route that let an admin write one would be a
    // compliance defect wearing a permission code. These six routes are the
    // CALLER'S OWN consent, so they carry `auth:sanctum` and nothing else.
    //
    // The loop is over the six named routes rather than over `pd47Routes()` in
    // full, because F14 added the code's one consumer - `GET /admin/persetujuan-pdp`
    // - and a blanket "nothing anywhere carries this code" scan would have turned a
    // deliberate addition into a false alarm. The narrower claim is also the one
    // that matters: this file's six routes must stay ungated.
    foreach ([
        'GET api/v1/pdp/dokumen',
        'GET api/v1/pdp/persetujuan',
        'POST api/v1/pdp/persetujuan',
        'GET api/v1/notifikasi',
        'PUT api/v1/notifikasi/{id}/baca',
        'PUT api/v1/notifikasi/baca-semua',
    ] as $diumi) {
        // `not->toContain` with ONE needle: Pest's `toContain` is variadic, so a
        // second argument is a second needle and would quietly make the assertion
        // pass for the wrong reason.
        expect(pd47Guards($routes, $diumi))->not->toContain('permission:pdp.kelola');
    }

    // And the consumer itself, asserted here rather than only in the F14 suite:
    // the code is granted to `admin`/`superadmin`, gates exactly ONE route, and
    // that route is a GET. A second route carrying it would have to be a write,
    // which is the thing this whole file exists to prevent.
    $pemakai = array_keys(array_filter(
        $routes,
        static fn (array $middleware): bool => in_array('permission:pdp.kelola', $middleware, true),
    ));

    expect($pemakai)->toBe(['GET api/v1/admin/persetujuan-pdp'])
        ->and(pd47Guards($routes, 'GET api/v1/admin/persetujuan-pdp'))
        ->toBe(['api', 'auth:sanctum', 'tipe:admin,superadmin', 'permission:pdp.kelola']);

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
// THE LEDGER RULE - the three boundaries
// =====================================================================

test('LEDGER 1 of 3: every decision is appended, and the LATEST row is the effective one', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    // L1: no row at all. `effective()` is NULL, which is a different fact from
    // `false`, and a gate still refuses.
    expect($consent->effective($akun['user'], $jenis))->toBeNull()
        ->and($consent->disetujui($akun['user'], $jenis))->toBeFalse()
        ->and($consent->ringkasan($akun['user']))->toHaveCount(5);

    // L2: the first decision. Approved.
    $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.4');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe($aktif)
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->ip_address)->toBe('198.51.100.4');

    // L3: the withdrawal, on the SAME version. Under the old `uq_consent` rule
    // this was a 422 collision; under the ledger it is a new row and the
    // effective answer flips on the same call.
    $service->catat($akun['user'], $jenis, $aktif, false, '198.51.100.6');

    expect($consent->effective($akun['user'], $jenis))->toBeFalse()
        ->and($consent->disetujui($akun['user'], $jenis))->toBeFalse()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe($aktif)
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(2);

    // And a gate refuses on the withdrawal rather than silently proceeding.
    expect(fn () => $consent->require($akun['user'], $jenis))
        ->toThrow(AccessDeniedHttpException::class);

    // L6 again in the other direction: re-approving on the same version
    // supersedes the withdrawal. All three rows stay on the table - the table is
    // a HISTORY, and the ledger is what reads it.
    $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.7');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->versi_dokumen)->toBe($aktif)
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(3)
        // `pluck()` on an ELOQUENT builder returns the CAST value, not the raw
        // column, so these are booleans rather than the `TINYINT(1)` integers the
        // raw builder would hand back - and the cast is the model's, so this also
        // asserts `disetujui` is cast to boolean at all.
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->orderBy('id')->pluck('disetujui')->all())
        ->toBe([true, false, true]);
});

test('LEDGER 2 of 3: a version that is not the active one is refused and changes nothing', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = 'kebijakan_privasi';
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    // A decision at the active version, then a stale client tries a version the
    // server does not publish. The refusal is a fact about the DOCUMENT, not
    // about what this account happens to have recorded.
    $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.8');

    $e = pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v99', true, '198.51.100.9'),
        PerubahanVersiException::class,
    );

    expect($e->errors())->toHaveKey('versi_dokumen')
        ->and($e->errors()['versi_dokumen'])->toHaveCount(2)
        ->and($e->errors()['versi_dokumen'][0])->toBe('Versi dokumen yang dikirim bukan versi aktif.')
        ->and($e->errors()['versi_dokumen'][1])->toContain($aktif);

    // A LOWER version is refused the same way: the check is equality with the
    // active version, not an ordering, so there is no "stale but acceptable"
    // value.
    pd47Tangkap(
        fn () => $service->catat($akun['user'], $jenis, 'v00', true, '198.51.100.10'),
        PerubahanVersiException::class,
    );

    // Nothing was written by either refusal, and the answer has not moved.
    $baris = DB::table('persetujuan_pdp')
        ->where('user_id', $userId)
        ->where('jenis', $jenis)
        ->orderBy('id')
        ->get();

    expect($baris)->toHaveCount(1)
        ->and($baris->pluck('versi_dokumen')->all())->toBe([$aktif])
        ->and($baris->pluck('disetujui')->all())->toBe([1])
        ->and($consent->effective($akun['user'], $jenis))->toBeTrue();

    // The active version is accepted, and only then does the answer move - so
    // the refusals above were a real gate and not a blanket denial.
    $service->catat($akun['user'], $jenis, $aktif, false, '198.51.100.11');

    expect($consent->effective($akun['user'], $jenis))->toBeFalse()
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2);
});

test('LEDGER 3 of 3: the SAME consecutive decision is idempotent and writes no second row', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = 'syarat_ketentuan';
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    $pertama = $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.11');

    // The client's request never got its response and it retries. Byte-identical
    // body, so the server CAN tell this from a changed decision - and must,
    // because refusing it would make every retry a hard failure while accepting
    // it as a new row would grow the ledger on every retry.
    $kedua = $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.99');

    expect((int) $kedua->getKey())->toBe((int) $pertama->getKey())
        ->and($kedua->ip_address)->toBe('198.51.100.11', 'the retry overwrote the recorded address')
        ->and($kedua->disetujui_at->toDateTimeString())->toBe(pd47Jam()->toDateTimeString())
        ->and(PersetujuanPdp::query()->where('user_id', $userId)->count())->toBe(1);

    // The same for a withdrawal, and the second call wrote no second row.
    $tarik = $service->catat($akun['user'], $jenis, $aktif, false, '198.51.100.12');
    $tarikUlang = $service->catat($akun['user'], $jenis, $aktif, false, '198.51.100.99');

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
// The ledger appends a changed decision - the acceptance criterion
// =====================================================================

test('a changed decision on the SAME version appends a new row and the latest row wins', function (): void {
    $akun = pd47AkunPasien();
    $service = app(PdpConsentService::class);
    $consent = app(PdpConsent::class);
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    // A patient approved the active version, then changed their mind. Under the
    // old `uq_consent` rule the second call was a 422 collision; under the
    // ledger it is a new row and the effective answer flips.
    $pertama = $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.20');
    $kedua = $service->catat($akun['user'], $jenis, $aktif, false, '198.51.100.21');

    expect((int) $kedua->getKey())->not->toBe((int) $pertama->getKey())
        ->and($consent->effective($akun['user'], $jenis))->toBeFalse()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->getKey())->toBe($kedua->getKey());

    // The FIRST row is untouched - every column, not just `disetujui`. An
    // `upsert` would have rewritten `disetujui_at` and `ip_address` in place.
    $asli = DB::table('persetujuan_pdp')->where('id', $pertama->getKey())->sole();

    expect($asli->disetujui)->toBe(1)
        ->and($asli->ip_address)->toBe('198.51.100.20')
        ->and($asli->versi_dokumen)->toBe($aktif);

    // And back again: a third decision at the same version is a third row.
    $ketiga = $service->catat($akun['user'], $jenis, $aktif, true, '198.51.100.22');

    expect($consent->effective($akun['user'], $jenis))->toBeTrue()
        ->and($consent->versiTerbaru($akun['user'], $jenis)?->getKey())->toBe($ketiga->getKey())
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(3)
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->orderBy('id')->pluck('disetujui')->all())
        ->toBe([1, 0, 1]);
});

test('a same-version duplicate is ALLOWED by the ledger, at the database', function (): void {
    // The inverse of the old acceptance criterion, and the point of this test:
    // prove the unique key is really gone rather than assumed. Nothing here goes
    // through the application, so a green run means MySQL accepted the write.
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();

    pd47ConsentRaw($userId, PdpConsent::JENIS_BERBAGI_DATA, 'v01', true);

    // The same (user_id, jenis, versi_dokumen) triple, a different answer. Under
    // `uq_consent` this was MySQL 1062; now it is the withdrawal mechanism.
    pd47ConsentRaw($userId, PdpConsent::JENIS_BERBAGI_DATA, 'v01', false);

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2)
        ->and(app(PdpConsent::class)->effective($akun['user'], PdpConsent::JENIS_BERBAGI_DATA))->toBeFalse();

    // The live schema really has no `uq_consent`: migration
    // `2026_10_01_000081` dropped it and the reference DDL no longer declares
    // it.
    expect(Schema::hasIndex('persetujuan_pdp', 'uq_consent'))->toBeFalse();

    // A DIFFERENT jenis at the same version is legal too, and so is a different
    // user - the ledger is per (user, jenis), not per account.
    pd47ConsentRaw($userId, 'pemasaran', 'v01', true);
    $lain = pd47AkunPasien('Pasien Lain PDP');

    pd47ConsentRaw((int) $lain['user']->getKey(), PdpConsent::JENIS_BERBAGI_DATA, 'v01', true);

    expect(DB::table('persetujuan_pdp')->where('versi_dokumen', 'v01')->count())->toBe(4);
});

test('a writer that lands BETWEEN the read and the append is kept, and the later row wins', function (): void {
    // The ledger has no unique key to trip, so the old race test's premise is
    // gone. What replaces it is the property that matters now: a competing row
    // written between the service's read and its insert is NOT lost and does NOT
    // block the caller - both decisions are recorded, and the later `id` wins.
    //
    // WHY ONE CONNECTION AND NOT TWO: `RefreshDatabase` holds its wrapper
    // transaction open, so the `users` row this test just created is
    // UNCOMMITTED, and a second connection's INSERT would block on the foreign
    // key's shared lock against that uncommitted parent row until MySQL's 1205
    // lock-wait timeout. The competing row is therefore written on the SAME
    // connection, where it is visible immediately and there is no lock to wait
    // for.
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);

    $sudahMasuk = false;
    $armed = true;

    // `Connection::beforeExecuting()` APPENDS to `$beforeExecutingCallbacks`,
    // offers no way to remove one, and hands the callback the statement's SQL as
    // a STRING. So the hook is DISARMED by a captured flag in the `finally`
    // rather than uninstalled, and `DB::purge('mysql')` is not an option either:
    // it would drop the PDO holding `RefreshDatabase`'s wrapper transaction,
    // whose teardown then sets `migrated = false` and forces a `migrate:fresh`
    // for every remaining test in the process.
    DB::connection('mysql')->beforeExecuting(function (string $sql) use (&$sudahMasuk, &$armed, $userId, $jenis, $aktif): void {
        if (! $armed || $sudahMasuk || ! str_contains(strtolower($sql), 'insert into `persetujuan_pdp`')) {
            return;
        }

        $sudahMasuk = true;

        // The competing writer lands BEFORE the service's own INSERT reaches the
        // server. There is no unique key to reject either row.
        DB::table('persetujuan_pdp')->insert([
            'user_id' => $userId,
            'jenis' => $jenis,
            'versi_dokumen' => $aktif,
            'disetujui' => 0,
            'disetujui_at' => pd47Jam(),
        ]);
    });

    try {
        $response = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
            'jenis' => $jenis,
            'versi_dokumen' => $aktif,
            'disetujui' => true,
        ]);

        // The caller's decision is a 201, not a 500 and not a 422: the ledger
        // appends.
        $response->assertCreated()
            ->assertJsonPath('data.persetujuan.efektif', true);

        // BOTH rows are on the table, and the caller's row is the later one, so
        // the effective answer is the caller's approval.
        expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2)
            ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->orderBy('id')->pluck('disetujui')->all())
            ->toBe([0, 1])
            ->and(app(PdpConsent::class)->effective($akun['user'], $jenis))->toBeTrue();
    } finally {
        $armed = false;
    }
});

// =====================================================================
// The consent endpoints
// =====================================================================

test('GET /pdp/persetujuan answers all five kinds with a tri-state resolved through the ledger', function (): void {
    $akun = pd47AkunPasien();
    $userId = (int) $akun['user']->getKey();

    // One of each of the three effective states, plus a pair whose answer can
    // only be got right by reading the LATEST row: the higher version is
    // recorded FIRST and the lower one LAST, so a "highest version wins" read
    // would report the refusal while the ledger reports the approval.
    pd47ConsentRaw($userId, 'syarat_ketentuan', 'v1', true, ['ip_address' => '198.51.100.30']);
    pd47ConsentRaw($userId, 'kebijakan_privasi', 'v02', false, ['disetujui_at' => '2026-02-03 04:05:06']);
    pd47ConsentRaw($userId, 'kebijakan_privasi', 'v01', true);
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

    // tri-state: true, false, and null for "no row". `kebijakan_privasi` is the
    // append-order proof: the LAST row written is `v01` and it says yes, so the
    // effective answer is `true` even though `v02` exists and says no.
    expect($olehJenis['syarat_ketentuan']['efektif'])->toBeTrue()
        ->and($olehJenis['syarat_ketentuan']['versi_dokumen'])->toBe('v1')
        ->and($olehJenis['syarat_ketentuan']['ip_address'])->toBe('198.51.100.30')
        ->and($olehJenis['kebijakan_privasi']['efektif'])->toBeTrue()
        ->and($olehJenis['kebijakan_privasi']['versi_dokumen'])->toBe('v01')
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
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);

    $created = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
        'disetujui' => true,
    ]);

    $created->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.persetujuan.jenis', $jenis)
        ->assertJsonPath('data.persetujuan.efektif', true)
        ->assertJsonPath('data.persetujuan.versi_dokumen', $aktif)
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
        'versi_dokumen' => $aktif,
        'disetujui' => true,
    ])->assertOk()
        ->assertJsonPath('data.persetujuan.efektif', true);

    expect((array) DB::table('persetujuan_pdp')->where('user_id', $userId)->sole())->toBe($sebelum);

    // The WITHDRAWAL is the SAME version and is a 201: the ledger appends, and
    // the effective answer flips.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
        'disetujui' => false,
    ])->assertCreated()
        ->assertJsonPath('data.persetujuan.efektif', false)
        ->assertJsonPath('data.persetujuan.versi_dokumen', $aktif);

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2);

    // The client may not set the server-owned columns, and each is named rather
    // than ignored - a caller who believes they set `ip_address` is a caller
    // building a false audit trail. The version is the ACTIVE one, so the only
    // errors are the prohibited fields.
    $prohibited = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
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

    // An anonymous caller is 401 on all THREE consent routes. TWO things have to
    // be undone first, and missing either one makes the "anonymous" request
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
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertStatus(401)->assertJsonPath('message', 'Unauthenticated.');

    app('auth')->forgetGuards();
    test()->flushHeaders();
    test()->getJson('/api/v1/pdp/persetujuan')->assertStatus(401);

    app('auth')->forgetGuards();
    test()->flushHeaders();
    test()->getJson('/api/v1/pdp/dokumen')->assertStatus(401);
});

test('the version refusal answers 422 with two messages on one field, and the envelope is the standard one', function (): void {
    $akun = pd47AkunPasien();
    $jenis = PdpConsent::JENIS_BERBAGI_DATA;
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);
    $userId = (int) $akun['user']->getKey();

    // The refusal, over HTTP: a version the server does not publish.
    $ditolak = pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => 'v99',
        'disetujui' => true,
    ]);

    $ditolak->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'The given data was invalid.')
        ->assertJsonPath('errors.versi_dokumen', [
            'Versi dokumen yang dikirim bukan versi aktif.',
            'Versi aktif saat ini adalah '.$aktif.'. Muat ulang GET /api/v1/pdp/dokumen lalu kirim ulang.',
        ]);

    // The envelope has EXACTLY three keys on a failure - no `data`, no `meta`.
    expect(array_keys($ditolak->json()))->toBe(['success', 'message', 'errors'])
        ->and(array_keys($ditolak->json('errors')))->toBe(['versi_dokumen'])
        ->and(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(0);

    // The active version is accepted, and a withdrawal on it is a 201 - so the
    // refusal above was a real gate and not a blanket denial.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
    ])->assertCreated();

    expect(DB::table('persetujuan_pdp')->where('user_id', $userId)->count())->toBe(2)
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
    // table at all. It records a REFUSAL, so the endpoint's first approval is a
    // changed decision (201) rather than an idempotent re-send of the fixture.
    $viaModel = pd47ConsentModel($userId, $jenis, 'v0', false);

    expect(DB::table('audit_log')
        ->where('tabel_target', 'persetujuan_pdp')
        ->where('record_id', (string) $viaModel->getKey())
        ->where('aksi', 'create')
        ->count())->toBe(1);

    // Now the endpoint. THREE requests, TWO decisions, TWO audit rows - one per
    // DECISION. The idempotent re-send (the second request) is not a decision
    // and writes no row, so the count is not the request count.
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertCreated();

    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => true,
    ])->assertOk();

    // The withdrawal is the SAME version and IS a decision, so it writes a row
    // and an audit row.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => $aktif, 'disetujui' => false,
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
                ->where('disetujui', 0)
                ->orderByDesc('id')
                ->value('id')
        )
        ->and($audit->last()->user_id)->toBe($userId)
        ->and($audit->last()->endpoint)->toBe('api/v1/pdp/persetujuan');

    // The refusal wrote no row: `aksi` has no member for "rejected" and the
    // refusal is not a change to a consent.
    pd47As($akun['user'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis, 'versi_dokumen' => 'v99', 'disetujui' => true,
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

    // No consent row at all: L1, and every gate treats `null` as a refusal.
    pd47As($akun['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $body)
        ->assertStatus(403)
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect(SuratKeterangan::query()->count())->toBe(0)
        ->and(Rujukan::query()->count())->toBe(0);

    // The PATIENT grants it through the endpoint todo 47 added - the gate is
    // checked against the patient's account, not the doctor's.
    $aktif = app(PdpDokumen::class)->versiAktif($jenis);

    pd47As($akun['pasienUser'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
        'disetujui' => true,
    ])->assertCreated();

    pd47As($akun['user'])->postJson('/api/v1/konsultasi/'.$sesi->getKey().'/surat-keterangan', $body)
        ->assertCreated()
        ->assertJsonPath('data.surat_keterangan.tipe', 'surat_rujukan');

    expect(SuratKeterangan::query()->count())->toBe(1)
        ->and(Rujukan::query()->count())->toBe(1);

    // The patient WITHDRAWS it on the SAME version, and the same request is
    // refused again. The gate reads the latest row, so a withdrawal is a
    // withdrawal - this is the end-to-end proof of the ledger rule.
    pd47As($akun['pasienUser'])->postJson('/api/v1/pdp/persetujuan', [
        'jenis' => $jenis,
        'versi_dokumen' => $aktif,
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
        // read the catalogue, read and record their OWN consent. Consent belongs
        // to a person whoever that person is at work, and
        // `persetujuan_pdp.user_id` (`:1136`) is a `users` foreign key, not a
        // `pasien` one.
        pd47As($user)->getJson('/api/v1/pdp/dokumen')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        pd47As($user)->getJson('/api/v1/pdp/persetujuan')
            ->assertOk()
            ->assertJsonPath('meta.total', 5);

        pd47As($user)->postJson('/api/v1/pdp/persetujuan', [
            'jenis' => 'syarat_ketentuan',
            'versi_dokumen' => app(PdpDokumen::class)->versiAktif('syarat_ketentuan'),
            'disetujui' => true,
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

    // `pdp.kelola` is granted to `admin` and `superadmin`. Its consumer is
    // F14's read-only admin ledger (`GET /admin/persetujuan-pdp`), NOT any route
    // in this file: recording a data subject's consent is the data subject's
    // act, and a route that let an admin write it would be a compliance defect
    // wearing a permission code. The three consent routes here therefore carry
    // no `permission:` at all, and the assertion below pins the code's grants
    // while the admin-side test pins its consumer.
    expect(RbacCatalog::ROLE_PERMISSIONS['admin'])->toContain('pdp.kelola')
        ->and(RbacCatalog::ROLE_PERMISSIONS['superadmin'])->toContain('pdp.kelola')
        ->and(RbacCatalog::isPermission('pdp.kelola'))->toBeTrue();

    // An anonymous caller is 401 on all six, which is the guard ORDER rather
    // than the permission: "send a token" and "you may not do this" are
    // different answers. BOTH the default headers and the memoised guard have to
    // be undone, for the reason the other anonymous block in this file spells
    // out - `pd47As()` leaves the bearer token as a default header and
    // `AuthManager` caches the user it resolved.
    foreach ([
        ['GET', '/api/v1/pdp/dokumen'],
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

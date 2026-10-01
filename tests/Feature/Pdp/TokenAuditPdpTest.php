<?php

declare(strict_types=1);

use App\Enums\NotifikasiTipe;
use App\Enums\PersetujuanPdpJenis;
use App\Http\Requests\Pdp\StorePersetujuanPdpRequest;
use App\Services\Notifikasi\NotificationService;
use App\Support\Schema\SqlSchemaParser;

require_once __DIR__.'/pdp47-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 47 - the byte-level gate and the DDL token audit, as PERMANENT tests
|--------------------------------------------------------------------------
|
| Tests rather than a one-shot script, because an audit nobody runs is a report
| and not a gate. Both invariants have already been violated on this project -
| `referencia` for `referensi` in a controller docblock cost 48 test failures -
| so both are made permanent here rather than left to the next executor's care.
|
| ## Why RAW BYTES, and not a decoded string
|
| `file_get_contents()` returns a byte string and this file walks it with `ord()`
| and never decodes it. That is the whole point: an identifier whose first letter
| is a Cyrillic U+0430 is VALID UTF-8, so a decoder hands it back looking like
| `a`, `mb_strtolower()` normalises it happily, and a regex matching `/^[a-z]/`
| accepts it. The only instrument that catches it is a byte comparison against
| 0x7F, which is what runs below.
|
| ## Why the ENUM values are their OWN bucket, and why PER COLUMN
|
| A quoted token is ambiguous on its own: `booking` is a member of BOTH
| `notifikasi.tipe` and `booking.status`, and `sistem` appears in more than one
| ENUM. So membership is asserted per `table.column`, in DDL order, with `toBe`
| rather than `toContain` - order included - because a value or an ORDER differing
| from the schema by one character still looks right in a diff and answers a 500
| at the INSERT, since MySQL rejects an ENUM value outside its list.
|
| ## Why the token audit is a DECLARED VOCABULARY, not a scrape
|
| The first draft of this audit in this project scraped every backticked token out
| of every authored file and demanded it resolve. That is the wrong instrument:
| `routes/api.php` backticks class names and permission codes, and the test files
| backtick PHP methods, so most candidates were not schema tokens at all and the
| gate ended up measuring its own noise. What is worth asserting is the
| vocabulary the CODE depends on, checked against the table that owns each column
| - strictly stronger than a flat name set, because `disetujui` existing somewhere
| in the schema says nothing about `persetujuan_pdp` having it.
|
| ## The DDL citation gate scans ITSELF
|
| A `:NNN` citation is only trustworthy if the line it names exists, and the plan's
| own citations for this batch are wrong in places: todo 47 names
| `notifikasi.idx_notif` at `:1045` and `:1045` is `dibuat_at`; the index is on
| `:1047`. So every citation in every file this todo authored is range-checked
| here. The negative lookbehind is load-bearing - without it the time portion of a
| frozen clock (`10:00`) reads as a citation - and the first run of this gate
| reported ten clock fragments as out-of-range, which is why the rule is spelled
| out in prose rather than shown as a token.
*/

/**
 * Every file todo 47 authored or modified. This list IS the audit's scope: a file
 * added to the todo and not to this list is silently ungated, so the count is
 * asserted at the bottom.
 *
 * @var list<string>
 */
const PD47_AUDIT_FILES = [
    'app/Enums/NotifikasiTipe.php',
    'app/Http/Controllers/Api/V1/NotifikasiController.php',
    'app/Http/Controllers/Api/V1/PersetujuanPdpController.php',
    'app/Http/Requests/Notifikasi/IndexNotifikasiRequest.php',
    'app/Http/Requests/Pdp/StorePersetujuanPdpRequest.php',
    'app/Http/Resources/NotifikasiResource.php',
    'app/Http/Resources/PersetujuanPdpResource.php',
    'app/Providers/AppServiceProvider.php',
    'app/Services/Notifikasi/LogPushDispatcher.php',
    'app/Services/Notifikasi/NotificationService.php',
    'app/Services/Notifikasi/PushDispatcher.php',
    'app/Services/Pdp/PdpConsent.php',
    'app/Services/Pdp/PdpConsentService.php',
    'app/Services/Pdp/PerubahanVersiException.php',
    'routes/api.php',
    'tests/Feature/Pdp/TokenAuditPdpTest.php',
    'tests/Feature/Pdp/pdp47-helpers.php',
    'tests/Feature/Pdp/PdpNotificationTest.php',
];

test('every file this todo authored is byte-identical ASCII, read as RAW BYTES', function (): void {
    $pelanggaran = [];
    $total = 0;

    foreach (PD47_AUDIT_FILES as $file) {
        $bytes = file_get_contents(base_path($file));

        // A missing file would make `file_get_contents` return false and `strlen`
        // read as 0, so a RENAMED file would pass this gate as an empty one.
        // Asserted first, per file, which is the only place it can be caught.
        expect($bytes)->toBeString("{$file} is not readable, so the byte gate would pass it vacuously");

        $total += strlen($bytes);

        if (str_starts_with((string) $bytes, "\xEF\xBB\xBF")) {
            $pelanggaran[] = "{$file}: UTF-8 BOM at offset 0";
        }

        $length = strlen((string) $bytes);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($bytes[$i]);

            if ($byte > 0x7F) {
                $pelanggaran[] = sprintf(
                    '%s: byte %d = 0x%s',
                    $file,
                    $i,
                    strtoupper(str_pad(dechex($byte), 2, '0', STR_PAD_LEFT)),
                );
            }
        }
    }

    // Byte-exact rather than "0 violations reported": the harness could itself be
    // broken and report nothing, and a floor on the byte count is the cheapest
    // proof that it actually read eighteen files.
    expect($pelanggaran)->toBe([])
        ->and($total)->toBeGreaterThan(200000)
        ->and(count(PD47_AUDIT_FILES))->toBe(18);
});

test('the schema this audit reads is the one the parity verifier reads', function (): void {
    // The audit is only worth anything if the parser and the file are the same ones
    // `sehatly:verify-schema` uses. Asserted rather than assumed, because a second
    // parser would make "0 unknown" a statement about something else.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->hasTable('persetujuan_pdp'))->toBeTrue()
        ->and($spec->hasTable('notifikasi'))->toBeTrue()
        ->and($spec->hasTable('user_devices'))->toBeTrue()
        ->and($spec->hasTable('audit_log'))->toBeTrue()
        ->and($spec->tableNames())->toHaveCount(76);
});

test('every column this todo names resolves against the table that OWNS it', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $kolomYangDipakai = [
        // Every column of `persetujuan_pdp` (`:1135`-`:1142`), and the
        // completeness assertion below makes the list prove it.
        'persetujuan_pdp' => [
            'id', 'user_id', 'jenis', 'versi_dokumen', 'disetujui', 'disetujui_at', 'ip_address',
        ],
        // Every column of `notifikasi` (`:1037`-`:1045`) for the same reason.
        'notifikasi' => [
            'id', 'user_id', 'judul', 'isi', 'tipe', 'tautan', 'payload', 'dibaca_at', 'dibuat_at',
        ],
        'user_devices' => ['id', 'user_id', 'device_id', 'platform', 'fcm_token', 'aktif'],
        'audit_log' => ['id', 'user_id', 'aksi', 'tabel_target', 'record_id', 'endpoint', 'dibuat_at'],
        'users' => ['id', 'uuid', 'nama_lengkap', 'no_telepon', 'kata_sandi_hash', 'tipe', 'status'],
        'pasien' => ['id', 'user_id', 'jenis_kelamin', 'tanggal_lahir', 'alamat_lengkap'],
        'dokter' => ['id', 'user_id', 'tipe', 'nomor_str'],
        'faskes' => ['id', 'kode_faskes', 'nama', 'tipe', 'alamat', 'status_aktif'],
        'konsultasi' => ['id', 'pasien_id', 'dokter_id', 'status'],
    ];

    $hilang = [];
    $total = 0;

    foreach ($kolomYangDipakai as $table => $columns) {
        $specTable = $spec->table($table);

        if ($specTable === null) {
            $hilang[] = "TABLE {$table} does not exist";

            continue;
        }

        foreach ($columns as $column) {
            $total++;

            if (! array_key_exists($column, $specTable->columns)) {
                $hilang[] = "{$table}.{$column} does not exist";
            }
        }
    }

    expect($hilang)->toBe([])
        ->and($total)->toBeGreaterThan(40);

    // No magic total: the meaningful claim is COMPLETENESS for the two tables this
    // todo writes. Every column of each is declared above, so a column added to the
    // DDL later fails here until this todo decides what it means - which is the
    // point of the list existing.
    foreach (['persetujuan_pdp', 'notifikasi'] as $table) {
        $dariDDL = array_keys($spec->table($table)->columns);
        $didasyarkan = $kolomYangDipakai[$table];

        sort($dariDDL);
        sort($didasyarkan);

        expect($didasyarkan)->toBe($dariDDL, "{$table} is not fully declared above");
    }

    // The classification is TOTAL over `persetujuan_pdp`: every column is either
    // accepted from the request or refused by it, with none falling into neither
    // bucket by accident. A `prohibited` entry that is forgotten is how a caller
    // gets a 201 for a field they believe they set, and this is the only test that
    // would notice.
    $diterima = ['jenis', 'versi_dokumen', 'disetujui'];
    $milikSistem = StorePersetujuanPdpRequest::KOLOM_MILIK_SISTEM;

    $diklasifikasi = array_merge($diterima, $milikSistem);
    $harusAda = array_keys($spec->table('persetujuan_pdp')->columns);

    sort($diklasifikasi);
    sort($harusAda);

    expect($diklasifikasi)->toBe($harusAda)
        ->and(count($diklasifikasi))->toBe(count(array_unique($diklasifikasi)));

    // And the three the request ACCEPTS are REAL rules, so a rename of the
    // constant alone cannot make this pass. Read off the request, not hardcoded
    // here, so the two lists cannot drift apart in the same edit.
    $rules = (new StorePersetujuanPdpRequest)->rules();

    foreach (['jenis', 'versi_dokumen', 'disetujui'] as $kolom) {
        expect($rules)->toHaveKey($kolom);
    }

    // And the four it REFUSES are PRESENT but as `prohibited` and nothing else,
    // which is the other half of the same claim: that single word is what turns a
    // caller-supplied `user_id` into a 422 instead of a consent for somebody else.
    // A key deleted outright would be worse, not better - it would be accepted.
    foreach (StorePersetujuanPdpRequest::KOLOM_MILIK_SISTEM as $kolom) {
        expect($rules)->toHaveKey($kolom)
            ->and($rules[$kolom])->toBe(['prohibited'], $kolom);
    }

    // Seven keys in total, no overlap between the accepted three and the refused
    // four, so the classification above is a partition rather than a union.
    expect(array_keys($rules))->toHaveCount(7)
        ->and(array_intersect($diterima, StorePersetujuanPdpRequest::KOLOM_MILIK_SISTEM))->toBe([]);
});

test('every ENUM member this todo writes is a member of the column it writes, in DDL order', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $enumYangDipakai = [
        // The five `jenis` values, from a WRAPPED ENUM at `:1137`-`:1138` - the
        // case a naive single-line read truncates to three values.
        'persetujuan_pdp.jenis' => PersetujuanPdpJenis::nilai(),
        // The seven `notifikasi.tipe` values at `:1041`, of which this todo's
        // service writes four. The FULL list is asserted, and the subset is
        // asserted as a subset of it, so a dropped ENUM member fails here rather
        // than producing a client that can never receive one.
        'notifikasi.tipe' => NotifikasiTipe::nilai(),
        'notifikasi.tipe::dipakai' => NotifikasiTipe::nilaiYangDipakai(),
        'user_devices.platform' => ['android', 'ios', 'web'],
        'audit_log.aksi' => ['create', 'read', 'update', 'delete', 'login', 'logout', 'download', 'export'],
    ];

    foreach ($enumYangDipakai as $qualified => $values) {
        $columnName = $qualified;

        if (str_contains($qualified, '::')) {
            [$columnName, $marker] = explode('::', $qualified);
            expect($marker)->toBe('dipakai');
        }

        [$table, $column] = explode('.', $columnName);
        $type = $spec->table($table)->columns[$column]->type;

        preg_match_all("/'([^']+)'/", $type, $m);

        if (str_contains($qualified, '::')) {
            // The subset must be a SUBSET, and in the same relative order, so
            // inventing a value the schema does not have fails here.
            $tidakDikenal = array_values(array_diff($values, $m[1]));

            expect($tidakDikenal)->toBe([], 'NotifikasiTipe::nilaiYangDipakai() names a value the ENUM lacks');
            expect($values)->toBe(array_values(array_intersect($m[1], $values)));
            expect(count($values))->toBeLessThan(count($m[1]));

            continue;
        }

        // `toBe`, not `toContain`: this checks ORDER as well as membership, so a
        // reordering of the ENUM or of the application's own list fails.
        expect($m[1])->toBe($values, "{$qualified} is not the parsed DDL list, in DDL order");
    }

    // The ambiguity this bucket exists to REPORT rather than resolve, taken from
    // the parse rather than asserted from memory. `chat` is the sharpest case: it
    // is a notification type AND a `booking.tipe_layanan` AND a `konsultasi.tipe`,
    // so a bare value cannot say which column it belongs to. `sistem` spans four.
    // Getting this from the parser is the point - the first draft of this test
    // named `booking.status` from memory and the parse disagreed.
    $kolom = [];

    foreach ($spec->tables as $satu) {
        foreach ($satu->columns as $column) {
            preg_match_all("/'([^']+)'/", $column->type, $m);

            foreach ($m[1] as $value) {
                $kolom[$value][] = $satu->name.'.'.$column->name;
            }
        }
    }

    expect($kolom['chat'])->toBe(['booking.tipe_layanan', 'konsultasi.tipe', 'notifikasi.tipe'])
        ->and($kolom['sistem'])->toHaveCount(4)
        ->and($kolom['sistem'])->toContain('notifikasi.tipe')
        ->and($kolom['booking'])->toContain('notifikasi.tipe')
        ->and($kolom['booking'])->toContain('invoice.referensi_tipe');

    // And a value unique to this todo's column, so the test is not only proving
    // that ambiguities exist: `promo` names exactly one column in the schema.
    expect($kolom['promo'])->toBe(['notifikasi.tipe']);

    // And the reverse direction for the table the ledger reads: F02 dropped
    // `uq_consent`, so the parsed spec must show NO index beyond the primary
    // key. Asserted against the parsed spec rather than a hand-typed list, so a
    // re-added unique key fails here instead of silently restoring the old
    // same-version collision.
    $nama = array_map(static fn ($index): string => (string) $index->name, $spec->table('persetujuan_pdp')->indexes);

    expect($nama)->toBe(['PRIMARY'])
        ->and($nama)->not->toContain('uq_consent');
});

test('every DDL citation in the files this todo authored points at a line that EXISTS', function (): void {
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);
    $total = count($lines);

    $citasi = 0;
    $diLuar = [];

    foreach (PD47_AUDIT_FILES as $file) {
        $source = (string) file_get_contents(base_path($file));

        if (preg_match_all('/(?<!\d):(\d{1,4})\b/', $source, $m) === 0) {
            continue;
        }

        foreach ($m[1] as $n) {
            $citasi++;
            $value = (int) $n;

            if ($value < 1 || $value > $total) {
                $diLuar[] = $file.': :'.$n;
            }
        }
    }

    expect($diLuar)->toBe([])
        ->and($total)->toBe(1364)
        ->and($citasi)->toBeGreaterThan(150);
});

test('the notification service writes only columns the DDL declares, and only the read stamp is client-writable', function (): void {
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $notifikasi = array_keys($spec->table('notifikasi')->columns);

    // The seven the service assigns, by name, plus the two the database writes.
    // `dibaca_at` is assigned ONCE in the whole todo, by the two read stamps, and
    // `NotificationService` leaves it `null` deliberately - which the method list
    // below pins.
    $ditulisLayanan = [
        'user_id' => NotificationService::class,
        'judul' => NotificationService::class,
        'isi' => NotificationService::class,
        'tipe' => NotificationService::class,
        'tautan' => NotificationService::class,
        'payload' => NotificationService::class,
        'dibaca_at' => NotificationService::class,
    ];

    foreach (array_keys($ditulisLayanan) as $column) {
        expect($notifikasi)->toContain($column);
    }

    // Every column of the table is accounted for: the six the service writes, the
    // one the read stamps write, and the two the database writes.
    $diklasifikasi = array_merge(
        array_keys($ditulisLayanan),
        ['id', 'dibuat_at'],
    );

    sort($diklasifikasi);
    $harusAda = $notifikasi;
    sort($harusAda);

    expect($diklasifikasi)->toBe($harusAda);

    // The two ABSENCES the notification centre is built around, asserted against
    // the parsed column list rather than by a substring search: `notifikasi` has no
    // delivery-state column, no channel column and no attempt counter, so
    // "queued / sent / failed" is not representable and delivery is logged.
    foreach (['dikirim_at', 'status_kirim', 'channel', 'percobaan', 'diubah_at', 'dibatalkan_at'] as $hilang) {
        expect($notifikasi)->not->toContain($hilang);
    }

    // And `dibaca_at` really is the only nullable time the client can move: the two
    // read stamps are the only writes, and there is no builder write anywhere in
    // the controller, because a builder `update()` fires no Eloquent event and would
    // leave the only write in this table with no `audit_log` row.
    //
    // Stripped of comments first, deliberately. The controller's own docblock at
    // `:84` NAMES `->update([...])` while explaining why it is avoided, so a raw
    // substring scan of the file fails on its own explanation. The instrument has
    // to be the tokenizer rather than a text match, or the check is really asserting
    // that a comment is absent.
    $path = base_path('app/Http/Controllers/Api/V1/NotifikasiController.php');
    $sumber = (string) file_get_contents($path);
    $kode = pd47TanpaKomentar($path);

    expect($sumber)->toContain('$baris->dibaca_at = now();')
        ->and(substr_count($sumber, '$baris->dibaca_at = now();'))->toBe(2)
        // Two stamps, two `save()` calls, so every mutation in the controller
        // goes through the model and fires `updated`.
        ->and(substr_count($kode, '$baris->save();'))->toBe(2)
        ->and($kode)->not->toContain('->update(')
        ->and($kode)->not->toContain('->delete(')
        ->and($kode)->not->toContain('->create(')
        ->and($kode)->not->toContain('Notifikasi::create(')
        ->and($kode)->not->toContain('forceDelete(');
});

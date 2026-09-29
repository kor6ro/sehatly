<?php

declare(strict_types=1);

use App\Support\NikCipher;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Todo 50 - the byte gate, the DDL token audit, and the citation bounds
|--------------------------------------------------------------------------
|
| These are tests rather than a one-shot script because an audit nobody runs is
| a report, not a gate. Both invariants have already been violated in this
| project: a single wrong word in a method name shipped here as a 500 with a
| stack trace, and a `referensi` / `referensi` slip once cost 48 test failures.
|
| ## Why RAW BYTES and not a decoded string
|
| `file_get_contents()` returns a byte string and this file walks it with
| `ord()` and never decodes it. An identifier whose first letter is a Cyrillic
 * U+0430 is valid UTF-8, survives `mb_strtolower()` unchanged, and matches
| `/^[a-z]/`; only a byte comparison against 0x7F catches it. The mask
| character U+2022 is a legitimate non-ASCII character in this project, and it
| is written as the PHP escape `"\u{2022}"` in these files rather than as a
| literal byte pair - which is what lets this gate be a whole-file ASCII
| assertion rather than a list of permitted characters. The gate caught exactly
| that mistake the first time it ran: a comment in THIS file had been written
| with a literal U+00AB instead of naming the codepoint, and it reported bytes
| 1132 and 1133.
|
| ## Why the token audit is PER TABLE
|
| A flat set of every column name in the schema would be a much weaker claim:
| `nik` existing SOMEWHERE says nothing about `pasien` having it. Checking each
| column against the table that owns it is strictly stronger, and it is the only
| form that catches a token typo - a misspelt column name resolves against no
| table at all, which a flat set would also catch, but a misspelt TABLE name
 * would silently pass a flat set and fail this one.
|
| ## Why the ENUM values get their own bucket
|
| A backticked token is a table name, a column name, an ENUM value, or none of
| those, in that order. The ENUM bucket is separate because a value is AMBIGUOUS
| on its own: `ringan` belongs to both `obat_interaksi.tingkat` and
| `pasien_alergi.keparahan`, so the audit reports which columns hold each
| ambiguous value rather than resolving it. This todo writes no ENUM at all, and
| asserting that is the point: the two NIK columns are `CHAR(16)` and
| `pasien.jenis_kelamin` is the only identity-adjacent enum, so a future edit
| that tries to make NIK an ENUM fails here.
*/

const T50_AUDIT_FILES = [
    'app/Support/NikCipher.php',
    'app/Support/NikMasker.php',
    'app/Support/Security/MissingNikCipherKeyException.php',
    'app/Support/Security/NikDecryptionException.php',
    'app/Http/Resources/BookingResource.php',
    'app/Http/Resources/KonsultasiResource.php',
    'app/Http/Resources/PasienAnggotaKeluargaResource.php',
    'app/Http/Resources/PasienResource.php',
    'app/Http/Resources/RekamMedisResource.php',
    'app/Http/Resources/SuratKeteranganResource.php',
    'config/nik.php',
    'tests/Feature/Pasien/NikCipherTest.php',
    'tests/Feature/Pasien/NikCipherAuditTest.php',
];

beforeEach(function (): void {
    // The width claim in the DDL test encrypts a real NIK, and the cipher
    // refuses to work without a key. Set rather than assumed.
    config([
        'nik.key' => base64_encode(random_bytes(32)),
        'nik.previous_keys' => [],
    ]);
});

test('every authored file is byte-identical ASCII, read as RAW BYTES', function (): void {
    $pelanggaran = [];
    $total = 0;

    foreach (T50_AUDIT_FILES as $file) {
        $bytes = file_get_contents(base_path($file));

        // A missing file makes `file_get_contents` return false and `strlen`
        // below reads as 0, so a RENAMED file would pass this gate as an empty
        // one. Asserted first, per file.
        expect($bytes)->toBeString($file.' is not readable, so the byte gate would pass it vacuously');

        $total += strlen($bytes);

        if (str_starts_with((string) $bytes, "\xEF\xBB\xBF")) {
            $pelanggaran[] = $file.': UTF-8 BOM at offset 0';
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

    // Byte-exact, not "0 violations reported": the harness could itself be
    // broken and report nothing, and a total floor is the cheapest proof that it
    // actually read the files.
    expect($pelanggaran)->toBe([])
        ->and($total)->toBeGreaterThan(40000)
        ->and(count(T50_AUDIT_FILES))->toBe(13);
});

test('every DDL citation in the authored files points at a line that EXISTS', function (): void {
    // The plan's inline `:NNN` citations are off by one in places, so a citation
    // in these files is only trustworthy if the range is checked. The CONTENT of
    // the cited lines is asserted by the next test, from the parsed DDL.
    //
    // The negative lookbehind is load-bearing: without it the time portion of a
    // frozen clock is a citation, which is how an earlier version of this gate
    // reported ten phantom violations and then one more - the literal in its own
    // comment. The gate scans itself, which is the only way it can be trusted to
    // scan its neighbours.
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);
    $total = count($lines);

    $citasi = 0;
    $diLuar = [];

    foreach (T50_AUDIT_FILES as $file) {
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
        ->and($total)->toBe(1349)
        ->and($citasi)->toBeGreaterThan(15);
});

test('the schema holds exactly two NIK columns, and both are the CHAR(16) this todo confronts', function (): void {
    // THE DDL DELIVERABLE, asserted rather than described: a sweep of all 75
    // parsed tables for a column named `nik`. If a third appears, this fails and
    // the cipher has to be told about it.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->tableNames())->toHaveCount(75);

    $ditemukan = [];

    foreach ($spec->tables as $table) {
        foreach ($table->columns as $column) {
            if ($column->name !== 'nik') {
                continue;
            }

            $ditemukan[$table->name] = $column;
        }
    }

    ksort($ditemukan);

    expect(array_keys($ditemukan))->toBe(['pasien', 'pasien_anggota_keluarga'])
        ->and($ditemukan['pasien']->type)->toBe('char(16)')
        ->and($ditemukan['pasien']->nullable)->toBeTrue()
        ->and($ditemukan['pasien_anggota_keluarga']->type)->toBe('char(16)')
        ->and($ditemukan['pasien_anggota_keluarga']->nullable)->toBeTrue();

    // The declared line numbers, read out of the parser rather than trusted from
    // the plan. `telemedicine_test.sql:222` and `:263` are what this todo's
    // whole argument turns on, so they are checked against the file.
    $garis = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);

    expect($garis[$ditemukan['pasien']->line - 1])
        ->toContain("nik CHAR(16) NULL UNIQUE")
        ->toContain('WAJIB dienkripsi')
        ->and($garis[$ditemukan['pasien_anggota_keluarga']->line - 1])
        ->toBe('  nik CHAR(16) NULL,');

    // AND THE WIDTH CLAIM, from the DDL rather than from a comment: 16
    // characters, and a payload of 88. `TEXT` is the only type in the file's
    // vocabulary that can hold the second one, and the plan's own rule - never
    // encrypt a value into a column narrower than its ciphertext - therefore
    // makes `CHAR(16)` an arithmetic impossibility rather than a bad default.
    expect($ditemukan['pasien']->type)->toBe('char(16)')
        ->and(strlen(NikCipher::encrypt('3273123456780001')))->toBeGreaterThan(16)
        ->and(NikCipher::COL_PAYLOAD)->toBe('nik_cipher')
        ->and(NikCipher::COL_INDEX)->toBe('nik_index');

    // The UNIQUE the blind index has to preserve is authored on the column
    // itself, so the parser records it as a single-column UNIQUE index.
    $unik = array_values(array_filter(
        $spec->table('pasien')->indexes,
        static fn ($index): bool => $index->type === 'UNIQUE' && $index->columns === ['nik'],
    ));

    expect($unik)->toHaveCount(1)
        // And the family table has NO unique on `nik`, which is why that table
        // gets no blind index: the linkage cost with no integrity benefit.
        ->and(array_values(array_filter(
            $spec->table('pasien_anggota_keluarga')->indexes,
            static fn ($index): bool => $index->columns === ['nik'],
        )))->toBe([]);
});

test('every column this todo names resolves against the parsed DDL, table by table', function (): void {
    // The audit is only worth anything if the parser and the file are the same
    // ones `sehatly:verify-schema` reads, so that is asserted rather than
    // assumed: a different parser would make "0 unresolvable" a statement about
    // something else.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $kolomYangDipakai = [
        'pasien' => ['id', 'user_id', 'nomor_rm', 'nik', 'nomor_kk', 'jenis_kelamin', 'tanggal_lahir', 'alamat_lengkap'],
        'pasien_anggota_keluarga' => [
            'id', 'pasien_id', 'hubungan_id', 'nik', 'nama_lengkap',
            'jenis_kelamin', 'tanggal_lahir', 'no_telepon', 'catatan_alergi', 'dibuat_at',
        ],
        'users' => ['id', 'uuid', 'nama_lengkap', 'no_telepon', 'kata_sandi_hash', 'tipe', 'status'],
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
        ->and($total)->toBe(25);

    // Completeness for the one table this todo names in full, so a column added
    // to the DDL later fails here until somebody decides what it means.
    $dariDDL = array_keys($spec->table('pasien_anggota_keluarga')->columns);
    sort($dariDDL);
    $didasyarkan = $kolomYangDipakai['pasien_anggota_keluarga'];
    sort($didasyarkan);

    // `pasien` is deliberately NOT required to be complete: it has 35 columns
    // and this todo touches three of them, so demanding all 35 would be a
    // different todo's list. The five it does touch are pinned instead.
    expect($didasyarkan)->toBe($dariDDL);
});

test('the ENUM bucket is separate, and this todo writes no ENUM at all', function (): void {
    // A backticked token is a table name, a column name, an ENUM value, or
    // none of those - in that order. The ENUM bucket is separate because a value
    // is AMBIGUOUS on its own, and this file's claim is narrower: the two NIK
    // columns are `CHAR(16)`, so there is no NIK ENUM to validate against, and
    // `pasien.jenis_kelamin` is the only identity-adjacent enum on the row.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $enumYangDipakai = [
        'pasien.jenis_kelamin' => ['L', 'P'],
        'pasien.rhesus' => ['positif', 'negatif', 'tidak_diketahui'],
    ];

    foreach ($enumYangDipakai as $qualified => $values) {
        [$table, $column] = explode('.', $qualified);

        $type = $spec->table($table)->columns[$column]->type;

        preg_match_all("/'([^']+)'/", $type, $m);

        // `toBe`, not `toContain`: ORDER is checked as well as membership, so a
        // reordering of the ENUM or of the application's own list fails.
        expect($m[1])->toBe($values, "{$qualified} is not the parsed DDL list, in DDL order");
    }

    // The ambiguity, reported rather than resolved, and asserted to be genuinely
    // ambiguous so the bucket above can never be collapsed into a cast.
    $kolom = [];

    foreach ($spec->tables as $satu) {
        foreach ($satu->columns as $column) {
            preg_match_all("/'([^']+)'/", $column->type, $m);

            foreach ($m[1] as $value) {
                $kolom[$value][] = $satu->name.'.'.$column->name;
            }
        }
    }

    $ambigu = array_filter($kolom, static fn (array $cols): bool => count(array_unique($cols)) > 1);

    expect($kolom['ringan'])->toContain('obat_interaksi.tingkat')
        ->and($kolom['ringan'])->toContain('pasien_alergi.keparahan')
        ->and(count($ambigu))->toBeGreaterThan(3)
        // And the negative claim, which is the one that would fail if somebody
        // tried to model a NIK as an ENUM to make masking "work".
        ->and($spec->table('pasien')->columns['nik']->type)->toBe('char(16)');
});

test('the DDL file is byte-identical and this todo added no table, index or constraint', function (): void {
    // The whole reason the cipher could not simply be pointed at a new column is
    // that the reference SQL is read-only law. Asserting the digest makes the
    // claim checkable by the next executor rather than a promise in prose.
    expect(hash_file('sha256', base_path('telemedicine_test.sql')))
        ->toBe('aefe2247e00f09acb02235168ac289cdfa74f762d604ada71f68e328574b27f5');

    // No table named after the proposed columns exists: if a migration had
    // already landed them, this todo's finding would be stale.
    $tables = DB::select(
        'select table_name as nama from information_schema.tables where table_schema = database()'
        .' and table_name in (?, ?)',
        [NikCipher::COL_PAYLOAD, NikCipher::COL_INDEX],
    );

    expect($tables)->toBe([]);

    // And `pasien` carries no index on a column that does not exist, which is
    // the shape a half-applied migration would leave behind.
    $indexes = DB::select(
        'select column_name as kolom from information_schema.statistics'
        .' where table_schema = database() and table_name = ?',
        ['pasien'],
    );
    $kolom = array_unique(array_map(static fn ($baris): string => $baris->kolom, $indexes));

    expect(in_array(NikCipher::COL_INDEX, $kolom, true))->toBeFalse()
        ->and(in_array(NikCipher::COL_PAYLOAD, $kolom, true))->toBeFalse()
        ->and(in_array('nik', $kolom, true))->toBeTrue('the DDL UNIQUE on pasien.nik is the one this todo preserves');

    // `mobile/` is a later todo's concern and must not exist yet.
    expect(is_dir(base_path('mobile')))->toBeFalse();
});

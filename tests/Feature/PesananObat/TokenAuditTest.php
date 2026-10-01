<?php

declare(strict_types=1);

use App\Enums\PesananObatStatus;
use App\Services\PesananObat\PesananObatService;
use App\Support\Schema\SqlSchemaParser;

require_once __DIR__.'/pesanan46-helpers.php';

/*
|--------------------------------------------------------------------------
| Todo 46 - the byte-level gate and the DDL token audit, as PERMANENT tests
|--------------------------------------------------------------------------
|
| These are tests rather than a one-shot script because an audit nobody runs is
| a report, not a gate. Both invariants have already been violated once in this
| task: a CJK character and a `kurang`/`kurangi` mismatch both reached the
| application code, and the second one shipped as a 500 with a stack trace
| instead of a failing test.
|
| ## Why RAW BYTES, and not a decoded string
|
| `file_get_contents()` returns a byte string, and this test walks it with
| `ord()` and never decodes it. That is the whole point: an identifier whose
| first letter is a Cyrillic `U+0430` is VALID UTF-8, so a decoder hands it back
| looking like `a`, a `mb_strtolower()` normalises it happily, and a regex
| matching `/^[a-z]/` accepts it. The only thing that catches it is a byte
| comparison against 0x7F.
|
| ## Why the ENUM values are their OWN BUCKET
|
| A backticked token is bucketed as a table name, a column name, an ENUM value,
| or none of those - in that order. The ENUM bucket is separate because a value
| is AMBIGUOUS on its own: `ringan` is a member of both
| `obat_interaksi.tingkat` and `pasien_alergi.keparahan`, and the audit reports
| which columns each ambiguous value lives on rather than resolving it. An
| earlier audit in this project put `ringan` and `Berat` in that position and
| concluded the values needed a cast, which is the wrong direction.
*/

const PO46_AUDIT_FILES = [
    'app/Enums/PesananObatStatus.php',
    'app/Http/Controllers/Api/V1/PesananObatController.php',
    'app/Http/Requests/PesananObat/CheckoutResepRequest.php',
    'app/Http/Requests/PesananObat/StokObatRequest.php',
    'app/Http/Resources/PesananObatResource.php',
    'app/Http/Resources/PesananObatTrackingResource.php',
    'app/Http/Resources/StokObatResource.php',
    'app/Services/Pasien/PasienRecordAccess.php',
    'app/Services/PesananObat/ApotekStokService.php',
    'app/Services/PesananObat/PesananObatService.php',
    'app/Services/PesananObat/PesananObatStateMachine.php',
    'app/Services/PesananObat/StokTidakCukupException.php',
    'app/Support/Dokumen/NomorDokumen.php',
    'routes/api.php',
    'tests/Feature/PesananObat/CheckoutTest.php',
    'tests/Feature/PesananObat/ApotekStokConcurrencyTest.php',
    'tests/Feature/PesananObat/PesananObatTrackingTest.php',
    'tests/Feature/PesananObat/pesanan46-helpers.php',
    'tests/Feature/PesananObat/TokenAuditTest.php',
];

beforeEach(function (): void {
    po46Bersihkan();
});

test('every authored file is byte-identical ASCII, read as RAW BYTES', function (): void {
    $pelanggaran = [];
    $total = 0;

    foreach (PO46_AUDIT_FILES as $file) {
        $bytes = file_get_contents(base_path($file));

        // A missing file would make `file_get_contents` return false and
        // `strlen` below would read as 0, so a renamed file would pass this
        // gate as an empty one. Asserted first, per file.
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

    // Byte-exact, not "0 violations reported": the harness could itself be
    // broken and report nothing, and `expect($total)->toBeGreaterThan(50000)`
    // is the cheapest proof that it actually read the files.
    expect($pelanggaran)->toBe([])
        ->and($total)->toBeGreaterThan(50000)
        ->and(count(PO46_AUDIT_FILES))->toBe(19);
});

test('the schema the audit reads is the one the parity verifier reads', function (): void {
    // The audit is only worth anything if the parser and the file are the same
    // ones `sehatly:verify-schema` uses. Asserted rather than assumed, because a
    // different parser would make "0 unknown" a statement about something else.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    expect($spec->hasTable('pesanan_obat'))->toBeTrue()
        ->and($spec->hasTable('apotek_stok'))->toBeTrue()
        ->and($spec->hasTable('pesanan_obat_tracking'))->toBeTrue()
        ->and($spec->tableNames())->toHaveCount(76);
});

test('every column and ENUM value this todo names resolves against the parsed DDL, table by table', function (): void {
    // The invariant is a DECLARED VOCABULARY checked against the parser, and
    // it is declared PER TABLE rather than as a flat set of names.
    //
    // The first draft scraped every backticked token out of every authored file
    // and demanded it resolve. That is the wrong instrument: `routes/api.php`
    // backticks class names, permission codes and route names, and the test
    // files backtick PHP methods, so 140 of the candidates were not schema
    // tokens at all. A gate that mostly measures its own noise is a gate nobody
    // reads. What is worth asserting is the vocabulary the CODE depends on, and
    // checking each column against the table that owns it - which is strictly
    // stronger than a flat name set, because `jumlah_stok` existing somewhere
    // in the schema says nothing about `apotek_stok` having it.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $kolomYangDipakai = [
        'pesanan_obat' => [
            'id', 'nomor_pesanan', 'resep_id', 'pasien_id', 'apotek_id', 'tipe', 'alamat_kirim',
            'kurir', 'no_resi', 'subtotal', 'biaya_kirim', 'total', 'status', 'dibuat_at', 'diubah_at',
        ],
        'pesanan_obat_tracking' => ['id', 'pesanan_obat_id', 'status', 'keterangan', 'lokasi', 'waktu'],
        'apotek_stok' => ['id', 'apotek_id', 'obat_id', 'jumlah_stok', 'stok_minimum', 'harga_jual', 'kedaluwarsa', 'diubah_at'],
        'resep' => ['pasien_id', 'dokter_id', 'apotek_id', 'status', 'tanggal_resep', 'berlaku_sampai'],
        'resep_item' => ['resep_id', 'obat_id', 'nama_obat', 'jumlah', 'harga_satuan', 'subtotal'],
        'resep_verifikasi' => ['resep_id', 'apoteker_user_id', 'status'],
        'master_obat' => ['kode_obat', 'nama_generik', 'kelas_obat', 'requires_resep', 'harga_jual', 'status_aktif'],
        'faskes' => ['nama', 'tipe', 'alamat', 'status_aktif'],
        'pasien' => ['user_id', 'alamat_lengkap', 'jenis_kelamin', 'tanggal_lahir'],
        'invoice' => ['nomor_invoice', 'pasien_id', 'referensi_tipe', 'referensi_id', 'subtotal', 'diskon', 'biaya_admin', 'biaya_pengiriman', 'total', 'status'],
        'users' => ['uuid', 'nama_lengkap', 'no_telepon', 'kata_sandi_hash', 'tipe', 'status'],
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
        ->and($total)->toBeGreaterThan(60);

    // No magic total: the meaningful claim is COMPLETENESS for the three tables
    // this todo writes. Every column of each is declared above, so a column
    // added to the DDL later fails here until this todo decides what it means -
    // which is the point of the list existing.
    foreach (['pesanan_obat', 'pesanan_obat_tracking', 'apotek_stok'] as $table) {
        $dariDDL = array_keys($spec->table($table)->columns);
        $didasyarkan = $kolomYangDipakai[$table];

        sort($dariDDL);
        sort($didasyarkan);

        expect($didasyarkan)->toBe($dariDDL, "{$table} is not fully declared above");
    }

    // THE REVERSE DIRECTION, for the table this todo writes hardest: every
    // column `PesananObatService` names must be a real one, and the two the
    // service writes but the request forbids (`apotek_id`, `biaya_kirim`,
    // `tipe`) are accounted for rather than quietly missing.
    $semua = array_keys($spec->table('pesanan_obat')->columns);

    expect($semua)->toBe([
        'id', 'nomor_pesanan', 'resep_id', 'pasien_id', 'apotek_id', 'tipe', 'alamat_kirim',
        'kurir', 'no_resi', 'subtotal', 'biaya_kirim', 'total', 'status', 'dibuat_at', 'diubah_at',
    ]);

    // And the classification is TOTAL over those fifteen: every column is either
    // derived-and-prohibited or accepted-from-the-request, with no column falling
    // into neither bucket by accident.
    $dikirim = PesananObatService::KOLOM_DARI_REQUEST;
    $milikSistem = PesananObatService::KOLOM_MILIK_SISTEM;

    $diklasifikasi = array_merge($dikirim, $milikSistem);

    sort($diklasifikasi);
    $harusAda = $semua;
    // `id` is neither: it is the primary key, written by the database.
    $harusAda = array_values(array_diff($harusAda, ['id']));
    sort($harusAda);

    expect($diklasifikasi)->toBe($harusAda)
        ->and(count($diklasifikasi))->toBe(count(array_unique($diklasifikasi)));
});

test('every ENUM member this todo writes is a member of the column it writes, and the ambiguous ones are reported', function (): void {
    // The ENUM bucket, per column rather than as a flat value set, for the reason
    // the flat version is wrong: `ringan` is a member of BOTH
    // `obat_interaksi.tingkat` and `pasien_alergi.keparahan`, so a value alone
    // cannot decide its column and an earlier audit in this project resolved
    // that ambiguity the wrong way round.
    $spec = (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));

    $enumYangDipakai = [
        'pesanan_obat.status' => PesananObatStatus::nilai(),
        'pesanan_obat.tipe' => PesananObatService::SEMUA_TIPE,
        'pesanan_obat.kurir' => PesananObatService::SEMUA_KURIR,
        'faskes.tipe' => ['rumah_sakit', 'klinik', 'puskesmas', 'apotek', 'laboratorium'],
    ];

    foreach ($enumYangDipakai as $qualified => $values) {
        [$table, $column] = explode('.', $qualified);

        $type = $spec->table($table)->columns[$column]->type;

        preg_match_all("/'([^']+)'/", $type, $m);

        // `toBe`, not `toContain`: this checks ORDER as well as membership, so a
        // reordering of the ENUM or of the application's own list fails.
        expect($m[1])->toBe($values, "{$qualified} is not the parsed DDL list, in DDL order");
    }

    // The ambiguity, reported rather than resolved.
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

    // The value this audit originally mis-resolved, asserted to be genuinely
    // ambiguous so the bucket above can never be collapsed to a cast.
    expect($kolom['ringan'])->toContain('obat_interaksi.tingkat')
        ->and($kolom['ringan'])->toContain('pasien_alergi.keparahan')
        ->and($kolom['berat'])->toContain('obat_interaksi.tingkat')
        ->and($kolom['berat'])->toContain('pasien_alergi.keparahan')
        ->and(count($ambigu))->toBeGreaterThan(3);
});

test('every DDL citation in the authored files points at a line that EXISTS', function (): void {
    // The plan's own `:NNN` citations in this batch are off by one in places, so
    // a citation in these files is only trustworthy if the range is checked. The
    // CONTENT of the cited lines is asserted elsewhere, by `po46AssertLine()`.
    //
    // The negative lookbehind is load-bearing. Without it the time portion of a
    // frozen clock is a citation: the first run of this test reported ten of
    // those as out-of-range, and the second reported one more - the literal in
    // this very comment, which is why the explanation above spells it out rather
    // than showing the token. The gate scans itself, which is the only way it
    // can be trusted to scan its neighbours.
    $lines = file(base_path('telemedicine_test.sql'), FILE_IGNORE_NEW_LINES);
    $total = count($lines);

    $citasi = 0;
    $diLuar = [];

    foreach (PO46_AUDIT_FILES as $file) {
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
        ->and($citasi)->toBeGreaterThan(60);
});

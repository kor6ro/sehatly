<?php

use App\Support\Schema\SchemaParseException;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The parity verifier is only as trustworthy as its parser. A parser that quietly
 * skipped a construct would report "no drift" for a schema it never read, so these
 * tests pin the *coverage* of the reference DDL, not just that parsing does not
 * throw.
 */
function referenceSpec(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

test('the reference DDL parses into exactly 80 tables and 2 views', function () {
    $spec = referenceSpec();

    // `indexes` is 152 (143 before F11's four appended tables). The two removals
    // F02 folded in are described below;
    // F08 appended `konsultasi_baca`, whose primary key and named
    // `UNIQUE KEY uq_baca` add two indexes on top of F02's 140.
    //
    // - migration `2026_10_01_000079` renamed `pasien.nik` to `nik_cipher` and
    //   the DDL dropped the inline `UNIQUE` the old `nik CHAR(16) NULL UNIQUE`
    //   column carried (a `TEXT` column cannot carry an index at all - MySQL
    //   requires a key length for `TEXT` - and this project deliberately adds
    //   NO blind index to replace it);
    // - F02 dropped `uq_consent` from `persetujuan_pdp`, whose line in the DDL
    //   is now a comment (see `docs/schema-notes.md`).
    //
    // `columns` moved 672 -> 678 (six columns of `konsultasi_baca`) and
    // `foreign_keys` 105 -> 107 (its two cascading keys). F08's schema note is
    // in `docs/schema-notes.md`.
    //
    // F01 then added `user_refresh_tokens.device_id` and its named
    // `idx_refresh_device`: `columns` 678 -> 679 and `indexes` 142 -> 143. The
    // reference edit folded both declarations onto existing lines (`:206` and
    // `:211`) so the file stayed 1,364 lines; see `docs/schema-notes.md`.
    //
    // F11 APPENDED section [18] at the very end (four tables: the two preference
    // tables, `pengingat` and `pengingat_terkirim`): `tables` 76 -> 80,
    // `columns` 679 -> 716, `indexes` 143 -> 152 and `foreign_keys` 107 -> 114,
    // and the file moved 1,364 -> 1,429 lines. No pre-existing line moved.
    expect($spec->summary())->toBe([
        'tables' => 80,
        'views' => 2,
        'columns' => 716,
        'indexes' => 152,
        'foreign_keys' => 114,
        'checks' => 3,
    ]);

    expect($spec->views)->toBe(['v_dokter_katalog', 'v_pendapatan_bulanan']);
});

test('the parser reads columns, indexes, foreign keys and checks - it is not vacuous', function () {
    $spec = referenceSpec();

    // A parser that produced 75 empty table shells would satisfy a table count.
    expect($spec->columnCount())->toBeGreaterThan(600);
    expect($spec->indexCount())->toBeGreaterThan(100);
    expect($spec->foreignKeyCount())->toBeGreaterThan(100);
    expect($spec->checkCount())->toBe(3);

    // Sanity: a mid-file table is fully populated, not just counted.
    $booking = $spec->table('booking');
    expect($booking)->not->toBeNull();
    expect($booking->columns)->toHaveCount(22);
    expect($booking->indexes)->toHaveCount(4);
    expect($booking->foreignKeys)->toHaveCount(6);
    expect($booking->engine)->toBe('innodb');
});

test('every multi-line ENUM is read as one unit', function () {
    $spec = referenceSpec();
    $wrapped = $spec->multiLineColumns();

    // telemedicine_test.sql:515, 542, 568, 713, 751, 810, 884, 947, 1022, 1104, 1137.
    // The plan named six; there are eleven, and every one must survive the wrap.
    expect($wrapped)->toHaveCount(11);

    expect($spec->table('booking')->columns['status']->type)
        ->toBe("enum('menunggu_pembayaran','terjadwal','check_in','berlangsung','selesai','dibatalkan','no_show','kadaluarsa')");
    expect($spec->table('konsultasi')->columns['status']->type)
        ->toBe("enum('menunggu_dokter','berlangsung','menunggu_resep','selesai','dibatalkan','gagal')");
    expect($spec->table('konsultasi_chat')->columns['tipe_pesan']->type)
        ->toBe("enum('teks','gambar','dokumen','audio','video_note','resep','surat_keterangan','sistem')");
    expect($spec->table('master_obat')->columns['bentuk_sediaan']->type)
        ->toBe("enum('tablet','kaplet','kapsul','sirup','salep','krim','gel','tetes','injeksi','inhaler','suppositoria','lainnya')");
    expect($spec->table('resep')->columns['status']->type)
        ->toBe("enum('aktif','diproses','diverifikasi','dipenuhi','dikirim','selesai','kedaluwarsa','dibatalkan')");
    expect($spec->table('persetujuan_pdp')->columns['jenis']->type)
        ->toBe("enum('syarat_ketentuan','kebijakan_privasi','berbagi_data_medis','pemasaran','komunikasi_tindak_lanjut')");
    expect($spec->table('invoice')->columns['status']->type)
        ->toBe("enum('draft','menunggu_pembayaran','lunas','kadaluarsa','dibatalkan','refund_sebagian','refund_penuh')");
});

test('ENUM member case is data, not spelling', function () {
    $spec = referenceSpec();

    expect($spec->table('master_golongan_darah')->columns['kode']->type)->toBe("enum('A','B','AB','O')");
    expect($spec->table('pasien')->columns['jenis_kelamin']->type)->toBe("enum('L','P')");
});

test('the three inline CHECKs are captured as expressions in declaration order', function () {
    $spec = referenceSpec();
    $checks = $spec->table('ulasan_dokter')->checks;

    expect($checks)->toHaveCount(3);
    expect(array_map(fn ($c) => $c->expression, $checks))->toBe([
        'rating between 1 and 5',
        'rating_komunikasi between 1 and 5',
        'rating_akurasi between 1 and 5',
    ]);

    // MySQL names these incrementally, so the names are engine output, not input.
    expect($checks[0]->name)->toBeNull();
});

test('dihapus_at is TIMESTAMP with an explicit DEFAULT NULL, never DATETIME', function () {
    $spec = referenceSpec();

    foreach (['users', 'pasien'] as $table) {
        $column = $spec->table($table)->columns['dihapus_at'];

        expect($column->type)->toBe('timestamp');
        expect($column->type)->not->toBe('datetime');
        expect($column->nullable)->toBeTrue();
        expect($column->default)->toBe('NULL');
    }
});

test('a column with no DEFAULT clause is distinguishable from DEFAULT NULL', function () {
    $spec = referenceSpec();
    $users = $spec->table('users');

    expect($users->columns['dihapus_at']->default)->toBe('NULL');
    expect($users->columns['nama_lengkap']->default)->toBeNull();
});

test('a PRIMARY KEY column is treated as implicitly NOT NULL', function () {
    $spec = referenceSpec();

    // telemedicine_test.sql:59 writes no NOT NULL on master_provinsi.id; MySQL
    // prints one. Without the implication every such column would read as drift.
    expect($spec->table('master_provinsi')->columns['id']->nullable)->toBeFalse();
    expect($spec->table('master_agama')->columns['id']->nullable)->toBeFalse();
    // A column that is genuinely nullable stays nullable.
    expect($spec->table('users')->columns['email']->nullable)->toBeTrue();
});

test('inline UNIQUE is captured as an unnamed index, named keys are not', function () {
    $spec = referenceSpec();
    $users = $spec->table('users');

    $unnamed = array_values(array_filter($users->indexes, fn ($i) => ! $i->nameIsAuthoritative));
    expect($unnamed)->toHaveCount(3);
    expect(array_map(fn ($i) => $i->semanticKey(), $unnamed))->toBe([
        'UNIQUE (uuid)',
        'UNIQUE (email)',
        'UNIQUE (no_telepon)',
    ]);

    $named = array_values(array_filter($spec->tables['booking']->indexes, fn ($i) => $i->nameIsAuthoritative));
    expect(array_map(fn ($i) => $i->name, $named))->toContain('idx_booking_dokter', 'idx_booking_pasien');
});

test('the reused index name idx_icd10 is recorded on both tables it appears on', function () {
    $spec = referenceSpec();

    // telemedicine_test.sql:119 and :297 - legal in MySQL because index names are
    // unique per table, not per schema.
    expect($spec->table('master_icd10')->indexes)->toHaveCount(3);
    expect($spec->table('pasien_riwayat_penyakit')->indexes)->toHaveCount(2);

    $byName = static fn (array $indexes): array => array_values(array_map(
        fn ($i) => $i->semanticKey(),
        array_filter($indexes, fn ($i) => $i->name === 'idx_icd10'),
    ));

    expect($byName($spec->table('master_icd10')->indexes))->toBe(['INDEX (kode)']);
    expect($byName($spec->table('pasien_riwayat_penyakit')->indexes))->toBe(['INDEX (icd10_kode)']);
});

test('the deferred foreign key added by ALTER TABLE is folded into its table', function () {
    $spec = referenceSpec();
    $keys = $spec->table('pasien_tanda_vital')->foreignKeys;
    $deferred = end($keys);

    expect($deferred->name)->toBe('fk_vital_rm');
    expect($deferred->nameIsAuthoritative)->toBeTrue();
    expect($deferred->columns)->toBe(['rekam_medis_id']);
    expect($deferred->referencedTable)->toBe('rekam_medis');
    expect($deferred->onDelete)->toBe('SET NULL');
    // Absent ON UPDATE is RESTRICT, and it must be materialised rather than left
    // as "unknown" or the two sides would never line up.
    expect($deferred->onUpdate)->toBe('RESTRICT');
});

test('an inline foreign key with no ON DELETE is RESTRICT on both sides', function () {
    $spec = referenceSpec();

    expect($spec->table('master_kabupaten_kota')->foreignKeys[0]->onDelete)->toBe('RESTRICT');
    expect($spec->table('user_otp')->foreignKeys[0]->onDelete)->toBe('CASCADE');
});

test('comments, backticks and section markers do not confuse the scanner', function () {
    $parser = new SqlSchemaParser;
    $spec = $parser->parse(<<<'SQL'
        -- a line comment with a semicolon ; and a fake CREATE TABLE sneaky (
        # a hash comment; also ; CREATE TABLE sneaky2 (
        /* a block comment
           spanning lines; CREATE TABLE sneaky3 ( */
        CREATE TABLE `demo` (
          `id` BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, -- trailing comment; more ; text
          `label` VARCHAR(50) NOT NULL DEFAULT 'a;b' COMMENT 'has ; and , and () inside'
        ) ENGINE=InnoDB;
        SQL);

    expect($spec->tableNames())->toBe(['demo']);
    expect($spec->table('demo')->columns)->toHaveCount(2);
    expect($spec->table('demo')->columns['label']->default)->toBe("'a;b'");
});

test('a COMMENT containing SQL keywords is treated as text', function () {
    $parser = new SqlSchemaParser;
    $table = $parser->parseCreateTable(<<<'SQL'
        CREATE TABLE demo (
          booking_id BIGINT UNSIGNED NULL UNIQUE COMMENT 'NULL = fitur "Tanya Dokter" instan 24 jam',
          total_durasi_detik INT UNSIGNED NULL
        ) ENGINE=InnoDB
        SQL);

    expect($table->columns['booking_id']->nullable)->toBeTrue();
    expect($table->columns['total_durasi_detik']->unsigned)->toBeTrue();
    // The keywords inside the comment must not have produced a bogus extra index,
    // and the inline UNIQUE must still be seen.
    expect(array_map(fn ($i) => $i->semanticKey(), $table->indexes))->toBe(['UNIQUE (booking_id)']);
});

test('a deliberately malformed reference file errors loudly instead of reporting success', function () {
    $parser = new SqlSchemaParser;

    // No CREATE TABLE at all: a parser that understood nothing must refuse.
    expect(fn () => $parser->parse('SELECT 1;'))->toThrow(SchemaParseException::class);

    // Unterminated table body.
    expect(fn () => $parser->parse('CREATE TABLE broken ( id INT NOT NULL'))->toThrow(SchemaParseException::class);

    // Column with no type.
    expect(fn () => $parser->parse('CREATE TABLE broken ( id )'))->toThrow(SchemaParseException::class);

    // Unterminated string literal.
    expect(fn () => $parser->parse("CREATE TABLE broken ( a VARCHAR(4) NOT NULL DEFAULT 'oops )"))->toThrow(SchemaParseException::class);

    // ALTER TABLE against a table that does not exist.
    expect(fn () => $parser->parse('CREATE TABLE a (id INT NOT NULL); ALTER TABLE nope ADD CONSTRAINT fk_x FOREIGN KEY (id) REFERENCES a(id);'))
        ->toThrow(SchemaParseException::class);

    // ALTER TABLE clause the parser does not understand must not be skipped.
    expect(fn () => $parser->parse('CREATE TABLE a (id INT NOT NULL); ALTER TABLE a DROP COLUMN id;'))
        ->toThrow(SchemaParseException::class);

    // A duplicate table definition is an error, not a silent overwrite.
    expect(fn () => $parser->parse('CREATE TABLE a (id INT NOT NULL); CREATE TABLE a (id INT NOT NULL);'))
        ->toThrow(SchemaParseException::class);

    // An empty ENUM list is nonsense and must not be accepted.
    expect(fn () => $parser->parse('CREATE TABLE a (id ENUM() NOT NULL);'))->toThrow(SchemaParseException::class);
});

test('a missing trailing comma is an error, not a plausible-looking wrong model', function () {
    // Without this the `id` and `uuid` declarations merge, `users` silently loses a
    // column, and the parser reports 76 tables with 678 columns instead of 679 - a
    // green-looking, wrong answer. It has to fail loudly instead.
    $parser = new SqlSchemaParser;

    expect(fn () => $parser->parse(
        "CREATE TABLE users (\n  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT\n  uuid CHAR(36) NOT NULL UNIQUE,\n"
        ."  nama_lengkap VARCHAR(150) NOT NULL\n) ENGINE=InnoDB;",
    ))->toThrow(SchemaParseException::class);
});

test('a table body that swallowed the next statement is an error', function () {
    $parser = new SqlSchemaParser;

    expect(fn () => $parser->parse(
        "CREATE TABLE users (\n  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT\n  uuid CHAR(36) NOT NULL\n"
        ."CREATE TABLE roles (\n  id SMALLINT UNSIGNED PRIMARY KEY\n  PRIMARY KEY (id)\n) ENGINE=InnoDB;",
    ))->toThrow(SchemaParseException::class);
});

test('a quoted ENUM value that reads as a statement keyword is not a false alarm', function () {
    // telemedicine_test.sql:1121 - audit_log.aksi is ENUM('create','read',...).
    $spec = (new SqlSchemaParser)->parse(
        "CREATE TABLE audit_log (\n  aksi ENUM('create','read','update','delete') NOT NULL\n) ENGINE=InnoDB;",
    );

    expect($spec->table('audit_log')->columns['aksi']->type)
        ->toBe("enum('create','read','update','delete')");
});

test('ON DELETE SET NULL does not trip the swallowed-body guard', function () {
    $spec = (new SqlSchemaParser)->parse(
        "CREATE TABLE t (\n  id BIGINT UNSIGNED PRIMARY KEY\n  , o BIGINT UNSIGNED NULL\n"
        ."  , FOREIGN KEY (o) REFERENCES o(id) ON DELETE SET NULL\n) ENGINE=InnoDB;",
    );

    expect($spec->table('t')->foreignKeys[0]->onDelete)->toBe('SET NULL');
});

test('a missing or empty reference file is an error, not a green run', function () {
    $parser = new SqlSchemaParser;
    $empty = tempnam(sys_get_temp_dir(), 'sehatly-empty-').'.sql';
    file_put_contents($empty, "   \n");

    try {
        expect(fn () => $parser->parseFile(base_path('telemedicine_test.does-not-exist')))
            ->toThrow(SchemaParseException::class);
        expect(fn () => $parser->parseFile($empty))->toThrow(SchemaParseException::class);
    } finally {
        @unlink($empty);
    }
});

test('a deliberately corrupted copy of the reference yields a different table count', function () {
    $original = (string) file_get_contents(base_path('telemedicine_test.sql'));
    $corrupt = sys_get_temp_dir().'/sehatly-corrupt-'.getmypid().'.sql';

    try {
        // Rename one CREATE TABLE so it no longer registers under the same name.
        file_put_contents($corrupt, (string) preg_replace('/CREATE TABLE booking \(/', 'CREATE TABLE booking_renamed (', $original, 1));

        $corrupted = (new SqlSchemaParser)->parseFile($corrupt);

        expect($corrupted->tableNames())->toHaveCount(80);
        expect($corrupted->hasTable('booking'))->toBeFalse();
        expect($corrupted->hasTable('booking_renamed'))->toBeTrue();
        // Same count, different content: the count alone is not proof of parsing.
        expect($corrupted->table('booking_renamed')->columns)->toHaveCount(22);

        // Now actually drop a table. The worktree file is CRLF, so the mutation has
        // to be line-ending agnostic or it silently matches nothing.
        $truncated = (string) preg_replace(
            '/CREATE TABLE users \(\R.*?\R\) ENGINE=InnoDB;/s',
            '',
            $original,
            1,
        );

        expect($truncated)->not->toBe($original);

        $dropped = (new SqlSchemaParser)->parse($truncated);

        expect($dropped->tableNames())->toHaveCount(79);
        expect($dropped->hasTable('users'))->toBeFalse();
        expect($dropped->columnCount())->toBe(716 - 16);
    } finally {
        @unlink($corrupt);
    }
});

test('telemedicine_test.sql is byte-unchanged by every test in this file', function () {
    // The file is read-only law. Reading it must never write to it.
    $before = md5_file(base_path('telemedicine_test.sql'));
    referenceSpec();
    expect(md5_file(base_path('telemedicine_test.sql')))->toBe($before);
});

<?php

declare(strict_types=1);

use App\Models\ApotekStok;
use App\Models\Booking;
use App\Models\Dokter;
use App\Models\DokterFaskes;
use App\Models\Konsultasi;
use App\Models\KonsultasiChat;
use App\Models\LabPaketItem;
use App\Models\MasterSpesialisasi;
use App\Models\Pasien;
use App\Models\PasienTandaVital;
use App\Models\Permission;
use App\Models\PesananObatTracking;
use App\Models\RekamMedis;
use App\Models\Role;
use App\Models\User;
use App\Models\UserOtp;
use App\Support\Schema\ColumnSpec;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableModel;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tests\TestCase;

uses(TestCase::class);

/**
 * The model layer is only trustworthy if it was derived from the DDL, so every
 * expectation below is re-derived from `telemedicine_test.sql` at run time rather
 * than transcribed. A hand-written list of 76 tables would agree with the models
 * exactly as long as nobody edited either, which is the property A.21 showed is
 * worth nothing: `php -l`, `migrate:fresh` and a green unit suite all coexisted
 * with a swallowed column declaration.
 */
function modelFoundationSpec(): SchemaSpec
{
    return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'));
}

/**
 * Every class in `app/Models`, read from the filesystem so a 76th model cannot slip
 * in unnoticed and a deleted one cannot hide.
 *
 * @return list<class-string<Model>>
 */
function modelFoundationClasses(): array
{
    $files = glob(base_path('app/Models/*.php'));

    expect($files)->not->toBeFalse();

    $classes = array_map(
        static fn (string $file): string => 'App\\Models\\'.basename($file, '.php'),
        $files,
    );
    sort($classes);

    return $classes;
}

/**
 * @return array<string, class-string<Model>> keyed by DDL table name
 */
function modelFoundationByTable(): array
{
    $map = [];

    foreach (modelFoundationClasses() as $class) {
        $table = (new $class)->getTable();

        expect($map)->not->toHaveKey($table, $class.' and an earlier model both claim `'.$table.'`');

        $map[$table] = $class;
    }

    ksort($map);

    return $map;
}

/**
 * Every public method whose declared return type is an Eloquent relation.
 *
 * @param  class-string<Model>  $class
 * @return list<string>
 */
function modelFoundationRelationMethods(string $class): array
{
    $methods = [];

    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $type = $method->getReturnType();

        if ($method->isStatic() || ! $type instanceof ReflectionNamedType) {
            continue;
        }

        if (is_subclass_of($type->getName(), Relation::class)) {
            $methods[] = $method->getName();
        }
    }

    sort($methods);

    return $methods;
}

/**
 * Every declared relationship of one model, instantiated.
 *
 * @param  class-string<Model>  $class
 * @return array<string, Relation<Model, Model>>
 */
function modelFoundationRelations(string $class): array
{
    $model = new $class;
    $relations = [];

    foreach (modelFoundationRelationMethods($class) as $method) {
        $relation = $model->{$method}();

        expect($relation)->toBeInstanceOf(Relation::class, $class.'::'.$method.'() did not return a Relation');

        $relations[$method] = $relation;
    }

    return $relations;
}

/**
 * The casts a class declares itself, read through its `casts()` method.
 *
 * `getCasts()` is the wrong lens: it merges `[$primaryKey => $keyType]` for an
 * incrementing model, and `SoftDeletes::initializeSoftDeletes()` injects its own
 * `deleted_at` entry into the property. Neither is a choice this class made.
 *
 * @param  class-string<Model>  $class
 * @return array<string, string>
 */
function modelFoundationDeclaredCasts(string $class): array
{
    $method = new ReflectionMethod($class, 'casts');
    $method->setAccessible(true);

    return $method->invoke(new $class);
}

/**
 * @return list<array{0: string, 1: ColumnSpec}>
 */
function modelFoundationColumnsOfType(SchemaSpec $spec, string $base, ?bool $unsigned = null): array
{
    $found = [];

    foreach ($spec->tables as $name => $table) {
        foreach ($table->columns as $column) {
            if (explode('(', $column->type)[0] !== $base) {
                continue;
            }
            if ($unsigned !== null && $column->unsigned !== $unsigned) {
                continue;
            }

            $found[] = [$name, $column];
        }
    }

    return $found;
}

test('app/Models holds exactly one class per contract table', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();

    expect($map)->toHaveCount(76);
    expect($spec->tableNames())->toHaveCount(76);

    $missing = array_values(array_diff($spec->tableNames(), array_keys($map)));
    $extra = array_values(array_diff(array_keys($map), $spec->tableNames()));

    expect($missing)->toBe([], 'contract tables with no model: '.implode(', ', $missing));
    expect($extra)->toBe([], 'models pointing at no contract table: '.implode(', ', $extra));
});

test('every model declares its own table instead of inheriting one', function () {
    foreach (modelFoundationClasses() as $class) {
        $property = (new ReflectionClass($class))->getProperty('table');

        expect($property->getDeclaringClass()->getName())
            ->toBe($class, $class.' inherits its table name instead of declaring `protected $table`');
    }
});

test('all 76 models instantiate and every declared relation resolves', function () {
    $map = modelFoundationByTable();
    $checked = 0;

    foreach ($map as $table => $class) {
        $model = new $class;

        expect($model)->toBeInstanceOf(Model::class, $class);
        expect($model->getTable())->toBe($table);

        foreach (modelFoundationRelations($class) as $method => $relation) {
            expect($relation->getRelated())->toBeInstanceOf(Model::class, $class.'::'.$method);
            $checked++;
        }
    }

    // A loop that found no relations at all would satisfy the assertions above.
    expect($checked)->toBeGreaterThan(200);
    expect(modelFoundationRelationMethods(Booking::class))->toBe([
        'anggotaKeluarga',
        'dibuatOlehUser',
        'dokter',
        'faskes',
        'jadwal',
        'konsultasi',
        'pasien',
    ]);
});

test('a relation exists exactly where the DDL declares the foreign key', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();

    foreach ($spec->tables as $name => $table) {
        /** @var array<string, string> $declared column => referenced table */
        $declared = [];
        foreach ($table->foreignKeys as $fk) {
            $declared[$fk->columns[0]] = $fk->referencedTable;
        }

        /** @var array<string, list<string>> $modelled column => related tables */
        $modelled = [];
        foreach (modelFoundationRelations($map[$name]) as $method => $relation) {
            if ($relation instanceof BelongsToMany) {
                continue;
            }
            expect($relation instanceof BelongsTo || $relation instanceof HasOneOrMany)
                ->toBeTrue($name.'::'.$method.' is neither a BelongsTo nor a HasOneOrMany');

            $modelled[$relation->getForeignKeyName()][] = $relation->getRelated()->getTable();
        }

        foreach ($declared as $column => $target) {
            expect(in_array($target, $modelled[$column] ?? [], true))
                ->toBeTrue($name.'.'.$column.' has no relation to '.$target);

            // The reverse direction matters just as much: a `belongsTo` with no
            // `hasMany`/`hasOne` back is half a relationship. A pivot is reached
            // through `belongsToMany`, whose `getTable()` is the pivot itself.
            $inbound = array_values(array_filter(array_map(
                static fn (Relation $relation): ?string => $relation instanceof BelongsToMany
                    ? $relation->getTable()
                    : $relation->getRelated()->getTable(),
                modelFoundationRelations($map[$target]),
            )));

            expect(in_array($name, $inbound, true))
                ->toBeTrue($target.' has no relation back to '.$name.' ('.$column.')');
        }
    }
});

test('no relation is built on a column the DDL leaves unconstrained', function () {
    $spec = modelFoundationSpec();

    // A `belongsTo` keeps its key on this table; a `hasOne`/`hasMany` keeps it on the
    // child. Both sides have to be a real foreign key, or the join is a guess.
    $declared = [];
    foreach ($spec->tables as $name => $table) {
        foreach ($table->foreignKeys as $fk) {
            $declared[$name][$fk->columns[0]] = true;
        }
    }

    foreach (modelFoundationByTable() as $name => $class) {
        foreach (modelFoundationRelations($class) as $method => $relation) {
            if ($relation instanceof BelongsToMany) {
                continue;
            }

            $key = $relation->getForeignKeyName();
            $owner = $relation instanceof BelongsTo ? $name : $relation->getRelated()->getTable();

            expect(array_key_exists($key, $declared[$owner] ?? []))
                ->toBeTrue($class.'::'.$method.'() joins on '.$owner.'.'.$key.', which has no FOREIGN KEY');
        }
    }
});

test('the pivot shortcuts point at the declared pivot tables', function () {
    $spec = modelFoundationSpec();

    /** @var list<array{0: class-string<Model>, 1: string, 2: string, 3: string, 4: string, 5: string}> $expected */
    $expected = [
        [User::class, 'roles', 'roles', 'user_roles', 'role_id', 'user_id'],
        [Role::class, 'users', 'users', 'user_roles', 'user_id', 'role_id'],
        [Role::class, 'permissions', 'permissions', 'role_permissions', 'permission_id', 'role_id'],
        [Permission::class, 'roles', 'roles', 'role_permissions', 'role_id', 'permission_id'],
    ];

    foreach ($expected as [$class, $method, $related, $pivot, $relatedKey, $parentKey]) {
        $relation = (new $class)->{$method}();

        expect($relation)->toBeInstanceOf(BelongsToMany::class, $class.'::'.$method);
        expect($relation->getRelated()->getTable())->toBe($related);
        // BelongsToMany::getTable() is the intermediate table, not the related one.
        expect($relation->getTable())->toBe($pivot);
        expect($relation->getRelatedPivotKeyName())->toBe($relatedKey);
        expect($relation->getQualifiedForeignPivotKeyName())->toBe($pivot.'.'.$parentKey);

        $pivotColumns = array_keys($spec->table($pivot)->columns);
        expect(in_array($relatedKey, $pivotColumns, true))->toBeTrue($pivot.'.'.$relatedKey);
        expect(in_array($parentKey, $pivotColumns, true))->toBeTrue($pivot.'.'.$parentKey);
    }
});

test('the bare columns the DDL leaves unconstrained get no relation', function () {
    // Each of these reads like a foreign key and several later todos will want one.
    // None is declared, so a `belongsTo` here would be a guess that silently joins
    // the wrong table.
    $bare = [
        'artikel.reviewer_user_id' => 'reviewerUser',
        'audit_log.user_id' => 'user',
        'audit_log.record_id' => 'record',
        'pasien_alergi.dicatat_oleh_user_id' => 'dicatatOlehUser',
        'pasien_penjamin.faskes_rujukan_id' => 'faskesRujukan',
        'pasien.provinsi_id' => 'provinsi',
        'pasien.kelurahan_id' => 'kelurahan',
        'rekam_medis_lampiran.diunggah_oleh' => 'diunggahOleh',
        'lab_hasil.diperiksa_oleh' => 'diperiksaOleh',
        'resep.konsultasi_id' => 'konsultasi',
        'resep.rekam_medis_id' => 'rekamMedis',
        'surat_keterangan.konsultasi_id' => 'konsultasi',
        'lab_permintaan.rekam_medis_id' => 'rekamMedis',
        'lab_permintaan.konsultasi_id' => 'konsultasi',
        'klaim_bpjs.booking_id' => 'booking',
        'klaim_bpjs.rekam_medis_id' => 'rekamMedis',
        'rujukan.faskes_asal_id' => 'faskesAsal',
        'invoice.referensi_id' => 'referensi',
    ];

    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();

    foreach ($bare as $qualified => $method) {
        [$table, $column] = explode('.', $qualified);

        // Precondition: the column really is a bare one, so this cannot pass by
        // asserting against a table that has since gained the constraint.
        $declared = [];
        foreach ($spec->table($table)->foreignKeys as $fk) {
            $declared = array_merge($declared, $fk->columns);
        }
        expect(in_array($column, $declared, true))->toBeFalse($qualified.' does have a foreign key now');

        expect(in_array($method, modelFoundationRelationMethods($map[$table]), true))
            ->toBeFalse($map[$table].' grew a `'.$method.'()` on the bare `'.$column.'`');
    }
});

test('TINYINT(1) is boolean and TINYINT UNSIGNED is not', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();

    $flags = modelFoundationColumnsOfType($spec, 'tinyint', false);
    $counters = modelFoundationColumnsOfType($spec, 'tinyint', true);

    // The parser folds MySQL's deprecated integer display width away, so `tinyint`
    // covers both spellings and the signedness flag is the only thing that tells
    // `is_utama` from `spo2`. Both counts are cross-checked against the raw DDL.
    expect($flags)->toHaveCount(30);
    expect($counters)->toHaveCount(25);

    foreach ($flags as [$table, $column]) {
        $casts = modelFoundationDeclaredCasts($map[$table]);

        expect(array_key_exists($column->name, $casts))
            ->toBeTrue($table.'.'.$column->name.' is a flag but has no cast');
        expect($casts[$column->name])->toBe('boolean');
    }

    foreach ($counters as [$table, $column]) {
        expect(array_key_exists($column->name, modelFoundationDeclaredCasts($map[$table])))
            ->toBeFalse($table.'.'.$column->name.' is a number, not a flag');
    }
});

test('a 0-100 percentage is never cast to boolean', function () {
    // telemedicine_test.sql:321 - pasien_tanda_vital.spo2 TINYINT UNSIGNED.
    $vitals = modelFoundationDeclaredCasts(PasienTandaVital::class);

    expect(array_key_exists('spo2', $vitals))->toBeFalse();
    expect($vitals['suhu'])->toBe('decimal:1');

    // Three more unsigned numerics that read like flags.
    $numeric = [
        ['jumlah_ulasan', Dokter::class],
        ['jumlah_konsultasi', Dokter::class],
        ['total_durasi_detik', Konsultasi::class],
        ['file_ukuran_kb', KonsultasiChat::class],
    ];

    foreach ($numeric as [$column, $class]) {
        $casts = modelFoundationDeclaredCasts($class);

        expect(($casts[$column] ?? null) === 'boolean')
            ->toBeFalse($class.'::'.$column);
    }
});

test('decimal, date, datetime, timestamp and json casts come from the DDL type', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();

    $decimals = modelFoundationColumnsOfType($spec, 'decimal');
    foreach ($decimals as [$table, $column]) {
        $args = explode(',', substr($column->type, (int) strpos($column->type, '(') + 1, -1));
        $casts = modelFoundationDeclaredCasts($map[$table]);

        expect($casts[$column->name] ?? null)->toBe('decimal:'.$args[1], $table.'.'.$column->name);
    }
    expect($decimals)->toHaveCount(37);

    $dates = modelFoundationColumnsOfType($spec, 'date');
    foreach ($dates as [$table, $column]) {
        $casts = modelFoundationDeclaredCasts($map[$table]);

        // `date`, never `datetime`: a datetime cast shifts tanggal_lahir and
        // tanggal_kunjungan across timezones.
        expect($casts[$column->name] ?? null)->toBe('date', $table.'.'.$column->name);
    }
    expect($dates)->toHaveCount(19);

    $datetimes = modelFoundationColumnsOfType($spec, 'datetime');
    foreach ($datetimes as [$table, $column]) {
        // No DATETIME column is a lifecycle column, so every one is cast by hand.
        expect(modelFoundationDeclaredCasts($map[$table])[$column->name] ?? null)
            ->toBe('datetime', $table.'.'.$column->name);
    }
    expect($datetimes)->toHaveCount(27);

    $json = modelFoundationColumnsOfType($spec, 'json');
    foreach ($json as [$table, $column]) {
        expect(modelFoundationDeclaredCasts($map[$table])[$column->name] ?? null)
            ->toBe('array', $table.'.'.$column->name);
    }
    expect($json)->toHaveCount(6);
});

test('the 57 TIMESTAMP columns are all covered, none of them twice', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $timestamps = modelFoundationColumnsOfType($spec, 'timestamp');

    expect($timestamps)->toHaveCount(57);

    foreach ($timestamps as [$table, $column]) {
        $class = $map[$table];
        $declared = modelFoundationDeclaredCasts($class);
        $lifecycle = in_array($column->name, ['dibuat_at', 'diubah_at', 'dihapus_at', 'terkirim_at'], true);

        expect($lifecycle)->toBeTrue($table.'.'.$column->name.' is not a lifecycle column');

        // A lifecycle column is converted by the constants, not by a cast entry, so
        // declaring one would be a second copy of a fact the class already states.
        // `apotek_stok` is the single exception: it is not timestamped, so nothing
        // else would convert its `diubah_at`. `dihapus_at` needs no entry either -
        // `SoftDeletes::initializeSoftDeletes()` injects one.
        $expectsCast = $table === 'apotek_stok';

        expect(array_key_exists($column->name, $declared))
            ->toBe($expectsCast, $table.'.'.$column->name.' cast is wrong');

        if ($expectsCast) {
            expect($declared[$column->name])->toBe('datetime');
        }
    }
});

test('every lifecycle column still hydrates as Carbon', function () {
    // `hasCast()` in laravel/framework 13 reads `$casts` and nothing else - it no
    // longer consults `getDates()` - so the constants are checked behaviourally.
    // `transformModelValue()` converts on `in_array($key, $this->getDates(), false)`.
    $cases = [
        [Booking::class, 'dibuat_at'],
        [Booking::class, 'diubah_at'],
        [UserOtp::class, 'dibuat_at'],
        [KonsultasiChat::class, 'terkirim_at'],
        [User::class, 'dihapus_at'],
        [Pasien::class, 'dihapus_at'],
        [ApotekStok::class, 'diubah_at'],
        [PesananObatTracking::class, 'waktu'],
    ];

    foreach ($cases as [$class, $column]) {
        $model = new $class;
        $model->setRawAttributes([$column => '2026-01-02 03:04:05'], true);

        expect($model->getAttribute($column))
            ->toBeInstanceOf(DateTimeInterface::class, $class.'::'.$column);
    }
});

test('every ENUM column is a plain string cast', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $count = 0;

    foreach ($spec->tables as $name => $table) {
        foreach ($table->columns as $column) {
            if (! str_starts_with($column->type, 'enum(')) {
                continue;
            }

            expect(modelFoundationDeclaredCasts($map[$name])[$column->name] ?? null)
                ->toBe('string', $name.'.'.$column->name);
            $count++;
        }
    }

    expect($count)->toBe(69);
});

test('no cast uses the enum: string list, which laravel/framework 13 dropped', function () {
    // `HasAttributes::isEnumCastable()` now requires `enum_exists($castType)`, so
    // `'tipe' => 'enum:pasien,dokter'` is a silent no-op - neither a primitive cast
    // nor a class castable. It reads like validation and validates nothing, which is
    // worse than no cast at all.
    $found = [];

    foreach (modelFoundationClasses() as $class) {
        foreach (modelFoundationDeclaredCasts($class) as $column => $cast) {
            if (str_starts_with($cast, 'enum:')) {
                $found[] = $class.'.'.$column;
            }
        }
    }

    expect($found)->toBe([]);
});

test('the audit columns are named exactly as each table declares them', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $shapes = ['both' => 0, 'createdOnly' => 0, 'updatedOnly' => 0, 'neither' => 0];

    foreach ($spec->tables as $name => $table) {
        $class = $map[$name];
        $model = new $class;
        $columns = array_keys($table->columns);

        $hasDibuat = in_array('dibuat_at', $columns, true);
        $hasDiubah = in_array('diubah_at', $columns, true);
        $hasDihapus = in_array('dihapus_at', $columns, true);
        $hasTerkirim = in_array('terkirim_at', $columns, true);
        $timestamps = $hasDibuat || $hasTerkirim;

        expect($model->usesTimestamps())->toBe($timestamps);

        if ($timestamps) {
            expect($model->getCreatedAtColumn())->toBe($hasDibuat ? 'dibuat_at' : 'terkirim_at');
            // Load-bearing: `Model::updateTimestamps()` writes this constant on every
            // save, so a timestamped table with no `diubah_at` must null it.
            expect($model->getUpdatedAtColumn())->toBe($hasDiubah ? 'diubah_at' : null);
        }

        expect(in_array(SoftDeletes::class, class_uses_recursive($class), true))->toBe($hasDihapus);

        if ($hasDihapus) {
            expect($model->getDeletedAtColumn())->toBe('dihapus_at');
        }

        match (true) {
            $hasDibuat && $hasDiubah => $shapes['both']++,
            $hasDibuat => $shapes['createdOnly']++,
            $hasDiubah => $shapes['updatedOnly']++,
            default => $shapes['neither']++,
        };
    }

    // 17 + 19 + 1 + 39 = 76. telemedicine_test.sql is the only source for these. The
    // 39 includes konsultasi_chat, whose only stamp is `terkirim_at`; F08's
    // `konsultasi_baca` joined the `both` group.
    expect($shapes)->toBe([
        'both' => 17,
        'createdOnly' => 19,
        'updatedOnly' => 1,
        'neither' => 39,
    ]);
});

test('the plan\'s "28 tables with neither" is 38 in the DDL', function () {
    // The plan's Scope block enumerates 29 names while claiming 28, and omits nine
    // tables the DDL plainly gives no timestamp column. The count is what matters:
    // it is what a loop over the plan's list would have left unconfigured.
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $neither = [];

    foreach ($spec->tables as $name => $table) {
        $columns = array_keys($table->columns);

        if (! in_array('dibuat_at', $columns, true)
            && ! in_array('diubah_at', $columns, true)
            && ! in_array('terkirim_at', $columns, true)) {
            $neither[] = $name;
        }
    }

    expect($neither)->toHaveCount(38);

    // Nine of them are absent from the plan's enumeration.
    $omittedByThePlan = [
        'artikel_kategori', 'master_kabupaten_kota', 'master_kecamatan', 'master_kelurahan',
        'master_metode_pembayaran', 'master_penjamin', 'master_promo', 'master_provinsi',
        'persetujuan_pdp',
    ];

    foreach ($omittedByThePlan as $name) {
        expect(in_array($name, $neither, true))->toBeTrue($name.' is one of the nine the plan omits');
    }

    foreach ($neither as $name) {
        expect((new $map[$name])->usesTimestamps())->toBeFalse($name);
    }
});

test('the 19 tables with dibuat_at alone declare CREATED_AT and no UPDATED_AT', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $checked = [];

    foreach ($spec->tables as $name => $table) {
        $columns = array_keys($table->columns);

        if (! in_array('dibuat_at', $columns, true) || in_array('diubah_at', $columns, true)) {
            continue;
        }

        $model = new $map[$name];
        expect($model->getCreatedAtColumn())->toBe('dibuat_at', $name);
        expect($model->getUpdatedAtColumn())->toBeNull($name);
        expect($model->usesTimestamps())->toBeTrue($name);
        $checked[] = $name;
    }

    expect($checked)->toHaveCount(19);
    // telemedicine_test.sql:1129 gives audit_log a dibuat_at and nothing else.
    expect(in_array('audit_log', $checked, true))->toBeTrue();
    expect(in_array('notifikasi', $checked, true))->toBeTrue();
    expect(in_array('user_refresh_tokens', $checked, true))->toBeTrue();
});

test('apotek_stok keeps diubah_at without pretending to be timestamped', function () {
    $stock = new ApotekStok;

    expect($stock->usesTimestamps())->toBeFalse();
    expect($stock->getUpdatedAtColumn())->toBe('diubah_at');
    // With timestamps off nothing else would convert this column, so it is cast.
    expect(modelFoundationDeclaredCasts(ApotekStok::class)['diubah_at'])->toBe('datetime');
});

test('konsultasi_chat writes its creation stamp to terkirim_at', function () {
    $chat = new KonsultasiChat;

    expect($chat->usesTimestamps())->toBeTrue();
    expect($chat->getCreatedAtColumn())->toBe('terkirim_at');
    expect($chat->getUpdatedAtColumn())->toBeNull();
});

test('pesanan_obat_tracking is not timestamped at all', function () {
    $tracking = new PesananObatTracking;

    expect($tracking->usesTimestamps())->toBeFalse();
    expect(modelFoundationDeclaredCasts(PesananObatTracking::class))
        ->toBe(['waktu' => 'datetime']);
});

test('the composite-key pivots declare both columns and never auto-increment', function () {
    $map = modelFoundationByTable();

    $expected = [
        'dokter_faskes' => ['dokter_id', 'faskes_id'],
        'lab_paket_item' => ['paket_id', 'tindakan_id'],
        'role_permissions' => ['role_id', 'permission_id'],
        'user_roles' => ['user_id', 'role_id'],
    ];

    expect($expected)->toHaveCount(4);

    foreach ($expected as $table => $key) {
        $model = new $map[$table];

        expect($model->getKeyName())->toBe($key, $table);
        expect($model->getIncrementing())->toBeFalse($table);
        expect($model->getKeyType())->toBe('string', $table);
    }

    // A composite-key pivot is not a `find(1)` model. The plan expected `null`; in
    // laravel/framework 13 it is a `TypeError`, because `Model::qualifyColumn()`
    // calls `str_contains()` on whatever `getKeyName()` returns. Failing before the
    // query is the better of the two outcomes: the alternative, leaving
    // `$primaryKey = 'id'`, would send `where id = ?` to a table with no `id` column
    // and only fail once the database is reached.
    expect(static fn (): mixed => DokterFaskes::query()->find(1))->toThrow(TypeError::class);
    expect(static fn (): mixed => LabPaketItem::query()->find(1))->toThrow(TypeError::class);
});

test('a non-auto-increment integer key still reports an integer key type', function () {
    $spec = modelFoundationSpec();
    $map = modelFoundationByTable();
    $checked = [];

    foreach ($spec->tables as $name => $table) {
        $primary = null;
        foreach ($table->indexes as $index) {
            if ($index->type === 'PRIMARY') {
                $primary = $index;
            }
        }

        if ($primary === null || count($primary->columns) !== 1) {
            continue;
        }

        $model = new $map[$name];

        if ($table->columns[$primary->columns[0]]->autoIncrement) {
            expect($model->getIncrementing())->toBeTrue($name);

            continue;
        }

        expect($model->getIncrementing())->toBeFalse($name);
        expect($model->getKeyType())->toBe('int', $name);
        $checked[] = $name;
    }

    expect($checked)->toBe([
        'master_agama',
        'master_golongan_darah',
        'master_hubungan_keluarga',
        'master_pendidikan',
        'master_status_pernikahan',
    ]);
});

test('an auto-increment single integer key is left entirely alone', function () {
    $model = new MasterSpesialisasi;

    expect($model->getIncrementing())->toBeTrue();
    expect($model->getKeyType())->toBe('int');
    expect($model->getKeyName())->toBe('id');
});

test('the uuid hook mints a 36-character value on insert', function () {
    foreach ([RekamMedis::class, User::class] as $class) {
        $model = new $class;

        expect($model->getAttribute('uuid'))->toBeNull();

        // Fire the registered `creating` listener without touching the database.
        $model->getEventDispatcher()->dispatch('eloquent.creating: '.$class, $model);

        expect($model->getAttribute('uuid'))->toBeString($class);
        expect($model->getAttribute('uuid'))->toHaveLength(36, $class);
    }
});

test('a uuid supplied by the caller is not overwritten', function () {
    $model = new RekamMedis;
    $model->setAttribute('uuid', '11111111-2222-3333-4444-555555555555');

    $model->getEventDispatcher()->dispatch('eloquent.creating: '.RekamMedis::class, $model);

    expect($model->getAttribute('uuid'))->toBe('11111111-2222-3333-4444-555555555555');
});

test('the whereUuid scope resolves against the real column', function () {
    $query = (new RekamMedis)->whereUuid('11111111-2222-3333-4444-555555555555');

    expect($query->getQuery()->wheres)->toHaveCount(1);
    expect($query->toSql())->toContain('`uuid`');
});

test('User keeps the auth stack and drops the scaffold contracts', function () {
    $model = new User;

    expect($model)->toBeInstanceOf(AuthenticatableModel::class);
    expect($model)->toBeInstanceOf(Authenticatable::class);
    // The scaffold's contracts are gone: telemedicine_test.sql:144-145 gives the row
    // `telepon_terverifikasi` / `email_terverifikasi`, not an `email_verified_at`,
    // and there are no `two_factor_*` or passkey columns at all.
    expect($model instanceof MustVerifyEmail)->toBeFalse();

    // **This line used to be vacuous and todo 30 is why.** It read
    // `expect($model instanceof PasskeyUser)->toBeFalse()` against
    // `Laravel\Fortify\Contracts\PasskeyUser`, and `instanceof` against a class
    // that does not exist is simply false - it cannot fail, so it proved nothing
    // while reading as if it proved something. Todo 30 uninstalled the package
    // that declared the interface, so the honest replacement is to assert the
    // interface is gone and then to pin the model's implemented-interface list
    // exactly, which DOES fail if any contract is added.
    expect(interface_exists('Laravel\Fortify\Contracts\PasskeyUser'))->toBeFalse();

    // The same argument applies to every other Fortify contract the scaffold
    // model implemented; each is asserted absent by name rather than by
    // `instanceof`, which would be equally vacuous, and the check is over the
    // model's real interface list so re-adding one would fail here.
    $implemented = array_values(class_implements($model));

    foreach ([
        'Laravel\Fortify\PasskeyAuthenticatable',
        'Laravel\Fortify\TwoFactorAuthenticatable',
        'Laravel\Fortify\Contracts\TwoFactorAuthenticatable',
        'Laravel\Fortify\Contracts\PasskeyUser',
    ] as $removed) {
        expect(interface_exists($removed))->toBeFalse($removed.' still exists.');
        expect($implemented)->not->toContain($removed);
    }

    // The two the model legitimately keeps, so the list above is a subtraction
    // rather than an emptiness: `Authenticatable` is the whole point of the
    // class, and `CanResetPassword` arrives with
    // `Illuminate\Foundation\Auth\User` rather than being added here.
    expect($implemented)->toContain(Authenticatable::class)
        ->and($implemented)->toContain('Illuminate\Contracts\Auth\CanResetPassword');

    $used = class_uses_recursive(User::class);
    foreach ([HasApiTokens::class, Notifiable::class, SoftDeletes::class] as $trait) {
        expect(in_array($trait, $used, true))->toBeTrue($trait.' is not used by User');
    }

    expect($model->getTable())->toBe('users');
    expect($model->getCreatedAtColumn())->toBe('dibuat_at');
    expect($model->getUpdatedAtColumn())->toBe('diubah_at');
    expect($model->getDeletedAtColumn())->toBe('dihapus_at');
    expect($model->usesTimestamps())->toBeTrue();

    expect($model->getFillable())->toBe([
        'nama_lengkap',
        'no_telepon',
        'email',
        'kata_sandi_hash',
        'tipe',
        'status',
        'foto_profil',
        'bahasa',
        'telepon_terverifikasi',
        'email_terverifikasi',
    ]);
    expect($model->getHidden())->toBe(['kata_sandi_hash']);

    $casts = modelFoundationDeclaredCasts(User::class);
    expect($casts['tipe'])->toBe('string');
    expect($casts['status'])->toBe('string');
    expect($casts['telepon_terverifikasi'])->toBe('boolean');
    expect($casts['email_terverifikasi'])->toBe('boolean');
    expect($casts['last_login_at'])->toBe('datetime');
});

test('only users and pasien soft-delete, because only they have dihapus_at', function () {
    $pasien = new Pasien;

    expect($pasien->getCreatedAtColumn())->toBe('dibuat_at');
    expect($pasien->getUpdatedAtColumn())->toBe('diubah_at');
    expect($pasien->getDeletedAtColumn())->toBe('dihapus_at');

    $softDeleting = [];
    foreach (modelFoundationByTable() as $table => $class) {
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $softDeleting[] = $table;
        }
    }

    // telemedicine_test.sql:148 and :249 are the only two dihapus_at columns.
    expect($softDeleting)->toBe(['pasien', 'users']);
});

test('each model names the table and the DDL line it was generated from', function () {
    $spec = modelFoundationSpec();

    foreach (modelFoundationByTable() as $table => $class) {
        $doc = (new ReflectionClass($class))->getDocComment();

        expect($doc)->toBeString();
        expect(str_contains($doc, '`'.$table.'`'))->toBeTrue($class.' names the wrong table');
        expect(str_contains($doc, 'telemedicine_test.sql:'.$spec->table($table)->line))
            ->toBeTrue($class.' cites the wrong DDL line');
    }
});

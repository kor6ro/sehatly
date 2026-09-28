<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Support\Schema\SqlSchemaParser;
use App\Support\Schema\TableSpec;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Which tables this project audits, derived rather than declared.
 *
 * The interesting question behind todo 43 is not "which tables should have an
 * audit log" but "which tables hold somebody's data". That question has an
 * answer in `telemedicine_test.sql` and it is the foreign keys: start at
 * `pasien` and `users` and follow every `FOREIGN KEY` outward, and the set you
 * land on is, by the schema's own construction, everything reachable from a
 * person.
 *
 * A hand-written list of the audited tables would be dozens of chances to
 * forget one the next time somebody adds a column or a feature, and dozens of
 * lines to argue about. Deriving it means a new table is in scope the day its
 * foreign key lands, with no edit here at all - which is the property the todo
 * asks for, expressed as a mechanism rather than as a promise.
 *
 * The two roots are chosen because they are the two tables a person IS: one
 * `users` row is an account whether or not a patient record exists, and one
 * `pasien` row is a patient whether or not they ever logged in. A closure from
 * only one of them would silently drop the other's subtree.
 *
 * Note what the closure excludes for free: `roles`, `permissions`, `faskes`,
 * `apotek_stok`, `master_agama`, and the catalogue tables carry no foreign key
 * to a person, so they are not audited. Neither is `audit_log` itself, which is
 * what stops the observer observing its own writes.
 *
 * Parsing the SQL costs roughly 60ms, so the result is memoised per process.
 * The file is a build-time constant, not a runtime input.
 *
 * @see \Tests\Feature\Audit\RegistrationTest
 */
final class AuditScope
{
    /**
     * In-scope tables that are nonetheless NOT audited, each with its reason.
     *
     * All three are in the closure and all three are excluded on purpose, for
     * two different reasons.
     *
     * The first two hang off `users` and hold only credentials: a bcrypt hash of
     * an OTP (telemedicine_test.sql:182, whose own COMMENT says 'Simpan hash,
     * bukan OTP asli') and a session token hash (:207). An audit row recording
     * a password reset would be a second place the credential's existence is
     * recorded, and the columns that make them interesting are precisely the
     * ones that must never be written anywhere.
     *
     * The third, `akses_rekam_medis_log`, is in the closure because it
     * references `rekam_medis` - and it is excluded for a different reason: it
     * is ALREADY a purpose-built access log with its own writer (:1147), so
     * observing it here would double-log every record access and duplicate its
     * rows into a table readable under the broader `audit.lihat` permission.
     *
     * Excluding a table is the honest option rather than denying every column
     * in it, because a deny-list that denies every column is a statement that
     * the table should not be audited at all, merely expressed the long way.
     *
     * @var array<string, string>
     */
    public const NOT_AUDITED = [
        'user_otp' => 'Holds only kode_hash (telemedicine_test.sql:182 COMMENT \'Simpan hash, bukan OTP asli\'), an expiry, and a used flag. An audit row would be a second record that a credential existed, and its only interesting column is the one that must never be written anywhere.',
        'user_refresh_tokens' => 'Holds only token_hash (:207), an expiry, and a revoked flag. The same credential argument as user_otp, with a longer-lived secret: every write to this table is a row that says "this session hash is still valid".',
        'akses_rekam_medis_log' => 'Already a purpose-built access log, with its own writer and five read-purpose ENUM values at telemedicine_test.sql:1147. It is in the person closure because it references rekam_medis, but observing it through the generic observer would double-log every record access and copy its rows into a table readable under the broader audit permission. The two tables mean different things: this one answers "who read this record", audit_log answers "who changed what".',
    ];

    /**
     * The tables this class hands to the registrar, once the closure is derived.
     *
     * @var list<string>|null
     */
    private static ?array $closure = null;

    /**
     * table => fully-qualified model class, memoised.
     *
     * @var array<string, string>|null
     */
    private static ?array $classMap = null;

    /**
     * The classes for {@see auditedTables()}, memoised.
     *
     * @var list<string>|null
     */
    private static ?array $auditedClasses = null;

    /**
     * Every table reachable from a person by following foreign keys, sorted.
     *
     * @return list<string>
     */
    public static function personClosure(): array
    {
        if (self::$closure !== null) {
            return self::$closure;
        }

        // Referenced table => the tables that reference it. Built from the DDL
        // rather than assumed, so a table that was dropped still resolves.
        $referencedBy = [];

        foreach (self::tables() as $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                $referencedBy[$foreignKey->referencedTable][] = $table->name;
            }
        }

        // A depth-first walk. The visited set is what makes a cycle in the schema
        // a non-event rather than a hang; the DDL has none today, but a walk that
        // depends on there being none is a walk that will hang the day there is.
        $seen = [];
        $stack = ['pasien', 'users'];

        while ($stack !== []) {
            $table = array_pop($stack);

            if (isset($seen[$table])) {
                continue;
            }

            $seen[$table] = true;

            foreach ($referencedBy[$table] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        $closure = array_keys($seen);

        sort($closure);

        return self::$closure = $closure;
    }

    /**
     * The tables that are actually observed: the closure minus the declared exclusions.
     *
     * @return list<string>
     */
    public static function auditedTables(): array
    {
        return array_values(array_diff(self::personClosure(), array_keys(self::NOT_AUDITED)));
    }

    /**
     * The model classes for {@see auditedTables()}, skipping any with no model.
     *
     * @return list<string>
     */
    public static function auditedModels(): array
    {
        if (self::$auditedClasses !== null) {
            return self::$auditedClasses;
        }

        $classes = [];

        foreach (self::auditedTables() as $table) {
            $class = self::classFor($table);

            // A table in the closure with no model cannot be written through
            // Eloquent, so there is no event to observe. Skipping is correct
            // rather than lenient: there is nothing that could have produced a
            // row to log.
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return self::$auditedClasses = $classes;
    }

    /**
     * The model class whose table is `$table`, or null when there is none.
     *
     * Built by asking each model on disk what its table is rather than by
     * assuming `Str::snake(class_basename($model))`, because six models break
     * that assumption: `User` is `users`, `Permission` is `permissions`, `Role`
     * is `roles`, and so on. Inverting from the filesystem makes the map total
     * by construction, so a new model is covered the day it is written.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    public static function classFor(string $table): ?string
    {
        return self::classMap()[$table] ?? null;
    }

    /**
     * table => model class, built once from the models on disk.
     *
     * The table is read from the class's DEFAULT `protected $table` property
     * rather than from an instance. Instantiating every model to ask it a
     * question about its own schema is both wasteful - it boots all 75, at
     * every application boot, because the registrar runs in a provider - and
     * unsafe: a `booted()` hook anywhere in `app/Models` would then be running
     * during service-provider boot, and the first one that constructed its own
     * model would re-enter `bootIfNotBooted()`.
     *
     * A model that does not declare `$table` is named by the framework's own
     * convention, copied from `Model::getTable()`. The test suite asserts this
     * reflection-derived map against the instantiated one for every model on
     * disk, so a model that declares its table in a way reflection cannot see
     * fails loudly instead of silently dropping out of the scope.
     *
     * @return array<string, string>
     */
    private static function classMap(): array
    {
        if (self::$classMap !== null) {
            return self::$classMap;
        }

        $map = [];

        foreach (self::modelClasses() as $class) {
            $map[self::tableFor($class)] = $class;
        }

        return self::$classMap = $map;
    }

    /**
     * The table a model class names, without instantiating it.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    public static function tableFor(string $class): string
    {
        $declared = (new ReflectionClass($class))->getDefaultProperties()['table'] ?? null;

        if (is_string($declared) && $declared !== '') {
            return $declared;
        }

        return Str::snake(Str::pluralStudly(class_basename($class)));
    }

    /**
     * Every class under `app/Models`, memoised.
     *
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private static function modelClasses(): array
    {
        $classes = [];

        foreach ((array) glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename((string) $file, '.php');

            if (class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * The parsed reference schema, memoised.
     *
     * @return list<TableSpec>
     */
    private static function tables(): array
    {
        return (new SqlSchemaParser)->parseFile(base_path('telemedicine_test.sql'))->tables;
    }

    /**
     * Forget the memoised derivation. Test-support only.
     */
    public static function flush(): void
    {
        self::$closure = null;
        self::$classMap = null;
        self::$auditedClasses = null;
    }
}

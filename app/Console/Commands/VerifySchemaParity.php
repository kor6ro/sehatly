<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Schema\Discrepancy;
use App\Support\Schema\ExtraTableRegistry;
use App\Support\Schema\LiveSchemaReader;
use App\Support\Schema\SchemaDiffer;
use App\Support\Schema\SchemaSpec;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only parity check between `telemedicine_test.sql` and the live schema.
 *
 * The command never writes. It issues nothing but `SELECT` against
 * `information_schema` and `SHOW CREATE TABLE`, against the database the
 * connection is configured to use. It never runs a migration, never imports the
 * reference SQL, and never creates, alters or drops anything.
 *
 * Exit codes: 0 exact match, 1 drift, 2 the verifier could not run (missing or
 * unparseable reference, unreachable database). A run that cannot understand its
 * inputs must never look green.
 */
class VerifySchemaParity extends Command
{
    protected $signature = 'sehatly:verify-schema
        {--tables= : Comma-separated subset of expected tables to verify (default: all)}
        {--sql= : Path to the reference SQL (default: telemedicine_test.sql at the project root)}
        {--notes= : Path to the extra-table registry (default: docs/schema-notes.md)}
        {--json : Emit a machine-readable JSON report on stdout}';

    protected $description = 'Diff the live information_schema against telemedicine_test.sql (read-only, non-zero on drift)';

    public function handle(SqlSchemaParser $parser, LiveSchemaReader $reader, SchemaDiffer $differ): int
    {
        $json = (bool) $this->option('json');
        $referencePath = (string) ($this->option('sql') ?: base_path('telemedicine_test.sql'));
        $notesPath = (string) ($this->option('notes') ?: base_path('docs/schema-notes.md'));
        $onlyTables = $this->requestedTables();

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver !== 'mysql') {
                throw new \RuntimeException(
                    'The schema is MySQL-8-only (ENUM, JSON, unsigned ints, inline INDEX, CHECK, VIEW, GROUP_CONCAT).'
                    .' The configured connection is "'.$driver.'".',
                );
            }

            $database = (string) $connection->getDatabaseName();

            if ($database === '') {
                throw new \RuntimeException('The configured connection has no database name.');
            }

            $expected = $parser->parseFile($referencePath);
            $registry = ExtraTableRegistry::fromMarkdown($notesPath);
            $live = $reader->read($database);
            $crossCheck = $this->crossCheck($live['spec'], $live['sourceCounts']);
            $discrepancies = [...$differ->diff($expected, $live['spec'], $onlyTables, $registry), ...$crossCheck];
            $drift = array_values(array_filter($discrepancies, static fn (Discrepancy $d): bool => $d->isDrift()));
        } catch (Throwable $e) {
            if ($json) {
                $this->line((string) json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'error_type' => $e::class,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }

            $this->components->error('verify-schema could not run: '.$e->getMessage());

            return self::INVALID;
        }

        $report = [
            'ok' => $drift === [],
            'exit_code' => $drift === [] ? self::SUCCESS : self::FAILURE,
            'read_only' => true,
            'reference' => [
                'path' => $this->relative($referencePath),
                'bytes' => (int) filesize($referencePath),
                'md5' => (string) md5_file($referencePath),
            ],
            'live' => [
                'driver' => $driver,
                'database' => $database,
                'information_schema_counts' => $live['sourceCounts'],
            ],
            'notes_registry' => [
                'path' => $this->relative($notesPath),
                'registered_extra_tables' => count($registry),
            ],
            'scope' => $onlyTables === null ? 'all expected tables' : implode(', ', $onlyTables),
            'expected' => $expected->summary(),
            'live_model' => $live['spec']->summary(),
            'multi_line_column_declarations' => $expected->multiLineColumns(),
            'discrepancy_count' => count($discrepancies),
            'drift_count' => count($drift),
            'discrepancies' => array_map(static fn (Discrepancy $d): array => $d->toArray(), $discrepancies),
        ];

        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $report['exit_code'];
        }

        $this->render($report, $expected, $live['spec']);

        return $report['exit_code'];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(array $report, SchemaSpec $expected, SchemaSpec $liveSpec): void
    {
        $this->newLine();
        $this->line('  <fg=cyan>Sehatly schema parity verifier</> — read-only, non-zero on drift');
        $this->newLine();
        $this->row('reference SQL', $report['reference']['path'].'  ('.number_format($report['reference']['bytes']).' bytes, md5 '.substr((string) $report['reference']['md5'], 0, 12).')');
        $this->row('live database', $report['live']['driver'].' / '.$report['live']['database']);
        $this->row('notes registry', $report['notes_registry']['path'].'  ('.$report['notes_registry']['registered_extra_tables'].' registered extra tables)');
        $this->row('scope', (string) $report['scope']);

        $this->section('Parsed reference model (proof the parser is not vacuous)');
        $this->row('counts', $this->counts($expected));
        $this->row('wrapped decls', $this->wrappedSummary($expected));
        $this->row('named keys', $this->namedKeySummary($expected));
        $this->row('named FKs', $this->namedForeignKeySummary($expected));

        $this->section('Live schema');
        $this->row('counts', $this->counts($liveSpec));
        $this->row('information_schema', 'columns='.$report['live']['information_schema_counts']['columns']
            .' indexes='.$report['live']['information_schema_counts']['indexes']
            .' foreign_keys='.$report['live']['information_schema_counts']['foreign_keys']
            .' checks='.$report['live']['information_schema_counts']['checks']);

        $this->section('Discrepancies: '.$report['discrepancy_count']
            .' ('.$report['drift_count'].' drift, '.($report['discrepancy_count'] - $report['drift_count']).' informational)');

        /** @var list<array<string, mixed>> $discrepancies */
        $discrepancies = $report['discrepancies'];

        if ($discrepancies === []) {
            $this->line('    <fg=green>none — the live schema is byte-for-byte equivalent to the reference DDL.</>');
        }

        foreach ($discrepancies as $d) {
            $this->renderDiscrepancy($d);
        }

        $this->newLine();

        if ($report['ok'] === true) {
            $this->line(sprintf(
                '  <fg=green>PASS</> — %d tables, %d views verified. Nothing was written.',
                $expected->summary()['tables'],
                $expected->summary()['views'],
            ));
        } else {
            $this->line(sprintf(
                '  <fg=red>FAIL</> — %d discrepanc%s. The live schema does not match %s. Nothing was written.',
                $report['drift_count'],
                $report['drift_count'] === 1 ? 'y' : 'ies',
                $report['reference']['path'],
            ));
        }

        $this->newLine();
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function renderDiscrepancy(array $d): void
    {
        $kind = (string) $d['kind'];
        $drift = (bool) $d['drift'];
        $colour = $drift ? 'red' : 'yellow';
        $where = trim(implode('.', array_filter([$d['table'] ?? null, $d['column'] ?? null], static fn ($v): bool => $v !== null && $v !== '')));

        $this->line(sprintf(
            '    <fg=%s>%-26s</> %-42s expected: %s | actual: %s',
            $colour,
            $kind,
            $where === '' ? '-' : $where,
            $this->cell($d['expected'] ?? null),
            $this->cell($d['actual'] ?? null),
        ));
    }

    private function cell(mixed $value): string
    {
        if ($value === null) {
            return '<fg=gray>-</>';
        }

        return (string) $value;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>'.$title.'</>');
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  <fg=gray>%-20s</> %s', $label, $value));
    }

    private function counts(SchemaSpec $spec): string
    {
        $s = $spec->summary();

        return sprintf(
            'tables=%d views=%d columns=%d indexes=%d foreign_keys=%d checks=%d',
            $s['tables'],
            $s['views'],
            $s['columns'],
            $s['indexes'],
            $s['foreign_keys'],
            $s['checks'],
        );
    }

    private function wrappedSummary(SchemaSpec $spec): string
    {
        $wrapped = $spec->multiLineColumns();

        if ($wrapped === []) {
            return '0 (no declaration spans more than one line)';
        }

        $listed = array_map(
            static fn (array $w): string => $w['table'].'.'.$w['column'].' ('.$w['line'].'-'.$w['end_line'].')',
            $wrapped,
        );

        return count($listed).' — each read as ONE unit: '.implode(', ', $listed);
    }

    private function namedKeySummary(SchemaSpec $spec): string
    {
        $named = 0;
        $inline = 0;

        foreach ($spec->tables as $table) {
            foreach ($table->indexes as $index) {
                if ($index->nameIsAuthoritative && $index->name !== 'PRIMARY') {
                    $named++;
                } elseif (! $index->nameIsAuthoritative) {
                    $inline++;
                }
            }
        }

        return $named.' explicitly named (compared by name) + '.$inline.' inline/engine-named (compared by semantics)';
    }

    private function namedForeignKeySummary(SchemaSpec $spec): string
    {
        $named = [];

        foreach ($spec->tables as $table) {
            foreach ($table->foreignKeys as $key) {
                if ($key->nameIsAuthoritative) {
                    $named[] = $key->name.' on '.$table->name.' (line '.$key->line.')';
                }
            }
        }

        return $named === [] ? '0 (every FK is inline, so every name is engine-generated)' : implode(', ', $named);
    }

    /**
     * `SHOW CREATE TABLE` is the authoritative live source; `information_schema` is
     * read independently. If the two disagree, one of them is wrong and the run
     * must say so rather than silently trusting the first.
     *
     * @param  array<string, int>  $counts
     * @return list<Discrepancy>
     */
    private function crossCheck(SchemaSpec $live, array $counts): array
    {
        $out = [];
        $pairs = [
            'columns' => $live->columnCount(),
            'indexes' => $live->indexCount(),
            'foreign_keys' => $live->foreignKeyCount(),
            'checks' => $live->checkCount(),
        ];

        foreach ($pairs as $what => $fromShowCreate) {
            if ($counts[$what] !== $fromShowCreate) {
                $out[] = new Discrepancy(
                    'live_source_mismatch',
                    null,
                    null,
                    'information_schema.'.$what.'='.$fromShowCreate,
                    'information_schema reports '.$counts[$what],
                );
            }
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    private function requestedTables(): ?array
    {
        $raw = $this->option('tables');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $tables = array_values(array_filter(array_map(
            static fn (string $t): string => strtolower(trim($t)),
            explode(',', $raw),
        ), static fn (string $t): bool => $t !== ''));

        if ($tables === []) {
            throw new \RuntimeException('--tables was given but resolved to no table names.');
        }

        return $tables;
    }

    private function relative(string $path): string
    {
        $root = base_path();

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/\\') : $path;
    }
}

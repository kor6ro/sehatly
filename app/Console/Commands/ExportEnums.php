<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Reference\EnumCatalogue;
use App\Support\Schema\SqlSchemaParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Generate `docs/enums.json` from the live `information_schema`, and prove the
 * result still agrees with the reference DDL.
 *
 * ## Two sources, and the second one is the reason this command can fail
 *
 * The plan's todo 42 asks for a mirror of `information_schema.COLUMNS`. A mirror
 * is enough to make a *file* current and not enough to make it *right*: nothing
 * in `information_schema` can tell you that a migration and `telemedicine_test.sql`
 * have quietly disagreed about an ENUM's members. So this command reads both and
 * refuses to write when they diverge.
 *
 * ## It does NOT write on divergence, and that is a deliberate choice
 *
 * Publishing a file the contract contradicts would turn a loud failure into a
 * quiet one, because the file is the input to the Dart and TypeScript enum
 * generators and to todo 49's OpenAPI cross-validation. A client compiled against
 * a wrong catalogue is worse than a missing catalogue. So divergence means exit 1
 * and an unchanged `docs/enums.json`.
 *
 * Exit codes: 0 written, verified, or already byte-identical; 1 the two sources
 * disagree, or `--check` found the file on disk stale; 2 the command could not
 * understand its inputs. A run that cannot read its inputs must never look green.
 *
 * ## Read-only against the database
 *
 * The only statements issued are `SELECT`s against `information_schema`, and the
 * only thing ever written is the output file. No migration is run, no row is
 * touched, and the database name always comes from the connection.
 */
class ExportEnums extends Command
{
    protected $signature = 'sehatly:enums
        {--out= : Path to write (default: docs/enums.json)}
        {--sql= : Path to the reference SQL (default: telemedicine_test.sql at the project root)}
        {--check : Write nothing; exit 1 when the file on disk differs from a fresh export}
        {--json : Emit a machine-readable report on stdout}';

    protected $description = 'Export every ENUM column to docs/enums.json from information_schema, cross-checked against the reference DDL';

    public function handle(EnumCatalogue $catalogue, SqlSchemaParser $parser): int
    {
        $outPath = (string) ($this->option('out') ?: base_path('docs/enums.json'));
        $sqlPath = (string) ($this->option('sql') ?: base_path('telemedicine_test.sql'));
        $check = (bool) $this->option('check');
        $json = (bool) $this->option('json');

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver !== 'mysql') {
                throw new \RuntimeException(
                    'ENUM columns are a MySQL type. The configured connection is "'.$driver.'".',
                );
            }

            $database = (string) $connection->getDatabaseName();

            if ($database === '') {
                throw new \RuntimeException('The configured connection has no database name.');
            }

            $live = $catalogue->fromInformationSchema($database);
            $excluded = $catalogue->excludedViewEnumColumns($database);
            $contract = $catalogue->fromSpec($parser->parseFile($sqlPath));
            $divergences = $catalogue->compare($live, $contract);
            $rendered = $catalogue->render($live);
        } catch (Throwable $e) {
            if ($json) {
                $this->line((string) json_encode([
                    'ok' => false,
                    'error' => $e->getMessage(),
                    'error_type' => $e::class,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }

            $this->components->error('sehatly:enums could not run: '.$e->getMessage());

            return self::INVALID;
        }

        // A live schema with no ENUM column at all is not a database this command
        // understands - it is an unmigrated one, or a reader that silently
        // matched nothing. Publishing an empty catalogue would overwrite a good
        // file with `{}`, so refuse.
        if ($live === []) {
            $message = 'information_schema reports no ENUM column in "'.$database
                .'". The schema is unmigrated, or the reader matched nothing; nothing was written.';

            if ($json) {
                $this->line((string) json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::INVALID;
            }

            $this->components->error($message);

            return self::INVALID;
        }

        $onDisk = is_file($outPath) ? (string) file_get_contents($outPath) : null;
        $matchesOnDisk = $onDisk === $rendered;

        $base = [
            'read_only' => true,
            'mode' => $check ? 'check' : 'write',
            'database' => $database,
            'out' => $this->relative($outPath),
            'reference_ddl' => $this->relative($sqlPath),
            'live_columns' => count($live),
            'live_values' => $catalogue->totalValues($live),
            'ddl_columns' => count($contract),
            'ddl_values' => $catalogue->totalValues($contract),
            'excluded_view_columns' => $excluded,
            'divergence_count' => count($divergences),
            'divergences' => $divergences,
            'bytes' => strlen($rendered),
            'sha256' => hash('sha256', $rendered),
            'matches_on_disk' => $matchesOnDisk,
        ];

        if ($divergences !== []) {
            $report = $base + ['ok' => false, 'exit_code' => self::FAILURE, 'changed' => false];

            if ($json) {
                $this->line((string) json_encode($report, EnumCatalogue::JSON_FLAGS));

                return self::FAILURE;
            }

            $this->renderDivergence($base, $excluded, $divergences);

            return self::FAILURE;
        }

        if ($check) {
            $report = $base + ['ok' => $matchesOnDisk, 'exit_code' => $matchesOnDisk ? self::SUCCESS : self::FAILURE, 'changed' => false];

            if ($json) {
                $this->line((string) json_encode($report, EnumCatalogue::JSON_FLAGS));

                return $report['exit_code'];
            }

            if (! $matchesOnDisk) {
                $this->newLine();
                $this->line('  <fg=red>DRIFT</> -- '.$this->relative($outPath).' is not a fresh export. Run `php artisan sehatly:enums`.');
                $this->row('on disk', $onDisk === null
                    ? '<fg=gray>file does not exist</>'
                    : strlen($onDisk).' bytes, sha256 '.hash('sha256', $onDisk));
                $this->row('fresh export', strlen($rendered).' bytes, sha256 '.hash('sha256', $rendered));
                $this->row('first difference', $this->firstDifference($onDisk, $rendered));
                $this->newLine();

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  <fg=green>UP TO DATE</> -- '.$this->relative($outPath).' is byte-identical to a fresh export');
            $this->row('live / DDL', count($live).' ENUM columns, '.$catalogue->totalValues($live).' values, 0 divergences');
            $this->row('sha256', hash('sha256', $rendered));
            $this->newLine();

            return self::SUCCESS;
        }

        $changed = ! $matchesOnDisk;

        if ($changed) {
            $catalogue->write($outPath, $rendered);
        }

        $report = $base + ['ok' => true, 'exit_code' => self::SUCCESS, 'changed' => $changed];

        if ($json) {
            $this->line((string) json_encode($report, EnumCatalogue::JSON_FLAGS));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Sehatly ENUM exporter</> -- generated from information_schema, cross-checked against the reference DDL');
        $this->newLine();
        $this->row('live database', $database);
        $this->row('reference DDL', $this->relative($sqlPath));
        $this->row('output', $this->relative($outPath).($changed ? '  (rewritten)' : '  (unchanged, already byte-identical)'));
        $this->row('live counts', count($live).' ENUM columns across base tables, '.$catalogue->totalValues($live).' values');
        $this->row('ddl counts', count($contract).' ENUM columns, '.$catalogue->totalValues($contract).' values');
        $this->row('divergences', '0 -- the two independent sources agree on every column and every value');
        $this->row('excluded views', $excluded === []
            ? 'none (no view exposes an ENUM column)'
            : implode(', ', array_map(
                fn (string $key): string => $key.' ('.count($excluded[$key]).' values, a projection of a base-table column)',
                array_keys($excluded),
            )));
        $this->row('bytes', (string) strlen($rendered));
        $this->row('sha256', hash('sha256', $rendered));
        $this->row('encoding', 'UTF-8, no BOM, LF line endings, one trailing newline, keys sorted by strcmp, values in MySQL declaration order');
        $this->newLine();
        $this->line('  <fg=green>PASS</> -- nothing in the database was written; the only bytes produced are '.$this->relative($outPath).'.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, list<string>>  $excluded
     * @param  list<array{kind: string, column: string, expected: list<string>, actual: list<string>}>  $divergences
     */
    private function renderDivergence(
        array $base,
        array $excluded,
        array $divergences,
    ): void {
        $this->newLine();
        $this->line('  <fg=cyan>Sehatly ENUM exporter</> -- DIVERGENCE');
        $this->newLine();
        $this->row('live database', (string) $base['database']);
        $this->row('reference DDL', (string) $base['reference_ddl']);
        $this->row('live columns', (string) $base['live_columns'].' carrying '.(string) $base['live_values'].' values');
        $this->row('ddl columns', (string) $base['ddl_columns'].' carrying '.(string) $base['ddl_values'].' values');
        $this->row('excluded views', $excluded === [] ? 'none' : implode(', ', array_keys($excluded)));
        $this->newLine();
        $this->line('  <options=bold>Divergences: '.count($divergences).'</>');
        $this->newLine();

        foreach ($divergences as $divergence) {
            $this->line(sprintf(
                '    <fg=red>%-28s</> %s',
                (string) $divergence['kind'],
                (string) $divergence['column'],
            ));
            $this->line('      ddl:    '.$this->cell($divergence['expected']));
            $this->line('      live:   '.$this->cell($divergence['actual']));
        }

        $this->newLine();
        $this->line('  <fg=red>FAIL</> -- the live schema and '.$this->relative((string) $base['reference_ddl'])
            .' disagree on '.count($divergences).' ENUM column(s).');
        $this->line('  <fg=red>'.$this->relative((string) $base['out']).' was NOT written.</> Nothing in the database was written either.');
        $this->newLine();
    }

    /**
     * @param  list<string>  $values
     */
    private function cell(array $values): string
    {
        if ($values === []) {
            return '<fg=gray>(none)</>';
        }

        $out = '[';

        foreach ($values as $index => $value) {
            $out .= ($index === 0 ? '' : ', ').$value;
        }

        return $out.'] ('.count($values).' values)';
    }

    /**
     * Where two files first differ, in a form that names the byte offset rather
     * than dumping both texts. A diff of a 4 KB JSON file printed in full is not
     * a message anybody reads.
     */
    private function firstDifference(?string $onDisk, string $rendered): string
    {
        if ($onDisk === null) {
            return 'the file does not exist';
        }

        $length = min(strlen($onDisk), strlen($rendered));

        for ($i = 0; $i < $length; $i++) {
            if ($onDisk[$i] !== $rendered[$i]) {
                $window = 40;
                $start = max(0, $i - $window);

                return sprintf(
                    'byte %d: on disk %s, fresh %s',
                    $i,
                    self::printable(substr($onDisk, $start, $window)),
                    self::printable(substr($rendered, $start, $window)),
                );
            }
        }

        return 'identical for the first '.$length.' bytes; lengths differ ('
            .strlen($onDisk).' on disk, '.strlen($rendered).' fresh)';
    }

    private static function printable(string $subject): string
    {
        $flat = preg_replace('/\s+/', ' ', $subject) ?? $subject;

        return '"'.str_replace(['"', "\n", "\r"], ['\\"', '\\n', '\\r'], $flat).'"';
    }

    private function row(string $label, string $value): void
    {
        $this->line(sprintf('  <fg=gray>%-18s</> %s', $label, $value));
    }

    private function relative(string $path): string
    {
        $root = base_path();

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/\\') : $path;
    }
}

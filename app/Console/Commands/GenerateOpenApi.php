<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApi\OpenApiDocumentBuilder;
use App\Support\OpenApi\OpenApiGenerationException;
use App\Support\OpenApi\RouteInventory;
use Illuminate\Console\Command;
use Throwable;

/**
 * Generate `docs/openapi.yaml` from the live route table, and prove the
 * committed copy is still a fresh export.
 *
 * ## Why the document is generated and not written
 *
 * A hand-maintained OpenAPI file is a copy of `routes/api.php` plus every
 * `FormRequest`'s `rules()`, and a copy is wrong the first time any of them
 * changes with nothing failing. So this walks `Route::getRoutes()` through
 * {@see RouteInventory}, reflects each controller method for its injected
 * `FormRequest`, and emits what it finds. It reads; it never asserts an
 * endpoint exists, an endpoint is protected, or a limit is N per minute.
 *
 * ## `--check` is the drift check, and it is the point of the command
 *
 * Exit codes:
 *
 * - 0 -- written, or `--check` found the file byte-identical to a fresh export;
 * - 1 -- `--check` found the committed file DRIFTED from a fresh export, or the
 *   route table is internally inconsistent (a `FormRequest` whose `rules()`
 *   throws);
 * - 2 -- the generator could not read one of its inputs (`docs/enums.json`
 *   missing or malformed). Distinct from 1 because "the inputs are broken" and
 *   "the file is stale" call for different actions, and a check that cannot
 *   tell them apart looks green in one of the two cases.
 *
 * **A check that cannot fail is a file copy.** The drift path is therefore
 * demonstrated, not just implemented: `OpenApiCommandTest` hand-edits the
 * committed YAML, asserts exit 1 and a named first-difference byte, asserts the
 * file is NOT overwritten, and restores. The mutation transcript is in
 * `.omo/evidence/task-53-sehatly.md`.
 *
 * ## Drift is BYTE-level, and that is deliberate
 *
 * The comparison is `hash('sha256', ...)` on the rendered string, not a parsed
 * YAML equality. A parsed comparison would call a reordered map, a changed
 * comment or a reformatted block "equal", so a hand-edit could pass while the
 * committed bytes no longer correspond to anything the generator produces. Two
 * runs over an unchanged route table produce identical bytes, so byte-level
 * equality is achievable and is the only comparison that makes `--check`
 * meaningful.
 *
 * ## Read-only against the database
 *
 * Nothing here touches MySQL. The route table is in memory and the ENUM
 * catalogue is read from `docs/enums.json` -- which was itself generated from
 * `information_schema` by `sehatly:enums`. So this command is safe to run in CI
 * before the migrations, and cannot mutate anything.
 */
class GenerateOpenApi extends Command
{
    protected $signature = 'sehatly:openapi
        {--out= : Path to write (default: docs/openapi.yaml)}
        {--check : Write nothing; exit 1 when the file on disk differs from a fresh export}
        {--json : Emit a machine-readable report on stdout}';

    protected $description = 'Generate docs/openapi.yaml from the live /api/v1 route table, its middleware and each FormRequest\'s rules()';

    public function handle(): int
    {
        $outPath = (string) ($this->option('out') ?: base_path('docs/openapi.yaml'));
        $check = (bool) $this->option('check');
        $json = (bool) $this->option('json');

        try {
            $inventory = new RouteInventory;
            $builder = new OpenApiDocumentBuilder($inventory);
            $rendered = $builder->render();
        } catch (OpenApiGenerationException $e) {
            return $this->refuse($e->getMessage(), $json);
        } catch (Throwable $e) {
            return $this->refuse(
                $e::class.': '.$e->getMessage()
                    .' (at '.$e->getFile().':'.$e->getLine().')',
                $json,
            );
        }

        // A route table this walk could not fully read is a real inconsistency:
        // a `FormRequest` whose `rules()` throws would throw for a client too.
        // Publishing a document missing that operation would make the spec wrong
        // in the one direction nobody reviews by hand.
        if ($inventory->failures() !== []) {
            if ($json) {
                $this->line((string) json_encode([
                    'ok' => false,
                    'exit_code' => self::FAILURE,
                    'read_failures' => $inventory->failures(),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  <fg=cyan>Sehatly OpenAPI generator</> -- could not read the route table completely');
            $this->newLine();
            $this->line('  <options=bold>Failures: '.count($inventory->failures()).'</>');
            $this->newLine();

            foreach ($inventory->failures() as $failure) {
                $this->line('    <fg=red>-</> '.$failure);
            }

            $this->newLine();
            $this->line('  <fg=red>FAIL</> -- nothing was written. Each failure is a route whose validation cannot be read.');
            $this->newLine();

            return self::FAILURE;
        }

        $onDisk = is_file($outPath) ? (string) file_get_contents($outPath) : null;
        $matchesOnDisk = $onDisk === $rendered;

        $writes = $inventory->writesWithoutFormRequest();
        $unexempt = array_values(array_filter($writes, static fn (array $w): bool => $w['exempt'] === false));

        $report = [
            'ok' => true,
            'exit_code' => self::SUCCESS,
            'mode' => $check ? 'check' : 'write',
            'routes_read' => $inventory->routesRead(),
            'unique_paths' => $inventory->uniquePaths(),
            'operations' => count($inventory->operations()),
            'form_requests' => count($inventory->formRequestExemptions()) > 0
                ? count(array_unique(array_map(
                    static fn (array $o): string => (string) ($o['form_request'] ?? ''),
                    $inventory->operations(),
                ))) - 1
                : 0,
            'writes_without_form_request' => $writes,
            'unexempt_writes' => $unexempt,
            'out' => $this->relative($outPath),
            'bytes' => strlen($rendered),
            'sha256' => hash('sha256', $rendered),
            'on_disk_sha256' => $onDisk === null ? null : hash('sha256', $onDisk),
            'matches_on_disk' => $matchesOnDisk,
            'changed' => false,
        ];

        // A write endpoint with no `FormRequest` and no documented exemption is
        // a DoD violation, and it is refused here rather than only reported by a
        // test: a developer adding an endpoint sees the failure at the moment
        // they generate, which is the cheapest possible moment.
        if ($unexempt !== []) {
            $report['ok'] = false;
            $report['exit_code'] = self::FAILURE;

            if ($json) {
                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  <fg=cyan>Sehatly OpenAPI generator</> -- DoD violation');
            $this->newLine();
            $this->line('  <options=bold>'.count($unexempt).' write endpoint(s) have no FormRequest:</>');
            $this->newLine();

            foreach ($unexempt as $offender) {
                $this->line('    <fg=red>-</> '.$offender['method'].' '.$offender['path']);
                $this->line('      '.$offender['action']);
            }

            $this->newLine();
            $this->line('  <fg=red>FAIL</> -- '.$this->relative($outPath).' was NOT written. Extract a FormRequest');
            $this->line('  <fg=red>for each of these, or add a documented exemption.</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($check) {
            $report['ok'] = $matchesOnDisk;
            $report['exit_code'] = $matchesOnDisk ? self::SUCCESS : self::FAILURE;

            if ($json) {
                $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return $report['exit_code'];
            }

            if (! $matchesOnDisk) {
                $this->newLine();
                $this->line('  <fg=red>DRIFT</> -- '.$this->relative($outPath).' is not a fresh export. Run `php artisan sehatly:openapi`.');
                $this->row('on disk', $onDisk === null
                    ? 'file does not exist'
                    : strlen($onDisk).' bytes, sha256 '.hash('sha256', $onDisk));
                $this->row('fresh export', strlen($rendered).' bytes, sha256 '.hash('sha256', $rendered));
                $this->row('first difference', $this->firstDifference($onDisk, $rendered));
                $this->row('routes read', (string) $inventory->routesRead());
                $this->newLine();
                $this->line('  <fg=red>The file was NOT overwritten.</> Commit the regenerated copy, or revert the hand-edit.');
                $this->newLine();

                return self::FAILURE;
            }

            $this->newLine();
            $this->line('  <fg=green>UP TO DATE</> -- '.$this->relative($outPath).' is byte-identical to a fresh export');
            $this->row('routes read', $inventory->routesRead().' under api/v1');
            $this->row('paths / operations', $inventory->uniquePaths().' paths, '.count($inventory->operations()).' operations');
            $this->row('sha256', hash('sha256', $rendered));
            $this->newLine();

            return self::SUCCESS;
        }

        $changed = ! $matchesOnDisk;

        if ($changed) {
            $directory = dirname($outPath);

            if (! is_dir($directory)) {
                mkdir($directory, 0o755, true);
            }

            file_put_contents($outPath, $rendered);
        }

        $report['changed'] = $changed;

        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Sehatly OpenAPI generator</> -- generated from the live route table');
        $this->newLine();
        $this->row('source', 'Route::getRoutes() filtered to api/v1, plus each controller method\'s FormRequest::rules()');
        $this->row('routes read', (string) $inventory->routesRead());
        $this->row('paths / operations', $inventory->uniquePaths().' paths, '.count($inventory->operations()).' operations');
        $this->row('output', $this->relative($outPath).($changed ? '  (rewritten)' : '  (unchanged, already byte-identical)'));
        $this->row('bytes', (string) strlen($rendered));
        $this->row('sha256', hash('sha256', $rendered));
        $this->row('writes without FormRequest', $writes === []
            ? 'none'
            : count($writes).' ('.count($unexempt).' unexempt, '.count($writes) - count($unexempt).' documented exemption)');

        foreach ($writes as $write) {
            $this->row('', $write['method'].' '.$write['path'].($write['exempt'] ? '  [exempt]' : '  [VIOLATION]'));
        }

        $this->row('encoding', 'UTF-8, no BOM, LF line endings, one trailing newline, no timestamp and no host');
        $this->newLine();
        $this->line('  <fg=green>PASS</> -- nothing else was written; the document is derived, not transcribed.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function refuse(string $message, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode([
                'ok' => false,
                'exit_code' => self::INVALID,
                'error' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::INVALID;
        }

        $this->newLine();
        $this->line('  <fg=red>sehatly:openapi could not run: '.$message.'</>');
        $this->newLine();
        $this->line('  <fg=red>Nothing was written.</> A document that cannot describe the whole route table');
        $this->line('  <fg=red>would be wrong in the one direction nobody reviews by hand.</>');
        $this->newLine();

        return self::INVALID;
    }

    /**
     * Where two files first differ, naming the byte offset rather than dumping
     * both texts. A diff of a 40 KB YAML file printed in full is not a message
     * anybody reads.
     */
    private function firstDifference(?string $onDisk, string $rendered): string
    {
        if ($onDisk === null) {
            return 'the file does not exist';
        }

        $length = min(strlen($onDisk), strlen($rendered));

        for ($i = 0; $i < $length; $i++) {
            if ($onDisk[$i] !== $rendered[$i]) {
                $window = 48;
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
        $this->line(sprintf('  <fg=gray>%-24s</> %s', $label, $value));
    }

    private function relative(string $path): string
    {
        $root = base_path();

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/\\') : $path;
    }
}

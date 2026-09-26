<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Provision the MySQL 8 database that the test suite runs against.
 *
 * The suite is pinned to MySQL in phpunit.xml because the 75 table schema uses
 * ENUM, JSON, unsigned integers, inline INDEX, CHECK, VIEW and GROUP_CONCAT,
 * none of which degrade faithfully on SQLite. The database itself is not
 * version-controlled, so it has to be creatable on demand and safe to re-run.
 */
class CreateTestDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:create-test-database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the MySQL test database with utf8mb4 / utf8mb4_unicode_ci if it is missing';

    /**
     * Fallback name, kept in step with phpunit.xml's DB_DATABASE.
     *
     * @var string
     */
    private const DEFAULT_DATABASE = 'telemedisin_db_test';

    /**
     * Matches MySQL unquoted identifiers so env values can be interpolated safely.
     *
     * @var string
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * The mysql connection config as it was before this command ran.
     *
     * @var array<string, mixed>|null
     */
    private ?array $originalConnectionConfig = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $database = (string) env('DB_TEST_DATABASE', self::DEFAULT_DATABASE);
        $charset = (string) env('DB_CHARSET', 'utf8mb4');
        $collation = (string) env('DB_COLLATION', 'utf8mb4_unicode_ci');

        foreach (['database' => $database, 'charset' => $charset, 'collation' => $collation] as $label => $value) {
            if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
                $this->components->error("[{$value}] is not a valid MySQL {$label} identifier.");

                return self::INVALID;
            }
        }

        try {
            $server = $this->connectToServer();
        } catch (Throwable $e) {
            $this->components->error('Could not reach the MySQL server: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $exists = $server->selectOne(
                'select schema_name from information_schema.schemata where schema_name = ?',
                [$database],
            ) !== null;

            if ($exists) {
                $this->components->info("Database [{$database}] already exists, nothing to create.");

                return self::SUCCESS;
            }

            $server->statement(
                "create database `{$database}` character set {$charset} collate {$collation}",
            );

            $this->components->info("Database [{$database}] created with {$charset} / {$collation}.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->components->error("Could not create database [{$database}]: ".$e->getMessage());

            return self::FAILURE;
        } finally {
            $this->disconnectFromServer($server);
        }
    }

    /**
     * Open a connection to the MySQL server with no default schema selected.
     *
     * The default connection may point at a database that does not exist yet,
     * which is exactly the state this command is meant to recover from, so the
     * schema is dropped from the config before the connection is built.
     */
    private function connectToServer(): Connection
    {
        $this->originalConnectionConfig = config('database.connections.mysql');

        config(['database.connections.mysql.database' => null]);
        DB::purge('mysql');

        return DB::connection('mysql');
    }

    /**
     * Drop the server connection and put the mysql config back as it was.
     */
    private function disconnectFromServer(Connection $server): void
    {
        $server->disconnect();

        if ($this->originalConnectionConfig !== null) {
            config(['database.connections.mysql' => $this->originalConnectionConfig]);
            DB::purge('mysql');

            $this->originalConnectionConfig = null;
        }
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Read-only row-count comparison between the SQLite source and the
 * PostgreSQL target of the database migration. Never writes to either side:
 * each connection is switched to read-only mode before any query runs and the
 * snapshot transaction is always rolled back.
 */
class VerifyRowParityCommand extends Command
{
    protected $signature = 'db:verify-row-parity
        {--source=sqlite : Configured connection name for the source database}
        {--source-path= : SQLite file to read as the source (defaults to the source connection\'s database)}
        {--target=pgsql : Configured connection name for the target database}
        {--target-host= : Override the target host}
        {--target-port= : Override the target port}
        {--target-database= : Override the target database name}
        {--target-username= : Override the target username (password comes from PARITY_TARGET_PASSWORD)}
        {--exclude=* : Table to skip; may be repeated}
        {--expect=* : Expected baseline count as table=count, checked on both sides; may be repeated}
        {--json : Print the report as JSON}';

    protected $description = 'Compare per-table row counts between the SQLite source and PostgreSQL target (read-only)';

    private const SOURCE = 'parity_source';
    private const TARGET = 'parity_target';

    public function handle(): int
    {
        try {
            $expected = $this->parseExpectations($this->option('expect'));
            $source = $this->openReadOnly(self::SOURCE, $this->sourceConfig());
            $target = $this->openReadOnly(self::TARGET, $this->targetConfig());

            try {
                $sourceCounts = $this->countRows($source);
                $targetCounts = $this->countRows($target);
            } finally {
                $this->close($source);
                $this->close($target);
            }
        } catch (\Throwable $e) {
            $this->error('Row parity check could not run: '.$e->getMessage());

            return Command::FAILURE;
        }

        $rows = $this->compare($sourceCounts, $targetCounts, $expected);
        $failures = array_filter($rows, fn (array $row) => $row['status'] !== 'OK');

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $failures === [],
                'tables' => $rows,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->table(
                ['Table', 'Source', 'Target', 'Expected', 'Status'],
                array_map(fn (array $row) => [
                    $row['table'],
                    $row['source'] ?? '-',
                    $row['target'] ?? '-',
                    $row['expected'] ?? '',
                    $row['status'],
                ], $rows)
            );

            if ($failures === []) {
                $this->info(sprintf('All %d tables match.', count($rows)));
            } else {
                $this->error(sprintf('%d of %d tables do not match.', count($failures), count($rows)));
            }
        }

        return $failures === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function sourceConfig(): array
    {
        $config = $this->baseConfig($this->option('source'));

        if ($path = $this->option('source-path')) {
            $config['database'] = $path;
        }

        if (($config['driver'] ?? null) === 'sqlite') {
            // The SQLite connector would happily open (and create) a missing
            // file in some setups; refuse instead so a typo never yields "0 rows".
            if ($config['database'] !== ':memory:' && ! is_file($config['database'])) {
                throw new RuntimeException("source SQLite file not found: {$config['database']}");
            }
            $config['foreign_key_constraints'] = false;
        }

        return $config;
    }

    private function targetConfig(): array
    {
        $config = $this->baseConfig($this->option('target'));

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (($value = $this->option("target-{$key}")) !== null) {
                $config[$key] = $value;
            }
        }

        if (($password = env('PARITY_TARGET_PASSWORD')) !== null) {
            $config['password'] = $password;
        }

        return $config;
    }

    private function baseConfig(string $name): array
    {
        $config = config("database.connections.{$name}");

        if (! is_array($config)) {
            throw new RuntimeException("database connection [{$name}] is not configured");
        }

        // A shared DATABASE_URL would override the per-side settings.
        unset($config['url']);

        return $config;
    }

    private function openReadOnly(string $name, array $config): Connection
    {
        config(["database.connections.{$name}" => $config]);
        DB::purge($name);
        $connection = DB::connection($name);

        match ($connection->getDriverName()) {
            'sqlite' => $connection->statement('PRAGMA query_only = ON'),
            'pgsql' => $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY'),
            'mysql', 'mariadb' => $connection->statement('SET SESSION TRANSACTION READ ONLY'),
            default => throw new RuntimeException("unsupported driver [{$connection->getDriverName()}] for [{$name}]"),
        };

        // One transaction per side so every count comes from the same snapshot.
        $connection->beginTransaction();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection;
    }

    private function close(Connection $connection): void
    {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::purge($connection->getName());
    }

    /**
     * @return array<string, int>
     */
    private function countRows(Connection $connection): array
    {
        $excluded = array_flip($this->option('exclude'));
        $counts = [];

        foreach ($this->listTables($connection) as $table) {
            if (isset($excluded[$table])) {
                continue;
            }
            $wrapped = $connection->getQueryGrammar()->wrapTable($table);
            $counts[$table] = (int) $connection->selectOne("select count(*) as aggregate from {$wrapped}")->aggregate;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function listTables(Connection $connection): array
    {
        $rows = match ($connection->getDriverName()) {
            'sqlite' => $connection->select("select name from sqlite_master where type = 'table' and name not like 'sqlite\\_%' escape '\\'"),
            'pgsql' => $connection->select('select tablename as name from pg_catalog.pg_tables where schemaname = current_schema()'),
            'mysql', 'mariadb' => $connection->select('select table_name as name from information_schema.tables where table_schema = database() and table_type = ?', ['BASE TABLE']),
        };

        return array_map(fn ($row) => $row->name, $rows);
    }

    /**
     * @return array<string, int>
     */
    private function parseExpectations(array $values): array
    {
        $expected = [];

        foreach ($values as $value) {
            if (! preg_match('/^([A-Za-z0-9_]+)=(\d+)$/', $value, $m)) {
                throw new RuntimeException("invalid --expect value [{$value}], use table=count");
            }
            $expected[$m[1]] = (int) $m[2];
        }

        return $expected;
    }

    /**
     * @param  array<string, int>  $source
     * @param  array<string, int>  $target
     * @param  array<string, int>  $expected
     * @return list<array{table: string, source: ?int, target: ?int, expected: ?int, status: string}>
     */
    private function compare(array $source, array $target, array $expected): array
    {
        $tables = array_unique(array_merge(array_keys($source), array_keys($target), array_keys($expected)));
        sort($tables);

        return array_map(function (string $table) use ($source, $target, $expected) {
            $s = $source[$table] ?? null;
            $t = $target[$table] ?? null;
            $e = $expected[$table] ?? null;

            $status = match (true) {
                $s === null && $t === null => 'MISSING_BOTH',
                $s === null => 'MISSING_IN_SOURCE',
                $t === null => 'MISSING_IN_TARGET',
                $s !== $t => 'COUNT_MISMATCH',
                $e !== null && $s !== $e => 'BASELINE_MISMATCH',
                default => 'OK',
            };

            return ['table' => $table, 'source' => $s, 'target' => $t, 'expected' => $e, 'status' => $status];
        }, $tables);
    }
}

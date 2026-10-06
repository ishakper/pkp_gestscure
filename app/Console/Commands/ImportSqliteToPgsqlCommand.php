<?php

namespace App\Console\Commands;

use App\Database\Import\PgsqlColumnValue;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Copies every row of a SQLite database file into an empty, freshly migrated
 * PostgreSQL database, keeping primary keys and identity text exactly as they
 * are ("00001" stays "00001").
 *
 * Without --execute it only runs the read-only preflight. With --execute the
 * whole copy runs in one PostgreSQL transaction: rows are inserted with their
 * original ids, sequences are moved past MAX(id) with setval, and every table
 * is compared value by value against the source before COMMIT. Any failure
 * rolls the target back to empty. The source is always opened read-only.
 */
class ImportSqliteToPgsqlCommand extends Command
{
    protected $signature = 'db:import-sqlite-to-pgsql
        {--source-path= : SQLite file to copy from (a copy, never the live file)}
        {--target=pgsql : Configured connection name for the PostgreSQL target}
        {--target-host= : Override the target host}
        {--target-port= : Override the target port}
        {--target-database= : Override the target database name}
        {--target-username= : Override the target username (password comes from IMPORT_TARGET_PASSWORD)}
        {--execute : Write to the target; without it only the read-only preflight runs}
        {--confirm-target= : Required with --execute: the target database name, typed again}
        {--batch=500 : Rows per INSERT statement}';

    protected $description = 'Copy a SQLite database into an empty PostgreSQL schema with exact ids and identities';

    private const SOURCE = 'import_source';
    private const TARGET = 'import_target';
    private const MIGRATIONS = 'migrations';

    /**
     * Historical production migrations that are known to be no-op metadata
     * only. They may exist in SQLite history even though the canonical
     * migration is the one present in current code.
     *
     * The legacy entry is ignored only when the canonical migration exists
     * on BOTH source and target.
     */
    private const LEGACY_SOURCE_ONLY_MIGRATIONS = [
        '2026_09_19_161737_add_card_number_hash_to_credential_records'
            => '2026_09_18_153235_add_card_number_hash_to_credential_records_table',
    ];

    /** @var array<string, array<string, array{type: string, nullable: bool, max: ?int, default: ?string}>> */
    private array $targetColumns = [];

    /** @var array<string, list<string>> */
    private array $sourceColumns = [];

    /** @var list<array{table: string, column: string, parent: string, parent_column: string}> */
    private array $foreignKeys = [];

    /** @var array<string, list<string>> */
    private array $primaryKeys = [];

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        try {
            $sourcePath = $this->resolveSourcePath();
            $hashBefore = hash_file('sha256', $sourcePath);
            $source = $this->openSource($sourcePath);
            $target = $this->openTarget();
        } catch (\Throwable $e) {
            $this->error('Import could not start: '.$e->getMessage());

            return Command::FAILURE;
        }

        try {
            $this->line(sprintf('Source: %s (sha256 %s)', $sourcePath, $hashBefore));
            $this->line(sprintf('Target: %s on %s:%s', $target->getDatabaseName(), $target->getConfig('host'), $target->getConfig('port')));

            $this->loadTargetSchema($target);
            $this->loadSourceSchema($source);
            $this->preflight($source, $target);
            $this->printFindings();

            if ($this->errors !== []) {
                $this->error(sprintf('Preflight failed with %d error(s). Nothing was written.', count($this->errors)));

                return Command::FAILURE;
            }

            if (! $this->option('execute')) {
                $this->info('Preflight passed. Dry run only: nothing was written. Re-run with --execute --confirm-target=<database> to import.');

                return Command::SUCCESS;
            }

            if ($this->option('confirm-target') !== $target->getDatabaseName()) {
                $this->error('--execute needs --confirm-target='.$target->getDatabaseName().' (the target database name). Nothing was written.');

                return Command::FAILURE;
            }

            $summary = $this->import($source, $target);
        } catch (\Throwable $e) {
            $this->error('Import failed and was rolled back, the target is unchanged: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->close($source);
            $this->close($target);
        }

        $this->table(['Table', 'Rows', 'Sequence next value', 'Reformatted values'], $summary);

        if (hash_file('sha256', $sourcePath) !== $hashBefore) {
            $this->error('The source file changed during the import (sha256 differs). Treat the import as invalid.');

            return Command::FAILURE;
        }

        $this->info(sprintf('Imported %d tables; every row matched the source before COMMIT. Source sha256 unchanged.', count($summary)));

        return Command::SUCCESS;
    }

    private function resolveSourcePath(): string
    {
        $path = (string) $this->option('source-path');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException("source SQLite file not found: {$path}");
        }

        $real = realpath($path);
        $live = config('database.connections.sqlite.database');
        if (is_string($live) && ($liveReal = realpath($live)) !== false && $liveReal === $real) {
            throw new RuntimeException("{$path} is the application's own SQLite database; copy it first and import the copy");
        }

        return $real;
    }

    private function openSource(string $path): Connection
    {
        config(['database.connections.'.self::SOURCE => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge(self::SOURCE);
        $connection = DB::connection(self::SOURCE);
        $connection->statement('PRAGMA query_only = ON');
        // One read transaction so every table comes from the same snapshot.
        $connection->beginTransaction();

        return $connection;
    }

    private function openTarget(): Connection
    {
        $config = config('database.connections.'.$this->option('target'));
        if (! is_array($config)) {
            throw new RuntimeException("database connection [{$this->option('target')}] is not configured");
        }
        unset($config['url']);

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if (($value = $this->option("target-{$key}")) !== null) {
                $config[$key] = $value;
            }
        }
        if (($password = env('IMPORT_TARGET_PASSWORD')) !== null) {
            $config['password'] = $password;
        }
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException('the target must be a pgsql connection');
        }

        config(['database.connections.'.self::TARGET => $config]);
        DB::purge(self::TARGET);
        $connection = DB::connection(self::TARGET);

        if (! $this->option('execute')) {
            $connection->statement('SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
        }

        return $connection;
    }

    private function close(Connection $connection): void
    {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::purge($connection->getName());
    }

    private function loadTargetSchema(Connection $target): void
    {
        foreach ($target->select(
            "select table_name, column_name, data_type, is_nullable, character_maximum_length, column_default
               from information_schema.columns
              where table_schema = current_schema()
              order by table_name, ordinal_position"
        ) as $row) {
            $this->targetColumns[$row->table_name][$row->column_name] = [
                'type' => $row->data_type,
                'nullable' => $row->is_nullable === 'YES',
                'max' => $row->character_maximum_length !== null ? (int) $row->character_maximum_length : null,
                'default' => $row->column_default,
            ];
        }

        foreach ($target->select(
            "select c.conrelid::regclass::text as tbl, a.attname as col,
                    c.confrelid::regclass::text as parent, pa.attname as parent_col
               from pg_constraint c
               join pg_attribute a on a.attrelid = c.conrelid and a.attnum = c.conkey[1]
               join pg_attribute pa on pa.attrelid = c.confrelid and pa.attnum = c.confkey[1]
              where c.contype = 'f' and c.connamespace = current_schema()::regnamespace
                and array_length(c.conkey, 1) = 1"
        ) as $row) {
            $this->foreignKeys[] = ['table' => $row->tbl, 'column' => $row->col, 'parent' => $row->parent, 'parent_column' => $row->parent_col];
        }

        foreach ($target->select(
            "select c.conrelid::regclass::text as tbl, a.attname as col
               from pg_constraint c
               join lateral unnest(c.conkey) with ordinality k(attnum, pos) on true
               join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.attnum
              where c.contype = 'p' and c.connamespace = current_schema()::regnamespace
              order by tbl, k.pos"
        ) as $row) {
            $this->primaryKeys[$row->tbl][] = $row->col;
        }
    }

    private function loadSourceSchema(Connection $source): void
    {
        $tables = $source->select("select name from sqlite_master where type = 'table' and name not like 'sqlite\\_%' escape '\\'");

        foreach ($tables as $table) {
            $this->sourceColumns[$table->name] = array_map(
                fn ($column) => $column->name,
                $source->select('select name from pragma_table_info(?)', [$table->name])
            );
        }
    }

    private function preflight(Connection $source, Connection $target): void
    {
        foreach (array_keys($this->sourceColumns) as $table) {
            if (! isset($this->targetColumns[$table])) {
                $this->errors[] = "table {$table} exists in the source but not in the target schema; its rows would be lost";
            }
        }
        foreach (array_keys($this->targetColumns) as $table) {
            if (! isset($this->sourceColumns[$table])) {
                $this->warnings[] = "table {$table} exists only in the target; it stays empty";
            }
        }

        $this->checkMigrations($source, $target);
        $this->checkTargetIsEmpty($target);

        foreach ($this->tables() as $table) {
            $this->checkColumns($source, $table);
        }

        $this->checkUniqueIndexes($source, $target);
        $this->checkForeignKeys($source);

        try {
            $this->insertOrder();
        } catch (RuntimeException $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function checkMigrations(Connection $source, Connection $target): void
    {
        if (! isset($this->sourceColumns[self::MIGRATIONS], $this->targetColumns[self::MIGRATIONS])) {
            $this->errors[] = 'the migrations table is missing on one side';

            return;
        }

        $sourceNames = $source->table(self::MIGRATIONS)->pluck('migration')->all();
        $targetNames = $target->table(self::MIGRATIONS)->pluck('migration')->all();

        if ($targetNames === []) {
            $this->errors[] = 'the target has no migrations; run php artisan migrate against the empty target first';
        }
        foreach (array_diff($sourceNames, $targetNames) as $name) {
            $canonical = self::LEGACY_SOURCE_ONLY_MIGRATIONS[$name] ?? null;

            if ($canonical !== null
                && in_array($canonical, $sourceNames, true)
                && in_array($canonical, $targetNames, true)) {
                $this->warnings[] = "legacy no-op migration {$name} exists only in source history; canonical {$canonical} is present on both source and target";

                continue;
            }

            $this->errors[] = "migration {$name} ran on the source but not on the target (code version mismatch)";
        }
        foreach (array_diff($targetNames, $sourceNames) as $name) {
            $this->errors[] = "migration {$name} ran on the target but not on the source; migrate the source copy with the same release first";
        }
    }

    private function checkTargetIsEmpty(Connection $target): void
    {
        foreach (array_keys($this->targetColumns) as $table) {
            if ($table === self::MIGRATIONS) {
                continue;
            }
            if ($target->table($table)->exists()) {
                $this->errors[] = "target table {$table} already has rows; the import only writes into an empty schema";
            }
        }
    }

    private function checkColumns(Connection $source, string $table): void
    {
        $sourceColumns = $this->sourceColumns[$table];
        $targetColumns = $this->targetColumns[$table];

        foreach ($sourceColumns as $column) {
            if (! isset($targetColumns[$column])) {
                $this->errors[] = "{$table}.{$column} exists only in the source; its values would be lost";
            }
        }
        foreach ($targetColumns as $column => $meta) {
            if (! in_array($column, $sourceColumns, true) && ! $meta['nullable'] && $meta['default'] === null) {
                $this->errors[] = "{$table}.{$column} is NOT NULL without default in the target and missing in the source";
            }
        }

        $columns = $this->sharedColumns($table);
        foreach ($columns as $column) {
            $type = $targetColumns[$column]['type'];
            if (! PgsqlColumnValue::isSupportedType($type)) {
                $this->errors[] = "{$table}.{$column} has unsupported target type {$type}";
            }
            if (PgsqlColumnValue::isTextType($type)) {
                $wrapped = $this->quote($column);
                foreach ($source->select("select typeof({$wrapped}) as kind, count(*) as n from {$this->quote($table)} group by 1") as $row) {
                    if (! in_array($row->kind, ['text', 'null'], true)) {
                        $this->warnings[] = "{$table}.{$column}: {$row->n} value(s) stored as SQLite {$row->kind}; copied as SQLite's own text form, never re-padded";
                    }
                }
            }
        }

        $checks = $this->checkConstraintValues($table);
        $failures = [];
        foreach ($this->sourceRows($source, $table) as $row) {
            foreach ($columns as $column) {
                $meta = $targetColumns[$column];
                $value = $row[$column];
                $label = "{$table}.{$column}";
                try {
                    if ($value === null && ! $meta['nullable']) {
                        throw new InvalidArgumentException('NULL in a NOT NULL column');
                    }
                    PgsqlColumnValue::toBinding($value, $meta['type'], $meta['max']);
                    if ($value !== null && isset($checks[$column]) && ! in_array((string) $value, $checks[$column], true)) {
                        throw new InvalidArgumentException("'{$value}' is not allowed by the CHECK constraint (".implode(', ', $checks[$column]).')');
                    }
                } catch (InvalidArgumentException $e) {
                    $failures[$label][$e->getMessage()][] = $this->rowKey($table, $row);
                }
            }
        }

        foreach ($failures as $label => $messages) {
            foreach ($messages as $message => $keys) {
                $this->errors[] = sprintf('%s: %s (%d row(s), e.g. %s)', $label, $message, count($keys), implode(', ', array_slice($keys, 0, 5)));
            }
        }
    }

    /**
     * Allowed values of the simple "col = ANY (ARRAY[...])" CHECK constraints
     * Laravel creates for enum columns on PostgreSQL.
     *
     * @return array<string, list<string>>
     */
    private function checkConstraintValues(string $table): array
    {
        $allowed = [];
        $rows = DB::connection(self::TARGET)->select(
            "select pg_get_constraintdef(oid) as def from pg_constraint where contype = 'c' and conrelid = ?::regclass",
            [$table]
        );

        foreach ($rows as $row) {
            if (preg_match('/^CHECK \(\(\(?\(?"?(\w+)"?\)?::text = ANY \(\(?ARRAY\[(.*)\]\)?(?:::text\[\])?\)\)\)?$/', $row->def, $m)
                && preg_match_all("/'((?:[^']|'')*)'::(?:character varying|text)/", $m[2], $values)) {
                $allowed[$m[1]] = array_map(fn ($v) => str_replace("''", "'", $v), $values[1]);
            } else {
                $this->warnings[] = "{$table}: CHECK constraint not pre-checked, PostgreSQL will enforce it during the import: {$row->def}";
            }
        }

        return $allowed;
    }

    private function checkUniqueIndexes(Connection $source, Connection $target): void
    {
        $indexes = $target->select(
            "select t.relname as tbl, i.relname as idx, array_to_string(array_agg(a.attname order by k.pos), ',') as cols
               from pg_index x
               join pg_class t on t.oid = x.indrelid
               join pg_class i on i.oid = x.indexrelid
               join lateral unnest(x.indkey) with ordinality k(attnum, pos) on true
               join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum
              where x.indisunique and t.relnamespace = current_schema()::regnamespace
                and x.indpred is null and x.indexprs is null
              group by t.relname, i.relname"
        );

        foreach ($indexes as $index) {
            if (! isset($this->sourceColumns[$index->tbl])) {
                continue;
            }
            $columns = explode(',', $index->cols);
            if (array_diff($columns, $this->sourceColumns[$index->tbl]) !== []) {
                continue;
            }
            $list = implode(', ', array_map(fn ($c) => $this->quote($c), $columns));
            $notNull = implode(' and ', array_map(fn ($c) => $this->quote($c).' is not null', $columns));
            $duplicates = (int) $source->selectOne(
                "select count(*) as n from (select 1 from {$this->quote($index->tbl)} where {$notNull} group by {$list} having count(*) > 1) d"
            )->n;
            if ($duplicates > 0) {
                $this->errors[] = "{$index->tbl}: {$duplicates} duplicate value(s) for unique index {$index->idx} ({$index->cols})";
            }
        }
    }

    private function checkForeignKeys(Connection $source): void
    {
        foreach ($this->foreignKeys as $fk) {
            if (! isset($this->sourceColumns[$fk['table']], $this->sourceColumns[$fk['parent']])
                || ! in_array($fk['column'], $this->sourceColumns[$fk['table']], true)) {
                continue;
            }
            $child = $this->quote($fk['table']);
            $column = $this->quote($fk['column']);
            $orphans = $source->select(
                "select {$column} as ref, count(*) as n from {$child} c
                  where {$column} is not null
                    and not exists (select 1 from {$this->quote($fk['parent'])} p where p.{$this->quote($fk['parent_column'])} = c.{$column})
                  group by {$column} limit 5"
            );
            if ($orphans !== []) {
                $this->errors[] = sprintf(
                    '%s.%s references missing %s.%s rows (e.g. %s)',
                    $fk['table'], $fk['column'], $fk['parent'], $fk['parent_column'],
                    implode(', ', array_map(fn ($o) => "{$o->ref} x{$o->n}", $orphans))
                );
            }
        }
    }

    private function printFindings(): void
    {
        foreach ($this->warnings as $warning) {
            $this->warn('WARN  '.$warning);
        }
        foreach ($this->errors as $error) {
            $this->error('ERROR '.$error);
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: string, 3: int}>
     */
    private function import(Connection $source, Connection $target): array
    {
        $order = $this->insertOrder();
        $summary = [];

        $target->beginTransaction();
        try {
            $target->table(self::MIGRATIONS)->delete();

            foreach ($order as $table) {
                $deferred = $this->deferredColumns($table);
                $columns = $this->sharedColumns($table);
                $batchSize = max(1, min((int) $this->option('batch'), intdiv(60000, max(1, count($columns)))));
                $batch = [];
                $updates = [];

                foreach ($this->sourceRows($source, $table) as $row) {
                    $values = [];
                    foreach ($columns as $column) {
                        $meta = $this->targetColumns[$table][$column];
                        $values[$column] = PgsqlColumnValue::toBinding($row[$column], $meta['type'], $meta['max']);
                    }
                    foreach ($deferred as $column) {
                        if ($values[$column] !== null) {
                            $updates[] = [$column, $values[$column], $this->keyValues($table, $values)];
                            $values[$column] = null;
                        }
                    }
                    $batch[] = $values;
                    if (count($batch) >= $batchSize) {
                        $target->table($table)->insert($batch);
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $target->table($table)->insert($batch);
                }

                // Self-references are filled in once every row of the table exists.
                foreach ($updates as [$column, $value, $key]) {
                    $target->table($table)->where($key)->update([$column => $value]);
                }
            }

            $sequences = $this->resetSequences($target);

            foreach ($order as $table) {
                $reformatted = $this->verifyTable($source, $target, $table);
                $summary[] = [$table, $target->table($table)->count(), $sequences[$table] ?? '-', $reformatted];
            }

            $target->commit();
        } catch (\Throwable $e) {
            $target->rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * Moves every serial sequence past the highest imported id.
     *
     * @return array<string, string>
     */
    private function resetSequences(Connection $target): array
    {
        $next = [];

        foreach ($this->targetColumns as $table => $columns) {
            foreach ($columns as $column => $meta) {
                if (! str_starts_with((string) $meta['default'], 'nextval(')) {
                    continue;
                }
                $quoted = $this->quote($column);
                $row = $target->selectOne(
                    "select setval(pg_get_serial_sequence(?, ?), coalesce(max({$quoted}), 1), max({$quoted}) is not null) as value,
                            max({$quoted}) is not null as used
                       from {$this->quote($table)}",
                    [$table, $column]
                );
                $next[$table] = (string) ($row->used ? $row->value + 1 : $row->value);
            }
        }

        return $next;
    }

    /**
     * Compares the canonical form of every source row with the rows now in
     * the target. Returns how many date/time values PostgreSQL stores in a
     * different text form (e.g. "2026-01-01 00:00:00" in a date column).
     */
    private function verifyTable(Connection $source, Connection $target, string $table): int
    {
        $columns = $this->sharedColumns($table);
        $expected = [];
        $reformatted = 0;

        foreach ($this->sourceRows($source, $table) as $row) {
            $parts = [];
            foreach ($columns as $column) {
                $type = $this->targetColumns[$table][$column]['type'];
                $binding = PgsqlColumnValue::toBinding($row[$column], $type, null);
                $parts[] = PgsqlColumnValue::canonical($binding, $type);
                if ($row[$column] !== null && PgsqlColumnValue::isTemporalType($type) && $row[$column] !== PgsqlColumnValue::canonical($binding, $type)) {
                    $reformatted++;
                }
            }
            $expected[] = json_encode($parts);
        }

        $actual = [];
        $select = implode(', ', array_map(fn ($c) => $this->quote($c), $columns));
        foreach ($target->cursor("select {$select} from {$this->quote($table)}") as $row) {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = PgsqlColumnValue::canonical($row->{$column}, $this->targetColumns[$table][$column]['type']);
            }
            $actual[] = json_encode($parts);
        }

        sort($expected);
        sort($actual);
        if ($expected !== $actual) {
            $missing = array_slice(array_values(array_diff($expected, $actual)), 0, 3);
            throw new RuntimeException(sprintf(
                'table %s differs after import (source %d rows, target %d rows); first source rows not found in target: %s',
                $table, count($expected), count($actual), implode(' | ', $missing)
            ));
        }

        return $reformatted;
    }

    /**
     * Tables in parent-before-child order. Self-references are deferred.
     *
     * @return list<string>
     */
    private function insertOrder(): array
    {
        $tables = $this->tables();
        $parents = array_fill_keys($tables, []);

        foreach ($this->foreignKeys as $fk) {
            if ($fk['table'] !== $fk['parent'] && isset($parents[$fk['table']]) && isset($parents[$fk['parent']])) {
                $parents[$fk['table']][$fk['parent']] = true;
            }
        }

        $order = [];
        while ($parents !== []) {
            $ready = array_keys(array_filter($parents, fn ($p) => array_diff_key($p, array_flip($order)) === []));
            if ($ready === []) {
                throw new RuntimeException('foreign keys form a cycle between '.implode(', ', array_keys($parents)));
            }
            sort($ready);
            foreach ($ready as $table) {
                $order[] = $table;
                unset($parents[$table]);
            }
        }

        foreach ($order as $table) {
            foreach ($this->deferredColumns($table) as $column) {
                if (! $this->targetColumns[$table][$column]['nullable']) {
                    throw new RuntimeException("{$table}.{$column} references its own table but is NOT NULL");
                }
            }
        }

        return $order;
    }

    /**
     * @return list<string>
     */
    private function deferredColumns(string $table): array
    {
        $columns = [];
        foreach ($this->foreignKeys as $fk) {
            if ($fk['table'] === $table && $fk['parent'] === $table && in_array($fk['column'], $this->sharedColumns($table), true)) {
                $columns[] = $fk['column'];
            }
        }

        return $columns;
    }

    /**
     * Tables present on both sides, the ones whose rows are copied.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = array_values(array_intersect(array_keys($this->sourceColumns), array_keys($this->targetColumns)));
        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function sharedColumns(string $table): array
    {
        return array_values(array_filter(
            array_keys($this->targetColumns[$table]),
            fn ($column) => in_array($column, $this->sourceColumns[$table], true)
        ));
    }

    /**
     * Source rows with text columns read through CAST(... AS TEXT), so the
     * value is SQLite's stored text and PDO never turns it into a number.
     *
     * @return iterable<array<string, mixed>>
     */
    private function sourceRows(Connection $source, string $table): iterable
    {
        $select = [];
        foreach ($this->sharedColumns($table) as $column) {
            $quoted = $this->quote($column);
            $select[] = PgsqlColumnValue::isTextType($this->targetColumns[$table][$column]['type'])
                ? "CAST({$quoted} AS TEXT) AS {$quoted}"
                : $quoted;
        }
        $order = implode(', ', array_map(fn ($c) => $this->quote($c), $this->primaryKeys[$table] ?? [])) ?: 'rowid';

        foreach ($source->cursor('select '.implode(', ', $select)." from {$this->quote($table)} order by {$order}") as $row) {
            yield (array) $row;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function keyValues(string $table, array $values): array
    {
        $key = $this->primaryKeys[$table] ?? [];
        if ($key === []) {
            throw new RuntimeException("{$table} has a self-reference but no primary key");
        }

        return array_intersect_key($values, array_flip($key));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowKey(string $table, array $row): string
    {
        $key = $this->primaryKeys[$table] ?? [];
        $parts = array_map(fn ($c) => $c.'='.var_export($row[$c] ?? null, true), $key);

        return $parts === [] ? '(no primary key)' : implode(',', $parts);
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}

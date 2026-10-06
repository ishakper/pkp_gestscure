<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Runs only in the PostgreSQL suite (phpunit.pgsql.xml): copies a small,
 * deliberately awkward SQLite database into the _test PostgreSQL database.
 */
class ImportSqliteToPgsqlCommandTest extends TestCase
{
    private string $dir;
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('needs the PostgreSQL test database (phpunit.pgsql.xml)');
        }

        $this->dir = sys_get_temp_dir().'/pg-import-'.uniqid();
        mkdir($this->dir);
        $this->sourcePath = $this->dir.'/source.sqlite';
        touch($this->sourcePath);

        config(['database.connections.import_test_source' => ['driver' => 'sqlite', 'database' => $this->sourcePath, 'prefix' => '', 'foreign_key_constraints' => true]]);
        Artisan::call('migrate', ['--database' => 'import_test_source', '--force' => true]);
        DB::purge('import_test_source');
        Artisan::call('migrate:fresh', ['--force' => true]);

        $this->seedSource();
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $tables = collect(DB::select("select tablename from pg_tables where schemaname = current_schema() and tablename <> 'migrations'"))
                ->map(fn ($t) => '"'.$t->tablename.'"')->implode(', ');
            DB::statement("truncate {$tables} restart identity cascade");
            RefreshDatabaseState::$migrated = false;
            array_map('unlink', glob($this->dir.'/*'));
            rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_known_legacy_noop_migration_is_tolerated_when_canonical_exists_on_both_sides(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $batch = (int) $pdo->query('select max(batch) from migrations')->fetchColumn();

        $pdo->prepare('insert into migrations (migration, batch) values (?, ?)')
            ->execute([
                '2026_09_19_161737_add_card_number_hash_to_credential_records',
                $batch + 1,
            ]);

        $this->artisan('db:import-sqlite-to-pgsql', ['--source-path' => $this->sourcePath])
            ->expectsOutputToContain('legacy no-op migration 2026_09_19_161737_add_card_number_hash_to_credential_records')
            ->expectsOutputToContain('Preflight passed. Dry run only')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('employees')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->artisan('db:import-sqlite-to-pgsql', ['--source-path' => $this->sourcePath])
            ->expectsOutputToContain('Preflight passed. Dry run only')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('employees')->count());
    }

    public function test_execute_requires_the_target_name(): void
    {
        $this->artisan('db:import-sqlite-to-pgsql', ['--source-path' => $this->sourcePath, '--execute' => true, '--confirm-target' => 'wrong'])
            ->expectsOutputToContain('--execute needs --confirm-target=securegate_test')
            ->assertExitCode(1);

        $this->assertSame(0, DB::table('employees')->count());
    }

    public function test_import_keeps_ids_and_identities_and_moves_sequences(): void
    {
        $hash = hash_file('sha256', $this->sourcePath);

        $this->artisan('db:import-sqlite-to-pgsql', ['--source-path' => $this->sourcePath, '--execute' => true, '--confirm-target' => 'securegate_test'])
            ->expectsOutputToContain('every row matched the source before COMMIT')
            ->assertExitCode(0);

        $this->assertSame($hash, hash_file('sha256', $this->sourcePath));
        $this->assertSame(
            [1 => '00001', 2 => '1', 7 => 'PKP-0007'],
            DB::table('employees')->orderBy('id')->pluck('employee_id', 'id')->all()
        );
        $this->assertSame(7, DB::table('employees')->where('id', 1)->value('supervisor_id'));
        $this->assertSame('00001', DB::table('device_person_states')->value('device_employee_no'));
        $this->assertSame('{"basis":"employee_no","note":"0001 ≠ 1"}', DB::table('device_person_states')->value('reasons'));
        $this->assertTrue(DB::table('buildings')->where('id', 4)->value('is_active'));
        $this->assertSame('2024-02-01', DB::table('employees')->where('id', 1)->value('hire_date'));

        // Sequences continue after the highest imported id, not at 1.
        $this->assertSame(8, DB::table('employees')->insertGetId(['employee_id' => 'NEW', 'nik' => 'NEW', 'name' => 'x', 'department' => 'x']));
        $this->assertSame(5, DB::table('buildings')->insertGetId(['code' => 'NEW', 'name' => 'x']));

        $this->artisan('db:verify-row-parity', [
            '--source' => 'import_test_source',
            '--target' => 'pgsql',
            '--exclude' => ['employees', 'buildings'],
            '--expect' => ['door_assignments=2', 'device_person_states=1'],
        ])->assertExitCode(0);
    }

    public function test_second_run_is_refused_because_the_target_is_not_empty(): void
    {
        $args = ['--source-path' => $this->sourcePath, '--execute' => true, '--confirm-target' => 'securegate_test'];
        $this->artisan('db:import-sqlite-to-pgsql', $args)->assertExitCode(0);

        $this->artisan('db:import-sqlite-to-pgsql', $args)
            ->expectsOutputToContain('target table employees already has rows')
            ->assertExitCode(1);

        $this->assertSame(3, DB::table('employees')->count());
    }

    public function test_orphans_and_bad_values_stop_the_import_before_any_write(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        $pdo->exec("insert into door_assignments (id, employee_id, door_id, sync_status, sync_attempts, sync_type) values (9, 99, 3, 'pending', 0, 'FULL')");
        $pdo->exec("update door_assignments set sync_status = 'done' where id = 1");
        $pdo->exec("update device_person_states set reasons = '[broken'");

        $exitCode = Artisan::call('db:import-sqlite-to-pgsql', ['--source-path' => $this->sourcePath, '--execute' => true, '--confirm-target' => 'securegate_test']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('door_assignments.employee_id references missing employees.id rows', $output);
        $this->assertStringContainsString("'done' is not allowed by the CHECK constraint", $output);
        $this->assertStringContainsString('device_person_states.reasons: invalid JSON', $output);
        $this->assertSame(0, DB::table('employees')->count());
    }

    private function seedSource(): void
    {
        $pdo = new PDO('sqlite:'.$this->sourcePath);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec("insert into buildings (id, code, name, is_active) values (2, '00002', 'B', 0), (4, 'gedung-a', 'A', 1)");
        $pdo->exec("insert into doors (id, door_id, location, building_id) values (3, 'DOOR-B', 'Lt 1', 4)");
        $pdo->exec("insert into employees (id, employee_id, nik, name, department, building_id, hire_date, supervisor_id, created_at) values
            (7, 'PKP-0007', '0007', 'Boss', 'Ops', 4, null, null, '2026-09-01 08:00:00'),
            (1, '00001', '00001', 'Siti Nur''aini', 'Ops', 2, '2024-02-01 00:00:00', 7, '2026-09-01T08:00:00.000000Z'),
            (2, '1', '1', 'One', 'Ops', 2, '2024-02-02', null, null)");
        $pdo->exec("insert into door_assignments (id, employee_id, door_id, sync_status, sync_attempts, sync_type) values (1, 1, 3, 'synced', 0, 'FULL'), (5, 2, 3, 'failed', 3, 'FULL')");
        $pdo->exec("insert into device_person_states (id, door_id, device_employee_no, employee_id, status, reasons) values (1, 3, '00001', 1, 'matched', '{\"basis\":\"employee_no\",\"note\":\"0001 ≠ 1\"}')");
    }
}

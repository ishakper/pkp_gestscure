<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PDO;
use Tests\TestCase;

class VerifyRowParityCommandTest extends TestCase
{
    private string $dir;
    private string $sourcePath;
    private string $targetPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/row-parity-'.uniqid();
        mkdir($this->dir);
        $this->sourcePath = $this->dir.'/source.sqlite';
        $this->targetPath = $this->dir.'/target.sqlite';

        $this->seedSqlite($this->sourcePath, ['employees' => 109, 'buildings' => 5, 'doors' => 4, 'door_assignments' => 98]);
        $this->seedSqlite($this->targetPath, ['employees' => 109, 'buildings' => 5, 'doors' => 4, 'door_assignments' => 98]);

        config(['database.connections.parity_test_target' => [
            'driver' => 'sqlite',
            'database' => $this->targetPath,
            'prefix' => '',
        ]]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);

        parent::tearDown();
    }

    public function test_matching_databases_pass_with_baseline(): void
    {
        $this->artisan('db:verify-row-parity', [
            '--source-path' => $this->sourcePath,
            '--target' => 'parity_test_target',
            '--expect' => ['employees=109', 'buildings=5', 'doors=4', 'door_assignments=98'],
        ])
            ->expectsOutputToContain('All 4 tables match.')
            ->assertExitCode(0);
    }

    public function test_count_mismatch_and_missing_table_fail(): void
    {
        $pdo = new PDO('sqlite:'.$this->targetPath);
        $pdo->exec('delete from door_assignments where id = 1');
        $pdo->exec('drop table doors');

        $exitCode = Artisan::call('db:verify-row-parity', [
            '--source-path' => $this->sourcePath,
            '--target' => 'parity_test_target',
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true);
        $statuses = array_column($report['tables'], 'status', 'table');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ok']);
        $this->assertSame([
            'buildings' => 'OK',
            'door_assignments' => 'COUNT_MISMATCH',
            'doors' => 'MISSING_IN_TARGET',
            'employees' => 'OK',
        ], $statuses);
    }

    public function test_baseline_mismatch_fails_even_when_sides_agree(): void
    {
        $this->artisan('db:verify-row-parity', [
            '--source-path' => $this->sourcePath,
            '--target' => 'parity_test_target',
            '--expect' => ['employees=110'],
        ])
            ->expectsOutputToContain('BASELINE_MISMATCH')
            ->assertExitCode(1);
    }

    public function test_excluded_tables_are_skipped(): void
    {
        (new PDO('sqlite:'.$this->targetPath))->exec('delete from doors');

        $this->artisan('db:verify-row-parity', [
            '--source-path' => $this->sourcePath,
            '--target' => 'parity_test_target',
            '--exclude' => ['doors'],
        ])
            ->expectsOutputToContain('All 3 tables match.')
            ->assertExitCode(0);
    }

    public function test_missing_source_file_fails_without_creating_it(): void
    {
        $missing = $this->dir.'/missing.sqlite';

        $this->artisan('db:verify-row-parity', [
            '--source-path' => $missing,
            '--target' => 'parity_test_target',
        ])
            ->expectsOutputToContain('source SQLite file not found')
            ->assertExitCode(1);

        $this->assertFileDoesNotExist($missing);
    }

    public function test_databases_are_left_unchanged(): void
    {
        $before = [hash_file('sha256', $this->sourcePath), hash_file('sha256', $this->targetPath)];

        $this->artisan('db:verify-row-parity', [
            '--source-path' => $this->sourcePath,
            '--target' => 'parity_test_target',
        ])->assertExitCode(0);

        $this->assertSame($before, [hash_file('sha256', $this->sourcePath), hash_file('sha256', $this->targetPath)]);
    }

    private function seedSqlite(string $path, array $tables): void
    {
        $pdo = new PDO('sqlite:'.$path);

        foreach ($tables as $table => $rows) {
            $pdo->exec("create table {$table} (id integer primary key)");
            for ($i = 1; $i <= $rows; $i++) {
                $pdo->exec("insert into {$table} (id) values ({$i})");
            }
        }
    }
}

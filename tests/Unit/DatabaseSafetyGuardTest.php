<?php

namespace Tests\Unit;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use RuntimeException;
use Tests\TestCase;

final class DatabaseSafetyGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Ensure clean testing environment config baseline
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_test');
    }

    /**
     * Requirement 1: Testing + PostgreSQL + securegate_test diterima.
     */
    public function test_testing_environment_accepts_pgsql_and_securegate_test(): void
    {
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_test');

        DatabaseSafetyGuard::enforce();
        $this->assertSame('pgsql', DatabaseSafetyGuard::REQUIRED_DRIVER);
        $this->assertSame('securegate_test', DatabaseSafetyGuard::REQUIRED_TEST_DATABASE);
    }

    /**
     * Requirement 1b: Testing + securegate_uat ditolak.
     */
    public function test_testing_environment_rejects_securegate_uat(): void
    {
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.connections.pgsql.database', 'securegate_uat');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: [testing] environment target database must be [securegate_test], configured as [securegate_uat]');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Requirement 4: Testing + securegate_db ditolak.
     */
    public function test_testing_environment_rejects_securegate_db(): void
    {
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.connections.pgsql.database', 'securegate_db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Target database [securegate_db] is prohibited in [testing] environment!');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Testing rejects non-pgsql driver (e.g. SQLite).
     */
    public function test_testing_environment_rejects_non_pgsql_driver(): void
    {
        App::detectEnvironment(fn () => 'testing');
        Config::set('database.connections.pgsql.driver', 'sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: [testing] environment driver must be [pgsql]');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Requirement 2: UAT + PostgreSQL + securegate_uat diterima.
     */
    public function test_uat_environment_accepts_pgsql_and_securegate_uat(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_uat');

        DatabaseSafetyGuard::enforce();
        $this->assertSame('securegate_uat', DatabaseSafetyGuard::REQUIRED_UAT_DATABASE);
    }

    /**
     * Requirement 5: UAT + securegate_test ditolak.
     */
    public function test_uat_environment_rejects_securegate_test(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_test');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: [uat] environment target database must be [securegate_uat], configured as [securegate_test]');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Requirement 3: UAT + securegate_db ditolak.
     */
    public function test_uat_environment_rejects_securegate_db(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Target database [securegate_db] is prohibited in [uat] environment!');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Requirement 6: UAT dengan SQLite/MySQL ditolak.
     */
    public function test_uat_environment_rejects_sqlite_and_mysql_drivers(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.database', 'securegate_uat');

        foreach (['sqlite', 'mysql'] as $invalidDriver) {
            Config::set('database.connections.pgsql.driver', $invalidDriver);

            try {
                DatabaseSafetyGuard::enforce();
                $this->fail("Expected RuntimeException for driver [{$invalidDriver}] in uat environment.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString("FATAL SAFETY VIOLATION: [uat] environment driver must be [pgsql], configured as [{$invalidDriver}]", $e->getMessage());
            }
        }
    }

    /**
     * Requirement 7: Environment UAT dengan database kosong ditolak.
     */
    public function test_uat_environment_rejects_empty_database(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Database target name is not configured or empty');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Fail-closed: Environment UAT dengan driver kosong ditolak.
     */
    public function test_uat_environment_rejects_empty_driver(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', '');
        Config::set('database.connections.pgsql.database', 'securegate_uat');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Database driver is not configured or empty');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Fail-closed: Environment UAT dengan default connection kosong ditolak.
     */
    public function test_uat_environment_rejects_empty_default_connection(): void
    {
        App::detectEnvironment(fn () => 'uat');
        Config::set('database.default', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Database default connection is not configured or empty');

        DatabaseSafetyGuard::enforce();
    }

    /**
     * Universal blacklist validation for both testing and uat environments.
     */
    public function test_rejects_all_prohibited_databases_in_guarded_environments(): void
    {
        foreach (['testing', 'uat'] as $env) {
            App::detectEnvironment(fn () => $env);
            Config::set('database.default', 'pgsql');
            Config::set('database.connections.pgsql.driver', 'pgsql');

            foreach (DatabaseSafetyGuard::PROHIBITED_DATABASES as $prohibited) {
                Config::set('database.connections.pgsql.database', $prohibited);

                try {
                    DatabaseSafetyGuard::enforce();
                    $this->fail("Expected RuntimeException for prohibited database [{$prohibited}] in [{$env}] environment.");
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString("FATAL SAFETY VIOLATION: Target database [{$prohibited}] is prohibited in [{$env}] environment!", $e->getMessage());
                }
            }
        }
    }

    /**
     * Production environment bypasses testing/uat allowlist check.
     */
    public function test_bypasses_check_in_production_environment(): void
    {
        App::detectEnvironment(fn () => 'production');
        Config::set('database.default', 'pgsql');
        Config::set('database.connections.pgsql.driver', 'pgsql');
        Config::set('database.connections.pgsql.database', 'production_db');

        // Should return early without throwing
        DatabaseSafetyGuard::enforce();
        $this->assertSame('production', App::environment());
    }

    /**
     * Constants integrity checks.
     */
    public function test_constants_integrity(): void
    {
        $this->assertSame('pgsql', DatabaseSafetyGuard::REQUIRED_DRIVER);
        $this->assertSame('securegate_test', DatabaseSafetyGuard::REQUIRED_TEST_DATABASE);
        $this->assertSame('securegate_uat', DatabaseSafetyGuard::REQUIRED_UAT_DATABASE);
        $this->assertSame(['testing', 'uat'], DatabaseSafetyGuard::GUARDED_ENVIRONMENTS);
        $this->assertSame([
            'testing' => 'securegate_test',
            'uat' => 'securegate_uat',
        ], DatabaseSafetyGuard::ENVIRONMENT_DATABASE_ALLOWLIST);

        foreach (DatabaseSafetyGuard::PROHIBITED_DATABASES as $db) {
            $this->assertContains($db, [
                'securegate_db',
                'securegate',
                'forge',
                'production',
                'homestead',
            ]);
        }
    }
}

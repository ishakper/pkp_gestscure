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

    public function test_passes_when_configured_with_valid_isolated_test_target(): void
    {
        DatabaseSafetyGuard::enforce();
        $this->assertSame('pgsql', DatabaseSafetyGuard::REQUIRED_DRIVER);
        $this->assertSame('securegate_test', DatabaseSafetyGuard::REQUIRED_TEST_DATABASE);
    }

    public function test_rejects_non_pgsql_driver(): void
    {
        Config::set('database.connections.pgsql.driver', 'sqlite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Test suite driver must be [pgsql]');

        DatabaseSafetyGuard::enforce();
    }

    public function test_rejects_non_test_database_target(): void
    {
        Config::set('database.connections.pgsql.database', 'custom_db');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FATAL SAFETY VIOLATION: Test suite target database must be [securegate_test]');

        DatabaseSafetyGuard::enforce();
    }

    public function test_rejects_prohibited_databases(): void
    {
        foreach (DatabaseSafetyGuard::PROHIBITED_DATABASES as $prohibited) {
            Config::set('database.connections.pgsql.database', $prohibited);

            try {
                DatabaseSafetyGuard::enforce();
                $this->fail("Expected RuntimeException for prohibited database: {$prohibited}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('FATAL SAFETY VIOLATION', $e->getMessage());
            }
        }
    }

    public function test_bypasses_check_in_non_testing_environment(): void
    {
        App::detectEnvironment(fn () => 'production');
        Config::set('database.connections.pgsql.database', 'securegate_db');

        // Should return early without throwing
        DatabaseSafetyGuard::enforce();
        $this->assertSame('production', App::environment());
    }

    public function test_prohibits_dangerous_database_names_constant(): void
    {
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

    public function test_requires_pgsql_driver_constant(): void
    {
        $this->assertSame('pgsql', DatabaseSafetyGuard::REQUIRED_DRIVER);
    }

    public function test_requires_securegate_test_database_constant(): void
    {
        $this->assertSame('securegate_test', DatabaseSafetyGuard::REQUIRED_TEST_DATABASE);
    }
}

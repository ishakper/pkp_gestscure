<?php

namespace Tests\Unit;

use App\Support\DatabaseSafetyGuard;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

class DatabaseSafetyGuardTest extends TestCase
{
    private string $originalEnv;
    private string $originalDefault;
    private array $originalPgsqlConfig;
    private array $originalAllConnections;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = (string) config('app.env');
        $this->originalDefault = (string) config('database.default');
        $this->originalPgsqlConfig = (array) config('database.connections.pgsql', []);
        $this->originalAllConnections = (array) config('database.connections', []);
    }

    protected function tearDown(): void
    {
        config([
            'app.env' => $this->originalEnv,
            'database.default' => $this->originalDefault,
            'database.connections' => $this->originalAllConnections,
            'database.connections.pgsql' => $this->originalPgsqlConfig,
        ]);
        parent::tearDown();
    }

    public function test_testing_guard_fails_when_app_env_is_not_testing(): void
    {
        config([
            'app.env' => 'production',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'securegate_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/PHPUnit must run in testing environment/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_driver_is_not_pgsql(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/PostgreSQL \(\'pgsql\'\) is the ONLY official database driver permitted/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_is_production_securegate(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Testing target cannot be production\/development database \'securegate\'/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_is_development_securegate_db(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate_db',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Testing target cannot be production\/development database \'securegate_db\'/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_host_is_production_ip(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '10.10.8.124',
            'database.connections.pgsql.database' => 'securegate_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Testing cannot target production host/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_name_is_empty(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => '',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Testing database name cannot be empty/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_is_not_allowlisted(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'unallowlisted_database',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not in the explicit allowlist/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_url_overrides_to_forbidden_database(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'securegate_test',
            'database.connections.pgsql.url' => 'postgres://securegate:pass@127.0.0.1:5432/securegate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Testing target cannot be production\/development database \'securegate\'/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_fails_when_database_url_is_malformed(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => '://invalid_url',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Malformed database connection URL detected/i');

        DatabaseSafetyGuard::enforceTestingEnvironment();
    }

    public function test_testing_guard_passes_with_official_securegate_test_database(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate_test',
            'database.connections.pgsql.url' => null,
        ]);

        DatabaseSafetyGuard::enforceTestingEnvironment();
        $this->assertTrue(true);
    }

    public function test_destructive_command_blocked_on_production_environment(): void
    {
        config([
            'app.env' => 'production',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate_test',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/strictly prohibited in production environment/i');

        DatabaseSafetyGuard::enforceCommand('migrate:fresh');
    }

    public function test_destructive_command_blocked_against_production_database_regardless_of_env(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Destructive command \'migrate:fresh\' targeting forbidden database \'securegate\' is strictly BLOCKED/i');

        DatabaseSafetyGuard::enforceCommand('migrate:fresh');
    }

    public function test_destructive_command_blocked_against_development_database(): void
    {
        config([
            'app.env' => 'local',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate_db',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/targeting forbidden database \'securegate_db\' is strictly BLOCKED/i');

        DatabaseSafetyGuard::enforceCommand('db:wipe');
    }

    public function test_destructive_command_allowed_in_testing_against_securegate_test(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.database' => 'securegate_test',
            'database.connections.pgsql.url' => null,
        ]);

        DatabaseSafetyGuard::enforceCommand('migrate:fresh');
        $this->assertTrue(true);
    }

    public function test_non_destructive_commands_are_ignored_by_command_guard(): void
    {
        config([
            'app.env' => 'production',
            'database.default' => 'pgsql',
            'database.connections.pgsql.database' => 'securegate',
        ]);

        DatabaseSafetyGuard::enforceCommand('migrate:status');
        DatabaseSafetyGuard::enforceCommand('route:list');
        $this->assertTrue(true);
    }

    public function test_resolver_enforces_isolated_test_database_during_testing(): void
    {
        config(['app.env' => 'testing']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Prohibited database \'securegate\' in testing/i');

        DatabaseSafetyGuard::enforceResolver('pgsql', 'securegate', []);
    }

    public function test_destructive_command_blocked_when_database_option_targets_production_database(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
            'database.connections.prod_conn' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Destructive command \'migrate:fresh\' targeting forbidden database \'securegate\' is strictly BLOCKED/i');

        $input = new ArrayInput(['--database' => 'prod_conn']);
        DatabaseSafetyGuard::enforceCommand('migrate:fresh', $input);
    }

    public function test_destructive_command_blocked_when_database_option_space_syntax_targets_production_database(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
            'database.connections.prod_conn' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Destructive command \'migrate:fresh\' targeting forbidden database \'securegate\' is strictly BLOCKED/i');

        $input = new ArgvInput(['artisan', 'migrate:fresh', '--database', 'prod_conn']);
        DatabaseSafetyGuard::enforceCommand('migrate:fresh', $input);
    }

    public function test_destructive_command_blocked_when_database_option_targets_production_host(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
            'database.connections.remote_conn' => [
                'driver' => 'pgsql',
                'host' => '10.10.8.200',
                'database' => 'securegate_test',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Destructive command \'migrate:fresh\' targeting production network host \'10.10.8.200\' is strictly BLOCKED/i');

        $input = new ArrayInput(['--database' => 'remote_conn']);
        DatabaseSafetyGuard::enforceCommand('migrate:fresh', $input);
    }

    public function test_destructive_command_blocked_when_database_option_specifies_unknown_connection(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SAFETY GUARD TRIGGERED: Unknown database connection \'unknown_custom_conn\' specified for destructive command \'migrate:fresh\'/i');

        $input = new ArrayInput(['--database' => 'unknown_custom_conn']);
        DatabaseSafetyGuard::enforceCommand('migrate:fresh', $input);
    }

    public function test_destructive_command_allowed_when_database_option_specifies_valid_test_connection(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'pgsql',
            'database.connections.pgsql' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
            'database.connections.custom_test_conn' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'database' => 'securegate_test',
            ],
        ]);

        $input = new ArrayInput(['--database' => 'custom_test_conn']);
        DatabaseSafetyGuard::enforceCommand('migrate:fresh', $input);
        $this->assertTrue(true);
    }

    public function test_destructive_command_blocked_when_default_connection_is_not_configured(): void
    {
        config([
            'app.env' => 'testing',
            'database.default' => 'unconfigured_conn',
            'database.connections' => [
                'pgsql' => [
                    'driver' => 'pgsql',
                    'host' => '127.0.0.1',
                    'database' => 'securegate_test',
                ],
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/SAFETY GUARD TRIGGERED: Default database connection \'unconfigured_conn\' is not defined in database configuration/i');

        DatabaseSafetyGuard::enforceCommand('migrate:fresh');
    }

    public function test_resolve_target_connection_from_various_inputs(): void
    {
        // 1. ArgvInput --database=foo
        $argv1 = new ArgvInput(['artisan', 'migrate:fresh', '--database=foo_conn']);
        $this->assertSame('foo_conn', DatabaseSafetyGuard::resolveTargetConnectionFromInput($argv1));

        // 2. ArgvInput --database foo
        $argv2 = new ArgvInput(['artisan', 'migrate:fresh', '--database', 'bar_conn']);
        $this->assertSame('bar_conn', DatabaseSafetyGuard::resolveTargetConnectionFromInput($argv2));

        // 3. ArrayInput ['--database' => 'baz_conn']
        $arrayInput = new ArrayInput(['--database' => 'baz_conn']);
        $this->assertSame('baz_conn', DatabaseSafetyGuard::resolveTargetConnectionFromInput($arrayInput));

        // 4. Raw array with --database=qux_conn
        $rawArray = ['migrate:fresh', '--database=qux_conn'];
        $this->assertSame('qux_conn', DatabaseSafetyGuard::resolveTargetConnectionFromInput($rawArray));

        // 5. Raw string with --database qux_conn
        $rawString = 'migrate:fresh --database custom_str_conn';
        $this->assertSame('custom_str_conn', DatabaseSafetyGuard::resolveTargetConnectionFromInput($rawString));

        // 6. Null input
        $this->assertNull(DatabaseSafetyGuard::resolveTargetConnectionFromInput(null));

        // 7. Input with no --database
        $argvNoDb = new ArgvInput(['artisan', 'migrate:fresh', '--force']);
        $this->assertNull(DatabaseSafetyGuard::resolveTargetConnectionFromInput($argvNoDb));
    }
}

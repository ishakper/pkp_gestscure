<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard quality gate that guarantees the automated test suite and UAT environments
 * can only ever run against their designated isolated PostgreSQL databases:
 * - testing environment: strictly `securegate_test`
 * - uat environment: strictly `securegate_uat`
 *
 * Protects against accidental execution against `securegate_db` (development),
 * `forge`, unconfigured targets, or SQLite/MySQL fallbacks.
 */
final class DatabaseSafetyGuard
{
    public const REQUIRED_DRIVER = 'pgsql';
    public const REQUIRED_TEST_DATABASE = 'securegate_test';
    public const REQUIRED_UAT_DATABASE = 'securegate_uat';

    public const GUARDED_ENVIRONMENTS = [
        'testing',
        'uat',
    ];

    public const ENVIRONMENT_DATABASE_ALLOWLIST = [
        'testing' => self::REQUIRED_TEST_DATABASE,
        'uat' => self::REQUIRED_UAT_DATABASE,
    ];

    public const PROHIBITED_DATABASES = [
        'securegate_db',
        'securegate',
        'forge',
        'production',
        'homestead',
    ];

    /**
     * Enforce static configuration isolation checks.
     * Invoked from CreatesApplication, early bootstrap, and AppServiceProvider.
     *
     * @throws RuntimeException if any safety invariant is violated.
     */
    public static function enforce(): void
    {
        $currentEnv = (string) App::environment();

        // 1. Guard only targets guarded environments (testing, uat).
        // APP_ENV=production and other non-guarded environments remain untouched.
        if (!in_array($currentEnv, self::GUARDED_ENVIRONMENTS, true)) {
            return;
        }

        // 2. Fail-closed check: verify connection configuration presence
        $defaultConnection = config('database.default');
        if (!is_string($defaultConnection) || trim($defaultConnection) === '') {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Database default connection is not configured or empty for [%s] environment.',
                $currentEnv
            ));
        }

        $configuredDriver = config("database.connections.{$defaultConnection}.driver");
        if (!is_string($configuredDriver) || trim($configuredDriver) === '') {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Database driver is not configured or empty on connection [%s] for [%s] environment.',
                $defaultConnection,
                $currentEnv
            ));
        }

        $configuredDb = config("database.connections.{$defaultConnection}.database");
        if (!is_string($configuredDb) || trim($configuredDb) === '') {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Database target name is not configured or empty on connection [%s] for [%s] environment.',
                $defaultConnection,
                $currentEnv
            ));
        }

        $configuredDbNormalized = strtolower(trim($configuredDb));

        // 3. Strict Driver Requirement (PostgreSQL only for testing and uat)
        if ($configuredDriver !== self::REQUIRED_DRIVER) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: [%s] environment driver must be [%s], configured as [%s] on connection [%s].',
                $currentEnv,
                self::REQUIRED_DRIVER,
                $configuredDriver,
                $defaultConnection
            ));
        }

        // 4. Guarded Environments Blacklist Check (Evaluated BEFORE allowlist validation on testing/uat)
        if (in_array($configuredDbNormalized, self::PROHIBITED_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Target database [%s] is prohibited in [%s] environment! Operation terminated.',
                $configuredDb,
                $currentEnv
            ));
        }

        // 5. Strict Environment Allowlist Check
        $expectedDatabase = self::ENVIRONMENT_DATABASE_ALLOWLIST[$currentEnv] ?? null;
        if ($expectedDatabase === null || $configuredDb !== $expectedDatabase) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: [%s] environment target database must be [%s], configured as [%s]. Refusing to run.',
                $currentEnv,
                $expectedDatabase ?? 'undefined',
                $configuredDb
            ));
        }
    }

    /**
     * Verifies that the active PDO connection is alive, effectively using PostgreSQL,
     * and connected strictly to the expected isolated database for the current environment.
     * Fail-closed: Throws RuntimeException on connection error or invariant mismatch.
     *
     * @throws RuntimeException
     */
    public static function assertConnectionSafe(): void
    {
        self::enforce();

        $currentEnv = (string) App::environment();
        if (!in_array($currentEnv, self::GUARDED_ENVIRONMENTS, true)) {
            return;
        }

        $expectedDatabase = self::ENVIRONMENT_DATABASE_ALLOWLIST[$currentEnv] ?? null;

        try {
            $connection = DB::connection();
            // Force live connection probe
            $connection->getPdo();
            $actualDriver = (string) $connection->getDriverName();
            $actualDb = (string) $connection->getDatabaseName();
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Live database connection probe failed: [%s]. Refusing to proceed in [%s] environment.',
                $e->getMessage(),
                $currentEnv
            ), 0, $e);
        }

        if ($actualDriver !== self::REQUIRED_DRIVER) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Active PDO driver is [%s], expected [%s] in [%s] environment.',
                $actualDriver,
                self::REQUIRED_DRIVER,
                $currentEnv
            ));
        }

        if (in_array(strtolower($actualDb), self::PROHIBITED_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Connected to protected database [%s] in [%s] environment! Operation terminated.',
                $actualDb,
                $currentEnv
            ));
        }

        if ($actualDb !== $expectedDatabase) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Active database is [%s], expected [%s] for [%s] environment. Aborting.',
                $actualDb,
                $expectedDatabase,
                $currentEnv
            ));
        }
    }
}

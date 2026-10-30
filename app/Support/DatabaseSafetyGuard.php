<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard quality gate that guarantees the automated test suite can only ever run
 * against the isolated PostgreSQL test database (`securegate_test`).
 *
 * Protects against accidental execution against `securegate_db` (development),
 * `forge`, unconfigured targets, or SQLite fallbacks.
 */
final class DatabaseSafetyGuard
{
    public const REQUIRED_DRIVER = 'pgsql';
    public const REQUIRED_TEST_DATABASE = 'securegate_test';
    public const PROHIBITED_DATABASES = [
        'securegate_db',
        'securegate',
        'forge',
        'production',
        'homestead',
    ];

    /**
     * Enforce static configuration isolation checks.
     * Invoked from CreatesApplication and early bootstrap.
     *
     * @throws RuntimeException if any safety invariant is violated.
     */
    public static function enforce(): void
    {
        if (!App::environment('testing')) {
            return;
        }

        // 1. Static config validation (fast-fail before connection attempt)
        $defaultConnection = config('database.default');
        $configuredDriver = config("database.connections.{$defaultConnection}.driver");
        $configuredDb = config("database.connections.{$defaultConnection}.database");

        if ($configuredDriver !== self::REQUIRED_DRIVER) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Test suite driver must be [%s], configured as [%s] on connection [%s].',
                self::REQUIRED_DRIVER,
                $configuredDriver ?? 'null',
                $defaultConnection
            ));
        }

        if ($configuredDb !== self::REQUIRED_TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Test suite target database must be [%s], configured as [%s]. Refusing to run.',
                self::REQUIRED_TEST_DATABASE,
                $configuredDb ?? 'null'
            ));
        }

        if (in_array(strtolower((string) $configuredDb), self::PROHIBITED_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Test suite target database [%s] is prohibited! Operation terminated.',
                $configuredDb
            ));
        }
    }

    /**
     * Verifies that the active PDO connection is alive, effectively using PostgreSQL,
     * and connected strictly to the isolated test database `securegate_test`.
     * Fail-closed: Throws RuntimeException on connection error or invariant mismatch.
     *
     * @throws RuntimeException
     */
    public static function assertConnectionSafe(): void
    {
        self::enforce();

        try {
            $connection = DB::connection();
            // Force live connection probe
            $connection->getPdo();
            $actualDriver = $connection->getDriverName();
            $actualDb = $connection->getDatabaseName();
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Live database connection probe failed: [%s]. Refusing to proceed in testing environment.',
                $e->getMessage()
            ), 0, $e);
        }

        if ($actualDriver !== self::REQUIRED_DRIVER) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Active PDO driver is [%s], expected [%s].',
                $actualDriver,
                self::REQUIRED_DRIVER
            ));
        }

        if ($actualDb !== self::REQUIRED_TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Active database is [%s], expected [%s]. Aborting.',
                $actualDb,
                self::REQUIRED_TEST_DATABASE
            ));
        }

        if (in_array(strtolower((string) $actualDb), self::PROHIBITED_DATABASES, true)) {
            throw new RuntimeException(sprintf(
                'FATAL SAFETY VIOLATION: Connected to protected database [%s]! Operation terminated.',
                $actualDb
            ));
        }
    }
}

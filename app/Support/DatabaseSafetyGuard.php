<?php

namespace App\Support;

class DatabaseSafetyGuard
{
    /**
     * Explicit allowlist of permissible test database names.
     */
    public const OFFICIAL_TEST_DATABASE = 'securegate_test';
    public const DISPOSABLE_VERIFICATION_DATABASE = 'securegate_verification';

    /**
     * Explicit denylist of strictly protected database names.
     */
    public const FORBIDDEN_DATABASES = [
        'securegate_db',
        'securegate_prod',
        'securegate_production',
        'production',
        'forge',
        'securegate_staging',
    ];

    /**
     * Enforce strict PostgreSQL connection and database isolation guard.
     * Fail-closed: terminates immediately if identity cannot be verified or violates policy.
     *
     * @throws \RuntimeException
     */
    public static function enforce(): void
    {
        $appEnv = (string) config('app.env');
        $isTesting = ($appEnv === 'testing') || (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) || (function_exists('app') && app()->runningUnitTests());

        if (!$isTesting) {
            return;
        }

        // 1. Validate Database Driver: ONLY pgsql is permitted
        $defaultConnection = (string) config('database.default');
        if ($defaultConnection !== 'pgsql') {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: PostgreSQL ('pgsql') is the ONLY official database driver permitted for testing. " .
                "Detected driver: '{$defaultConnection}'. SQLite fallback is strictly prohibited."
            );
        }

        // 2. Extract and sanitize target database name
        $databaseName = (string) config("database.connections.{$defaultConnection}.database");
        $sanitizedDbName = strtolower(trim($databaseName));

        if ($sanitizedDbName === '') {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: Database name cannot be empty. Identity verification failed."
            );
        }

        // 3. Denylist Check: Development and Production Databases are strictly forbidden
        if (in_array($sanitizedDbName, self::FORBIDDEN_DATABASES, true)) {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: Destruction prevention active! " .
                "Tests and migrations are strictly prohibited from targeting development or production database '{$databaseName}'. " .
                "Database 'securegate_db' and production databases must remain untouched."
            );
        }

        // 4. Explicit Allowlist Check
        $allowed = false;

        if ($sanitizedDbName === self::OFFICIAL_TEST_DATABASE) {
            $allowed = true;
        } elseif ($sanitizedDbName === self::DISPOSABLE_VERIFICATION_DATABASE) {
            if (env('DISPOSABLE_VERIFICATION') === 'true' || env('DISPOSABLE_VERIFICATION') === true) {
                $allowed = true;
            } else {
                throw new \RuntimeException(
                    "SAFETY GUARD TRIGGERED: Database '{$databaseName}' is a disposable verification database " .
                    "but DISPOSABLE_VERIFICATION=true flag is not set."
                );
            }
        }

        if (!$allowed) {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: Database '{$databaseName}' is not in the explicit test allowlist. " .
                "Only official test database '" . self::OFFICIAL_TEST_DATABASE . "' (or explicitly flagged '" .
                self::DISPOSABLE_VERIFICATION_DATABASE . "') is permitted."
            );
        }
    }
}

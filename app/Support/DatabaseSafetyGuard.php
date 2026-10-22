<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;

class DatabaseSafetyGuard
{
    /**
     * The single official allowlisted database for testing.
     */
    public const OFFICIAL_TEST_DATABASE = 'securegate_test';

    /**
     * Dedicated testing role for CI / test runner.
     */
    public const CI_TEST_USER = 'securegate_ci';

    /**
     * Strictly forbidden databases for testing and destructive operations.
     */
    public const FORBIDDEN_DATABASES = [
        'securegate',              // Production Database
        'securegate_db',           // Development Database
        'securegate_prod',
        'securegate_production',
        'securegate_staging',
        'access_door_production',
        'access_door_staging',
        'production',
        'forge',
    ];

    /**
     * Production IP prefixes / hosts strictly forbidden for testing.
     */
    public const FORBIDDEN_HOST_PATTERNS = [
        '10.10.8.',
    ];

    /**
     * Destructive console commands requiring strict validation.
     */
    public const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'db:wipe',
        'migrate:reset',
        'migrate:refresh',
    ];

    /**
     * Enforce strict safety rules on destructive console commands.
     *
     * @throws RuntimeException
     */
    public static function enforceCommand(?string $command, $input = null): void
    {
        if ($command === null) {
            return;
        }

        $normalizedCommand = strtolower(trim($command));
        if (!in_array($normalizedCommand, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        $targetConn = self::resolveTargetConnectionFromInput($input);
        $allConnections = (array) config('database.connections', []);

        if ($targetConn !== null && $targetConn !== '') {
            if (!array_key_exists($targetConn, $allConnections)) {
                throw new RuntimeException(
                    "SAFETY GUARD TRIGGERED: Unknown database connection '{$targetConn}' specified for destructive command '{$normalizedCommand}'."
                );
            }
            $connToUse = $targetConn;
        } else {
            $defaultConn = (string) config('database.default', 'pgsql');
            if (!array_key_exists($defaultConn, $allConnections)) {
                throw new RuntimeException(
                    "SAFETY GUARD TRIGGERED: Default database connection '{$defaultConn}' is not defined in database configuration."
                );
            }
            $connToUse = $defaultConn;
        }

        $effectiveConfig = self::resolveEffectiveConfig($connToUse);

        $dbName = strtolower(trim($effectiveConfig['database'] ?? ''));
        $dbHost = strtolower(trim($effectiveConfig['host'] ?? ''));
        $appEnv = (string) config('app.env', 'production');

        // 1. Production database check (Absolute prohibition in all environments)
        if ($dbName === '' || in_array($dbName, self::FORBIDDEN_DATABASES, true)) {
            throw new RuntimeException(
                "CRITICAL SECURITY HALT: Destructive command '{$normalizedCommand}' targeting forbidden database '{$dbName}' is strictly BLOCKED."
            );
        }

        // 2. Production host check
        foreach (self::FORBIDDEN_HOST_PATTERNS as $pattern) {
            if (str_starts_with($dbHost, $pattern)) {
                throw new RuntimeException(
                    "CRITICAL SECURITY HALT: Destructive command '{$normalizedCommand}' targeting production network host '{$dbHost}' is strictly BLOCKED."
                );
            }
        }

        // 3. Absolute block on production environment
        if ($appEnv === 'production') {
            throw new RuntimeException(
                "CRITICAL SECURITY HALT: Destructive command '{$normalizedCommand}' is strictly prohibited in production environment."
            );
        }

        // 4. In testing environment, enforce official test database allowlist
        $isTesting = ($appEnv === 'testing') || (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING);
        if ($isTesting && $dbName !== self::OFFICIAL_TEST_DATABASE) {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: Destructive command '{$normalizedCommand}' target '{$dbName}' is not allowlisted. Only '" . self::OFFICIAL_TEST_DATABASE . "' is permitted."
            );
        }
    }

    /**
     * Enforce strict configuration before PHPUnit test suite bootstrap.
     *
     * @throws RuntimeException
     */
    public static function enforceTestingEnvironment(): void
    {
        $appEnv = (string) config('app.env');
        if ($appEnv !== 'testing') {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: PHPUnit must run in testing environment (APP_ENV=testing). Current APP_ENV: '{$appEnv}'."
            );
        }

        $defaultConn = (string) config('database.default');
        if ($defaultConn !== 'pgsql') {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: PostgreSQL ('pgsql') is the ONLY official database driver permitted for testing. Detected driver: '{$defaultConn}'."
            );
        }

        $effectiveConfig = self::resolveEffectiveConfig($defaultConn);
        $dbName = strtolower(trim($effectiveConfig['database'] ?? ''));
        $dbHost = strtolower(trim($effectiveConfig['host'] ?? ''));

        if ($dbName === '') {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: Testing database name cannot be empty."
            );
        }

        if (in_array($dbName, self::FORBIDDEN_DATABASES, true)) {
            throw new RuntimeException(
                "CRITICAL SECURITY HALT: Testing target cannot be production/development database '{$dbName}'."
            );
        }

        foreach (self::FORBIDDEN_HOST_PATTERNS as $pattern) {
            if (str_starts_with($dbHost, $pattern)) {
                throw new RuntimeException(
                    "CRITICAL SECURITY HALT: Testing cannot target production host '{$dbHost}'."
                );
            }
        }

        if ($dbName !== self::OFFICIAL_TEST_DATABASE) {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: Testing database '{$dbName}' is not in the explicit allowlist. Only '" . self::OFFICIAL_TEST_DATABASE . "' is permitted."
            );
        }
    }

    /**
     * Extra defense layer on connection resolution.
     *
     * @throws RuntimeException
     */
    public static function enforceResolver(string $driver, string $database, array $config): void
    {
        $appEnv = (string) config('app.env');
        $isTesting = ($appEnv === 'testing') || (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) || (function_exists('app') && app()->runningUnitTests());

        if (!$isTesting) {
            return;
        }

        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: Driver '{$driver}' is prohibited in testing. Only 'pgsql' is allowed."
            );
        }

        $dbName = strtolower(trim($database));
        if ($dbName !== self::OFFICIAL_TEST_DATABASE) {
            throw new RuntimeException(
                "SAFETY GUARD TRIGGERED: Prohibited database '{$dbName}' in testing. Only '" . self::OFFICIAL_TEST_DATABASE . "' is allowed."
            );
        }
    }

    /**
     * Resolve target database connection specified via --database option from console input.
     *
     * @param mixed $input
     * @return string|null
     */
    public static function resolveTargetConnectionFromInput($input): ?string
    {
        if ($input === null) {
            return null;
        }

        if ($input instanceof InputInterface) {
            if ($input->hasParameterOption('--database')) {
                $val = $input->getParameterOption('--database');
                if ($val !== false && $val !== null && trim((string) $val) !== '') {
                    return trim((string) $val);
                }
            }

            if (method_exists($input, 'hasOption') && $input->hasOption('database')) {
                try {
                    $val = $input->getOption('database');
                    if ($val !== null && trim((string) $val) !== '') {
                        return trim((string) $val);
                    }
                } catch (\Throwable $e) {
                    // Option might not be bound or initialized; ignore safely
                }
            }
        }

        if (is_array($input)) {
            if (!empty($input['--database'])) {
                return trim((string) $input['--database']);
            }
            if (!empty($input['database'])) {
                return trim((string) $input['database']);
            }

            foreach ($input as $idx => $arg) {
                if (is_string($arg)) {
                    if (str_starts_with($arg, '--database=')) {
                        return trim(substr($arg, strlen('--database=')));
                    }
                    if ($arg === '--database' && isset($input[$idx + 1])) {
                        return trim((string) $input[$idx + 1]);
                    }
                }
            }
        }

        if (is_string($input)) {
            if (preg_match('/--database[=\s]+([^\s]+)/', $input, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Safely resolve effective connection configuration, taking into account
     * DATABASE_URL and DB_URL if present, without exposing passwords.
     */
    public static function resolveEffectiveConfig(string $connection = 'pgsql'): array
    {
        $rawConfig = (array) config("database.connections.{$connection}", []);

        // Inspect URL parameter
        $url = $rawConfig['url'] ?? env('DATABASE_URL') ?? env('DB_URL');
        if (!empty($url)) {
            $parsedUrl = @parse_url($url);
            if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
                throw new RuntimeException("SAFETY GUARD TRIGGERED: Malformed database connection URL detected.");
            }

            try {
                $parsed = (new ConfigurationUrlParser)->parseConfiguration(['url' => $url]);
                $rawConfig = array_merge($rawConfig, $parsed);
            } catch (\Throwable $e) {
                throw new RuntimeException("SAFETY GUARD TRIGGERED: Malformed database connection URL detected.");
            }
        }

        return $rawConfig;
    }
}

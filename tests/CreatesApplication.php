<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $this->enforceDatabaseSafetyGuard();

        return $app;
    }

    /**
     * Enforce strict PostgreSQL and isolated test database guard.
     * Prevents tests from running against development database (securegate_db)
     * or silently falling back to SQLite.
     */
    protected function enforceDatabaseSafetyGuard(): void
    {
        $defaultConnection = (string) config('database.default');

        if ($defaultConnection !== 'pgsql') {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: PostgreSQL is the only official database driver for testing. " .
                "Detected driver: '{$defaultConnection}'. SQLite fallback is strictly prohibited."
            );
        }

        $databaseName = (string) config("database.connections.{$defaultConnection}.database");

        $forbiddenDatabases = ['securegate_db', 'securegate_prod', 'production', 'securegate_production'];
        if (in_array(strtolower($databaseName), $forbiddenDatabases, true)) {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: Destruction prevention active! " .
                "Tests are strictly prohibited from targeting development or production database '{$databaseName}'. " .
                "Target database must be 'securegate_test' or an isolated test database."
            );
        }

        $lowerDb = strtolower($databaseName);
        if (!str_ends_with($lowerDb, 'test') && !str_ends_with($lowerDb, 'verification')) {
            throw new \RuntimeException(
                "SAFETY GUARD TRIGGERED: Test database name must end with 'test' or 'verification'. " .
                "Current target: '{$databaseName}'."
            );
        }
    }
}

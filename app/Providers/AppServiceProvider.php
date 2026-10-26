<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\DeviceHealthService::class, function ($app) {
            return new \App\Services\DeviceHealthService(
                $app->make(\App\Services\HikvisionIsapiService::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce Database Safety Guard for Testing Environment (Lazy connection evaluation)
        if (App::environment('testing')) {
            $this->app->resolving('db', function ($db) {
                $connection = $db->connection();
                $driver = $connection->getDriverName();
                $dbName = $connection->getDatabaseName();

                if ($driver !== 'pgsql') {
                    throw new RuntimeException("FATAL SAFETY VIOLATION: Test environment must use PostgreSQL ('pgsql') driver, but driver is '{$driver}'.");
                }

                if ($dbName === 'securegate_db' || $dbName === 'forge' || empty($dbName)) {
                    throw new RuntimeException("FATAL SAFETY VIOLATION: Test suite attempted to connect to prohibited or empty database '{$dbName}'!");
                }

                if ($dbName !== 'securegate_test') {
                    throw new RuntimeException("FATAL SAFETY VIOLATION: Test suite must connect to 'securegate_test', but connected to '{$dbName}'.");
                }
            });
        }
    }
}

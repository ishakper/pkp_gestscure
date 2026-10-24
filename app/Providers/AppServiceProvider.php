<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;

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
                try {
                    $dbName = $db->connection()->getDatabaseName();
                    if ($dbName === 'securegate_db' || $dbName === 'forge') {
                        throw new \RuntimeException("FATAL SAFETY VIOLATION: Test suite attempted to connect to development/production database '{$dbName}'!");
                    }
                } catch (\Throwable $e) {
                    // If connection fails during early boot, let Laravel handle it normally unless it's a safety violation
                    if (str_contains($e->getMessage(), 'FATAL SAFETY VIOLATION')) {
                        throw $e;
                    }
                }
            });
        }
    }
}

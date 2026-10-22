<?php

namespace App\Providers;

use App\Database\PostgresConnection;
use App\Support\DatabaseSafetyGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Postgres connections use a grammar that keeps LIKE case-insensitive, as it is on SQLite.
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            DatabaseSafetyGuard::enforceResolver('pgsql', (string) $database, (array) $config);

            return new PostgresConnection($connection, $database, $prefix, $config);
        });

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
        if ($this->app->runningInConsole()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event) {
                DatabaseSafetyGuard::enforceCommand($event->command, $event->input);
            });
        }
    }
}

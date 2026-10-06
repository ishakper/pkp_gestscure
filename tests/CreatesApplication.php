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

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * Tests wipe and re-migrate the database. Outside SQLite, refuse to run unless the
     * target database is clearly a throwaway one (name ending in _test), so a stray
     * DB_HOST/DB_DATABASE can never point the suite at a real database.
     */
    private function guardAgainstNonTestDatabase(Application $app): void
    {
        $config = $app['config'];
        $connection = $config->get('database.default');

        if ($config->get("database.connections.{$connection}.driver") === 'sqlite') {
            return;
        }

        $database = (string) $config->get("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Refusing to run tests against database [{$database}] on [{$connection}]: the name must end with _test.");
        }
    }
}

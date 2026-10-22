<?php

namespace Tests;

use App\Support\DatabaseSafetyGuard;
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

        DatabaseSafetyGuard::enforceTestingEnvironment();

        return $app;
    }
}

<?php

namespace Database\Seeders;

use App\Support\DatabaseSafetyGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use RuntimeException;

class UatDatabaseSeeder extends Seeder
{
    /**
     * Run the UAT isolated database seeds.
     * Enforces that this seeder can ONLY ever run in the uat or testing environment
     * and strictly against PostgreSQL `securegate_uat` (or `securegate_test`).
     */
    public function run(): void
    {
        // 1. Enforce database safety before seeding
        DatabaseSafetyGuard::enforce();

        $env = (string) App::environment();
        if (!in_array($env, ['uat', 'testing'], true)) {
            throw new RuntimeException("FATAL SAFETY VIOLATION: UatDatabaseSeeder can only be executed in [uat] or [testing] environment, currently in [{$env}].");
        }

        // UAT seeding is intentionally empty. No demo data or default accounts.
        return;
    }
}

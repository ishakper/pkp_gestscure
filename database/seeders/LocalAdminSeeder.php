<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LocalAdminSeeder extends Seeder
{
    /**
     * Local development admin account seeder.
     * NEVER runs on production.
     * Reads LOCAL_SUPER_ADMIN_EMAIL and LOCAL_SUPER_ADMIN_PASSWORD from env.
     */
    public function run(): void
    {
        // Refuse to run on production
        if (app()->environment('production')) {
            throw new \RuntimeException('LocalAdminSeeder is forbidden on production.');
        }

        $email = env('LOCAL_SUPER_ADMIN_EMAIL', 'admin@accesscontrol.local');
        $password = env('LOCAL_SUPER_ADMIN_PASSWORD');

        if (!$password) {
            // Skip if no password provided (idempotent, non-destructive)
            echo "Skipped LocalAdminSeeder: LOCAL_SUPER_ADMIN_PASSWORD not set.\n";
            return;
        }

        // updateOrCreate: safe idempotent upsert
        Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'System Administrator',
                'password' => Hash::make($password),
                'role' => 'super_admin',
            ]
        );

        // Do NOT echo or log the password
        echo "LocalAdminSeeder completed for {$email}.\n";
    }
}

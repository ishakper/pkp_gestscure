<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:rotate-password', function () {
    $email = env('RESET_ADMIN_EMAIL');
    $password = env('RESET_ADMIN_PASSWORD');

    if (!$email || !$password) {
        $this->error('RESET_ADMIN_EMAIL and RESET_ADMIN_PASSWORD must be set in environment.');
        return 1;
    }

    $admin = Admin::where('email', $email)->first();
    if (!$admin) {
        $this->error("Admin with email {$email} not found.");
        return 1;
    }

    $admin->update(['password' => Hash::make($password)]);

    // Clear password from environment
    unset($_ENV['RESET_ADMIN_PASSWORD']);
    putenv('RESET_ADMIN_PASSWORD=');

    $this->info('Password rotated successfully. No output printed.');
    return 0;
})->purpose('Rotate admin password from RESET_ADMIN_PASSWORD env var (password not printed)');

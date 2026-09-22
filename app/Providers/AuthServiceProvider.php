<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        \App\Models\AttendanceRequest::class => \App\Policies\AttendanceRequestPolicy::class,
        \App\Models\AttendanceCorrectionRequest::class => \App\Policies\AttendanceCorrectionRequestPolicy::class,
        \App\Models\OvertimeRequest::class => \App\Policies\OvertimeRequestPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Define the 'open' gate for Door: only super_admin can remotely unlock doors
        \Illuminate\Support\Facades\Gate::define('open', function ($user, $door) {
            // Only super_admin role is permitted to remote unlock doors
            return $user && $user->role === 'super_admin';
        });
    }
}

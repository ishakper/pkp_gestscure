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
        \App\Models\Door::class => \App\Policies\DoorPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        //
    }
}

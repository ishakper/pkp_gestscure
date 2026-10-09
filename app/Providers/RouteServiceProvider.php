<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if (app()->runningUnitTests() || app()->environment('testing')) {
                return Limit::none();
            }
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // 5 attempts per minute per Email + IP combination to prevent distributed brute forcing
        RateLimiter::for('login', function (Request $request) {
            if (app()->runningUnitTests() || app()->environment('testing')) {
                return Limit::none();
            }
            $email = strtolower(trim((string) $request->input('email')));
            $key = $email ? $email . '|' . $request->ip() : $request->ip();
            return Limit::perMinute(5)->by($key);
        });

        // 500 requests per minute for device ISAPI webhooks to accommodate retry bursts
        RateLimiter::for('isapi-webhook', function (Request $request) {
            if (app()->runningUnitTests() || app()->environment('testing')) {
                return Limit::none();
            }
            return Limit::perMinute(500)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}

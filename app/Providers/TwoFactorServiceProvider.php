<?php

namespace App\Providers;

use App\Console\Commands\ResetAdminTwoFactorCommand;
use App\Http\Middleware\EnforceTwoFactor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires two-factor authentication in as an add-on: its own routes file, its own
 * middleware appended to the `web` and `api` groups, its own rate limiter and
 * artisan command. No existing route, controller, kernel or model is changed.
 * The middleware does nothing while TWO_FACTOR_ENABLED is false.
 */
class TwoFactorServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('web', EnforceTwoFactor::class);
        $router->pushMiddlewareToGroup('api', EnforceTwoFactor::class);

        RateLimiter::for('two-factor', function (Request $request) {
            if (app()->environment('testing')) {
                return Limit::none();
            }

            return Limit::perMinute(10)->by('2fa|'.$request->session()->getId().'|'.$request->ip());
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/two_factor.php'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ResetAdminTwoFactorCommand::class]);
        }
    }
}

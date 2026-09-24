<?php

use App\Http\Controllers\Web\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (Web Application Dashboard Interface)
|--------------------------------------------------------------------------
*/

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'handleLogin'])->middleware('web', 'throttle:login');
Route::post('/logout', [AuthController::class, 'handleLogout'])->name('logout');

Route::middleware(['auth'])->group(function () {
    Route::get('/', function () {
        $apiToken = session('api_token');
        $admin = Auth::user();
        $doorsQuery = \App\Models\Door::withCount(['employees', 'doorAssignments']);
        if ($admin instanceof Admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $doorsQuery->where('location', $admin->assigned_building);
        }
        $doors = $doorsQuery->get();

        $portalAccess = app(\App\Services\PortalAccess::class);
        return view('dashboard', [
            'admin' => $admin,
            'portal' => $portalAccess->portalFor($admin),
            'permissions' => $portalAccess->permissionsFor($admin),
            'apiToken' => $apiToken,
            'doors' => $doors,
        ]);
    });

    // Web routes
    Route::get('/live-stream', [\App\Http\Controllers\LiveAccessStreamController::class, 'stream']);
});

// Observability Metrics Endpoints (Prometheus & Health Scrape)
Route::get('/metrics', [\App\Http\Controllers\Api\ObservabilityMetricsController::class, 'prometheus'])->name('metrics.prometheus');
Route::get('/metrics/json', [\App\Http\Controllers\Api\ObservabilityMetricsController::class, 'json'])->name('metrics.json');


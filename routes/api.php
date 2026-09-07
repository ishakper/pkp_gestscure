<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AdminAccessLogController;
use App\Http\Controllers\Api\V1\AdminDoorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DoorSyncController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\IsapiWebhookController;
use App\Http\Middleware\VerifyDeviceWebhook;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Auth Public Endpoint
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Protected API Endpoints
    Route::middleware('auth:sanctum')->group(function () {

        // Auth User Endpoints
        Route::prefix('auth')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/device-token', [AuthController::class, 'issueDeviceToken']);
        });

        // UserManagement Pillar
        Route::prefix('user-management')->group(function () {
            Route::get('/users', [EmployeeController::class, 'index']);
            Route::get('/employees', [EmployeeController::class, 'index']); // Spec alias
            Route::get('/doors-lookup', [AdminDoorController::class, 'lookup']);
            Route::post('/users', [EmployeeController::class, 'store']);
            Route::post('/employees', [EmployeeController::class, 'store']); // Spec alias
            Route::get('/users/{id}', [EmployeeController::class, 'show']);
            Route::get('/employees/{id}', [EmployeeController::class, 'show']);
            Route::put('/users/{id}', [EmployeeController::class, 'update']);
            Route::put('/employees/{id}', [EmployeeController::class, 'update']);
            Route::delete('/users/{id}', [EmployeeController::class, 'destroy']);
            Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);
            Route::post('/assign-doors', [DoorSyncController::class, 'assignDoors']);
            Route::post('/employees/{id}/door-access', [EmployeeController::class, 'assignDoorAccess']);
            Route::delete('/employees/{id}/door-access/{door_id}', [EmployeeController::class, 'revokeDoorAccess']);
        });

        // Admin Pillar
        Route::prefix('admin')->group(function () {
            Route::get('/doors', [AdminDoorController::class, 'index']);
            Route::patch('/doors/{door_id}/status', [AdminDoorController::class, 'overrideStatus']);
            Route::get('/access-logs', [AdminAccessLogController::class, 'index']);
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::post('/door-assignments/sync', [DoorSyncController::class, 'sync']);
        });
    });

    // ISAPI Physical Device Push Webhook (Protected via IP Whitelist & X-Device-Secret)
    Route::post('/isapi/event-notification', [IsapiWebhookController::class, 'handleEventNotification'])
        ->middleware([VerifyDeviceWebhook::class, 'throttle:isapi-webhook']);
});

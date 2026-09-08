<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AdminAccessLogController;
use App\Http\Controllers\Api\V1\AdminDoorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DoorSyncController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\IsapiWebhookController;
use App\Http\Controllers\Mock\HikvisionMockController;
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
            Route::post('/revoke-doors', [DoorSyncController::class, 'revokeDoors']);
            Route::post('/employees/{id}/door-access', [EmployeeController::class, 'assignDoorAccess']);
            Route::delete('/employees/{id}/door-access/{door_id}', [EmployeeController::class, 'revokeDoorAccess']);
        });

        // Admin Pillar
        Route::prefix('admin')->group(function () {
            Route::get('/doors', [AdminDoorController::class, 'index']);
            Route::post('/doors/check-all', [AdminDoorController::class, 'checkAllConnections']);
            Route::post('/doors/{door_id}/check-connection', [AdminDoorController::class, 'checkConnection']);
            Route::post('/doors/{door_id}/open', [AdminDoorController::class, 'openDoor'])->name('admin.doors.open');
            Route::post('/doors/{door_id}/unlock', [AdminDoorController::class, 'openDoor'])->name('admin.doors.unlock');
            Route::patch('/doors/{door_id}/status', [AdminDoorController::class, 'overrideStatus']);
            Route::get('/access-logs', [AdminAccessLogController::class, 'index']);
            Route::post('/access-logs/sync-hardware', [AdminAccessLogController::class, 'syncHardware']);
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::post('/door-assignments/sync', [DoorSyncController::class, 'sync']);
        });

        Route::post('/doors/{door_id}/unlock', [AdminDoorController::class, 'openDoor'])->name('api.doors.direct_unlock');
    });

    // ISAPI Physical Device Push Webhook (Protected via IP Whitelist & X-Device-Secret)
    Route::post('/isapi/event-notification', [IsapiWebhookController::class, 'handleEventNotification'])
        ->middleware([VerifyDeviceWebhook::class, 'throttle:isapi-webhook']);
});

/*
|--------------------------------------------------------------------------
| Mock ISAPI Routes (Hikvision DS-K1T804AMF Simulation & Integration)
|--------------------------------------------------------------------------
*/
Route::prefix('mock/isapi')->group(function () {
    Route::get('/System/status', [HikvisionMockController::class, 'deviceStatus']);
    Route::get('/System/deviceInfo', [HikvisionMockController::class, 'deviceStatus']);
    Route::put('/AccessControl/CardInfo/Record', [HikvisionMockController::class, 'syncCard']);
    Route::put('/AccessControl/RemoteControl/door/{doorNo}', [HikvisionMockController::class, 'remoteControl']);
    Route::post('/AccessControl/AcsEvent', [HikvisionMockController::class, 'fetchAccessLogs']);
});


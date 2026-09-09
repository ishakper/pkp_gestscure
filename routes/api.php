<?php

use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AdminAccessLogController;
use App\Http\Controllers\Api\V1\AdminDoorController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DoorSyncController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\IsapiWebhookController;
use App\Http\Controllers\Api\V1\OrganizationController;
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
            Route::get('/organization/lookup', [OrganizationController::class, 'lookup']);
            Route::get('/organization/{type}', [OrganizationController::class, 'index']);
            Route::post('/organization/{type}', [OrganizationController::class, 'store']);
            Route::put('/organization/{type}/{id}', [OrganizationController::class, 'update']);
            Route::post('/users', [EmployeeController::class, 'store']);
            Route::post('/employees', [EmployeeController::class, 'store']); // Spec alias
            Route::get('/users/{id}', [EmployeeController::class, 'show']);
            Route::get('/employees/{id}', [EmployeeController::class, 'show']);
            Route::get('/employees/{id}/360', [EmployeeController::class, 'profile360']);
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
            Route::get('/dashboard-metrics', [AdminDoorController::class, 'metrics']);
            Route::get('/access-logs', [AdminAccessLogController::class, 'index']);
            Route::post('/access-logs/sync-hardware', [AdminAccessLogController::class, 'syncHardware']);
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::post('/door-assignments/sync', [DoorSyncController::class, 'sync']);
        });

        // Recruitment & ATS Pillar
        Route::prefix('recruitment')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\RecruitmentController::class, 'metrics']);
            Route::get('/vacancies', [\App\Http\Controllers\Api\RecruitmentController::class, 'vacancies']);
            Route::post('/vacancies', [\App\Http\Controllers\Api\RecruitmentController::class, 'storeVacancy']);
            Route::get('/vacancies/{id}', [\App\Http\Controllers\Api\RecruitmentController::class, 'showVacancy']);
            Route::put('/vacancies/{id}', [\App\Http\Controllers\Api\RecruitmentController::class, 'updateVacancy']);
            Route::get('/candidates', [\App\Http\Controllers\Api\RecruitmentController::class, 'candidates']);
            Route::post('/candidates', [\App\Http\Controllers\Api\RecruitmentController::class, 'storeCandidate']);
            Route::get('/candidates/{id}', [\App\Http\Controllers\Api\RecruitmentController::class, 'showCandidate']);
            Route::get('/applications', [\App\Http\Controllers\Api\RecruitmentController::class, 'applications']);
            Route::post('/applications', [\App\Http\Controllers\Api\RecruitmentController::class, 'apply']);
            Route::post('/applications/{id}/stage', [\App\Http\Controllers\Api\RecruitmentController::class, 'transitionStage']);
            Route::post('/applications/{id}/interview', [\App\Http\Controllers\Api\RecruitmentController::class, 'scheduleInterview']);
            Route::put('/interviews/{id}/feedback', [\App\Http\Controllers\Api\RecruitmentController::class, 'submitFeedback']);
            Route::post('/applications/{id}/offer', [\App\Http\Controllers\Api\RecruitmentController::class, 'createOffer']);
            Route::post('/applications/{id}/convert-to-employee', [\App\Http\Controllers\Api\RecruitmentController::class, 'convertToEmployee']);
        });

        // Internship Management API (Sprint 4)
        Route::prefix('internships')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\InternshipController::class, 'metrics']);
            Route::get('/', [\App\Http\Controllers\Api\InternshipController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\InternshipController::class, 'store']);
            Route::post('/convert-candidate', [\App\Http\Controllers\Api\InternshipController::class, 'convertCandidate']);
            Route::get('/{id}', [\App\Http\Controllers\Api\InternshipController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\InternshipController::class, 'update']);
            Route::post('/{id}/assign-mentor', [\App\Http\Controllers\Api\InternshipController::class, 'assignMentor']);
            Route::post('/{id}/activate', [\App\Http\Controllers\Api\InternshipController::class, 'activate']);
            Route::post('/{id}/complete', [\App\Http\Controllers\Api\InternshipController::class, 'complete']);
            Route::get('/{id}/activities', [\App\Http\Controllers\Api\InternshipController::class, 'activities']);
            Route::post('/{id}/activities', [\App\Http\Controllers\Api\InternshipController::class, 'storeActivity']);
            Route::put('/activities/{activityId}/review', [\App\Http\Controllers\Api\InternshipController::class, 'reviewActivity']);
            Route::get('/{id}/reports', [\App\Http\Controllers\Api\InternshipController::class, 'reports']);
            Route::post('/{id}/reports', [\App\Http\Controllers\Api\InternshipController::class, 'storeReport']);
            Route::put('/reports/{reportId}/review', [\App\Http\Controllers\Api\InternshipController::class, 'reviewReport']);
            Route::get('/{id}/evaluations', [\App\Http\Controllers\Api\InternshipController::class, 'evaluations']);
            Route::post('/{id}/evaluations', [\App\Http\Controllers\Api\InternshipController::class, 'storeEvaluation']);
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

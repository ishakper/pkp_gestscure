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

        // Onboarding, Contracts & Document Management API (Sprint 5)
        Route::prefix('onboarding')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\OnboardingController::class, 'metrics']);
            Route::get('/cases', [\App\Http\Controllers\Api\OnboardingController::class, 'cases']);
            Route::post('/cases', [\App\Http\Controllers\Api\OnboardingController::class, 'storeCase']);
            Route::get('/cases/{id}', [\App\Http\Controllers\Api\OnboardingController::class, 'showCase']);
            Route::put('/tasks/{id}', [\App\Http\Controllers\Api\OnboardingController::class, 'updateTask']);
            Route::post('/cases/{id}/complete', [\App\Http\Controllers\Api\OnboardingController::class, 'completeCase']);

            Route::get('/contracts', [\App\Http\Controllers\Api\OnboardingController::class, 'contracts']);
            Route::post('/contracts', [\App\Http\Controllers\Api\OnboardingController::class, 'storeContract']);
            Route::put('/contracts/{id}', [\App\Http\Controllers\Api\OnboardingController::class, 'updateContract']);

            Route::get('/documents', [\App\Http\Controllers\Api\OnboardingController::class, 'documents']);
            Route::post('/documents', [\App\Http\Controllers\Api\OnboardingController::class, 'uploadDocument']);
            Route::put('/documents/{id}/verify', [\App\Http\Controllers\Api\OnboardingController::class, 'verifyDocument']);
            Route::get('/documents/{id}/download', [\App\Http\Controllers\Api\OnboardingController::class, 'downloadDocument']);
            Route::post('/documents/{id}/acknowledge', [\App\Http\Controllers\Api\OnboardingController::class, 'acknowledgeDocument']);
        });

        // Access Provisioning, Credential Center & E-Money (Sprint 6)
        Route::prefix('access')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'metrics']);

            // Profiles
            Route::get('/profiles', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'profiles']);
            Route::post('/profiles', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'storeProfile']);
            Route::get('/profiles/{id}', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'showProfile']);
            Route::put('/profiles/{id}', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'updateProfile']);

            // Requests
            Route::get('/requests', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'requests']);
            Route::post('/requests', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'storeRequest']);
            Route::get('/requests/{id}', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'showRequest']);
            Route::post('/requests/{id}/approve', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'approveRequest']);
            Route::post('/requests/{id}/reject', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'rejectRequest']);

            // Credentials
            Route::get('/credentials', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'credentials']);
            Route::post('/credentials', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'storeCredential']);
            Route::get('/credentials/{id}', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'showCredential']);
            Route::post('/credentials/{id}/revoke', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'revokeCredential']);

            // Device Sync Queue
            Route::get('/device-syncs', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'deviceSyncs']);
            Route::post('/device-syncs/{id}/retry', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'retryDeviceSync']);

            // E-Money Cards (Admin-Only)
            Route::get('/emoney', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'emoneyCards']);
            Route::post('/emoney', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'storeEmoneyCard']);
            Route::get('/emoney/{id}', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'showEmoneyCard']);
            Route::post('/emoney/{id}/status', [\App\Http\Controllers\Api\AccessProvisioningController::class, 'updateEmoneyStatus']);
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

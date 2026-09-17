<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ActivityLogController;
use App\Http\Controllers\Api\V1\AdminAccessLogController;
use App\Http\Controllers\Api\V1\AdminDoorController;
use App\Http\Controllers\Api\V1\AttendanceReportController;
use App\Http\Controllers\Api\V1\FacilityConfigurationController;
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
            Route::post('/change-password', [AuthController::class, 'changePassword']);
        });

        // System Accounts Endpoints
        Route::get('/accounts', [AccountController::class, 'index']);
        Route::patch('/accounts/{id}/password', [AccountController::class, 'resetPassword']);

        // UserManagement Pillar
        Route::prefix('user-management')->group(function () {
            Route::get('/accounts', [AccountController::class, 'index']);
            Route::patch('/accounts/{id}/password', [AccountController::class, 'resetPassword']);
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
            Route::post('/bulk-access', [DoorSyncController::class, 'bulkAccess']);
            Route::post('/employees/{id}/door-access', [EmployeeController::class, 'assignDoorAccess']);
            Route::delete('/employees/{id}/door-access/{door_id}', [EmployeeController::class, 'revokeDoorAccess']);
            Route::post('/employees/{id}/sync-biometric', [\App\Http\Controllers\Api\V1\BiometricProvisioningController::class, 'syncEmployeeBiometric']);
            Route::get('/employees/{id}/door-sync-status', [\App\Http\Controllers\Api\V1\BiometricProvisioningController::class, 'getEmployeeSyncStatus']);
            Route::post('/employees/{id}/enroll-card', [EmployeeController::class, 'enrollCard']);
            Route::post('/employees/{id}/block-lost-card', [EmployeeController::class, 'blockLostCard']);
        });

        // Admin Pillar
        Route::prefix('admin')->group(function () {
            Route::get('/doors', [AdminDoorController::class, 'index']);
            Route::post('/doors', [FacilityConfigurationController::class, 'storeDoor']);
            Route::put('/doors/{door_id}', [FacilityConfigurationController::class, 'updateDoor']);
            Route::get('/buildings', [FacilityConfigurationController::class, 'buildings']);
            Route::post('/buildings', [FacilityConfigurationController::class, 'storeBuilding']);
            Route::post('/zones', [FacilityConfigurationController::class, 'storeZone']);
            Route::post('/doors/check-all', [AdminDoorController::class, 'checkAllConnections']);
            Route::post('/doors/{door_id}/check-connection', [AdminDoorController::class, 'checkConnection']);
            Route::post('/doors/{door_id}/open', [AdminDoorController::class, 'openDoor'])->name('admin.doors.open');
            Route::post('/doors/{door_id}/unlock', [AdminDoorController::class, 'openDoor'])->name('admin.doors.unlock');
            Route::patch('/doors/{door_id}/status', [AdminDoorController::class, 'overrideStatus']);
            Route::get('/dashboard-metrics', [AdminDoorController::class, 'metrics']);
            Route::get('/system-health', [AdminDoorController::class, 'systemHealth']);
            Route::get('/access-logs', [AdminAccessLogController::class, 'index']);
            Route::post('/access-logs/sync-hardware', [AdminAccessLogController::class, 'syncHardware']);
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::post('/door-assignments/sync', [DoorSyncController::class, 'sync']);
            Route::post('/doors/{door_id}/sync-employee/{employee_id}', [\App\Http\Controllers\Api\V1\BiometricProvisioningController::class, 'syncDoorEmployee']);

            // Sprint 13: versioned job descriptions and verified skill matrix
            Route::get('/job-descriptions', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'jobDescriptions']);
            Route::post('/job-descriptions', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'storeJobDescription']);
            Route::post('/job-descriptions/{jobDescription}/publish', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'publishJobDescription']);
            Route::post('/job-descriptions/{jobDescription}/archive', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'archiveJobDescription']);
            Route::get('/skills', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'skills']);
            Route::post('/skills', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'storeSkill']);
        });

        Route::post('/employees/{employee}/skills', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'declareSkill']);
        Route::post('/employees/{employee}/skills/{skill}/verify', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'verifySkill']);
        Route::get('/employees/{employee}/skill-gap', [\App\Http\Controllers\Api\V1\JobDescriptionSkillController::class, 'skillGap']);

        // Sprint 14: task management and worklogs
        Route::get('/tasks', [\App\Http\Controllers\Api\V1\TaskController::class, 'index']);
        Route::post('/tasks', [\App\Http\Controllers\Api\V1\TaskController::class, 'store']);
        Route::get('/tasks/metrics', [\App\Http\Controllers\Api\V1\TaskController::class, 'metrics']);
        Route::get('/tasks/{task}', [\App\Http\Controllers\Api\V1\TaskController::class, 'show']);
        Route::put('/tasks/{task}', [\App\Http\Controllers\Api\V1\TaskController::class, 'update']);
        Route::get('/tasks/{task}/worklogs', [\App\Http\Controllers\Api\V1\TaskController::class, 'worklogs']);
        Route::post('/tasks/{task}/worklogs', [\App\Http\Controllers\Api\V1\TaskController::class, 'storeWorklog']);

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

        // Sprint 7: Enterprise Asset Management
        Route::prefix('assets')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\AssetController::class, 'metrics']);
            Route::get('/categories', [\App\Http\Controllers\Api\AssetController::class, 'categories']);
            Route::get('/', [\App\Http\Controllers\Api\AssetController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\AssetController::class, 'store']);
            Route::get('/assignments', [\App\Http\Controllers\Api\AssetController::class, 'assignments']);
            Route::get('/maintenances', [\App\Http\Controllers\Api\AssetController::class, 'maintenances']);
            Route::get('/incidents', [\App\Http\Controllers\Api\AssetController::class, 'incidents']);
            Route::get('/{id}', [\App\Http\Controllers\Api\AssetController::class, 'show']);
            Route::put('/{id}', [\App\Http\Controllers\Api\AssetController::class, 'update']);
            Route::post('/{id}/assign', [\App\Http\Controllers\Api\AssetController::class, 'assign']);
            Route::post('/assignments/{id}/return', [\App\Http\Controllers\Api\AssetController::class, 'returnAsset']);
            Route::post('/{id}/maintenance', [\App\Http\Controllers\Api\AssetController::class, 'openMaintenance']);
            Route::post('/maintenances/{id}/complete', [\App\Http\Controllers\Api\AssetController::class, 'completeMaintenance']);
            Route::post('/{id}/incident', [\App\Http\Controllers\Api\AssetController::class, 'reportIncident']);
            Route::post('/incidents/{id}/resolve', [\App\Http\Controllers\Api\AssetController::class, 'resolveIncident']);
            Route::post('/{id}/dispose', [\App\Http\Controllers\Api\AssetController::class, 'dispose']);
        });

        // Sprint 8: Work Calendar + Attendance Core
        Route::prefix('attendance')->group(function () {
            Route::get('/metrics', [\App\Http\Controllers\Api\AttendanceController::class, 'metrics']);
            Route::get('/reports/monthly', [AttendanceReportController::class, 'monthly']);
            Route::get('/reports/monthly/export', [AttendanceReportController::class, 'export']);

            // Work Calendars
            Route::get('/calendars', [\App\Http\Controllers\Api\AttendanceController::class, 'calendars']);
            Route::post('/calendars', [\App\Http\Controllers\Api\AttendanceController::class, 'storeCalendar']);
            Route::get('/calendars/{id}', [\App\Http\Controllers\Api\AttendanceController::class, 'showCalendar']);
            Route::put('/calendars/{id}', [\App\Http\Controllers\Api\AttendanceController::class, 'updateCalendar']);
            Route::post('/calendars/{id}/days', [\App\Http\Controllers\Api\AttendanceController::class, 'upsertCalendarDays']);

            // Public Holidays
            Route::get('/holidays', [\App\Http\Controllers\Api\AttendanceController::class, 'holidays']);
            Route::post('/holidays', [\App\Http\Controllers\Api\AttendanceController::class, 'storeHoliday']);
            Route::delete('/holidays/{id}', [\App\Http\Controllers\Api\AttendanceController::class, 'destroyHoliday']);

            // Calendar Assignment
            Route::post('/employees/{id}/assign-calendar', [\App\Http\Controllers\Api\AttendanceController::class, 'assignCalendar']);

            // Attendance Records
            Route::get('/records', [\App\Http\Controllers\Api\AttendanceController::class, 'records']);
            Route::post('/records', [\App\Http\Controllers\Api\AttendanceController::class, 'record']);
            Route::get('/records/{id}', [\App\Http\Controllers\Api\AttendanceController::class, 'showRecord']);
            Route::post('/records/{id}/verify', [\App\Http\Controllers\Api\AttendanceController::class, 'verifyRecord']);

            // Employee Attendance Summary
            Route::get('/employees/{id}/summary', [\App\Http\Controllers\Api\AttendanceController::class, 'employeeSummary']);
        });

        // Sprint 10: Field Attendance + GPS + Photo + Geofence
        Route::prefix('field-attendance')->group(function () {
            // Locations
            Route::get('/locations', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'listLocations']);
            Route::post('/locations', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'storeLocation']);
            Route::get('/locations/{id}', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'showLocation']);
            Route::put('/locations/{id}', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'updateLocation']);
            Route::delete('/locations/{id}', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'destroyLocation']);

            // Assignments
            Route::get('/assignments', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'listAssignments']);
            Route::post('/assignments', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'storeAssignment']);
            Route::get('/my-assignment', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'myAssignment']);

            // Check-In / Check-Out
            Route::post('/check-in', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'checkIn']);
            Route::post('/check-out', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'checkOut']);

            // Status & Records
            Route::get('/status-today', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'statusToday']);
            Route::get('/records', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'listRecords']);
            Route::get('/records/{id}', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'showRecord']);
            Route::post('/records/{id}/override', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'overrideRecord']);
            Route::get('/records/{id}/photo', [\App\Http\Controllers\Api\V1\FieldAttendanceController::class, 'downloadPhoto']);
        });

        // Sprint 11: WFH + Leave + Permission + Sick (Attendance Requests)
        Route::prefix('attendance-requests')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'store']);
            Route::get('/metrics', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'metrics']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'show']);
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'approve']);
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'reject']);
            Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'cancel']);
            Route::get('/{id}/attachment', [\App\Http\Controllers\Api\V1\AttendanceRequestController::class, 'attachment']);
        });

        // Sprint 12: Attendance Correction
        Route::prefix('attendance-corrections')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'store']);
            Route::get('/metrics', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'metrics']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'show']);
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'approve']);
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'reject']);
            Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'cancel']);
            Route::get('/{id}/attachment', [\App\Http\Controllers\Api\V1\AttendanceCorrectionController::class, 'attachment']);
        });

        // Sprint 12: Overtime Requests
        Route::prefix('overtime-requests')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'store']);
            Route::get('/metrics', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'metrics']);
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'show']);
            Route::post('/{id}/approve', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'approve']);
            Route::post('/{id}/reject', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'reject']);
            Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'cancel']);
            Route::get('/{id}/attachment', [\App\Http\Controllers\Api\V1\OvertimeController::class, 'attachment']);
        });

        Route::post('/doors/{door_id}/unlock', [AdminDoorController::class, 'openDoor'])->name('api.doors.direct_unlock');
        Route::post('/doors/simulate-event', [IsapiWebhookController::class, 'simulateEvent']);
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
if (app()->environment(['local', 'testing'])) {
    Route::prefix('mock/isapi')->group(function () {
        Route::get('/System/status', [HikvisionMockController::class, 'deviceStatus']);
        Route::get('/System/deviceInfo', [HikvisionMockController::class, 'deviceStatus']);
        Route::put('/AccessControl/CardInfo/Record', [HikvisionMockController::class, 'syncCard']);
        Route::put('/AccessControl/RemoteControl/door/{doorNo}', [HikvisionMockController::class, 'remoteControl']);
        Route::post('/AccessControl/AcsEvent', [HikvisionMockController::class, 'fetchAccessLogs']);
        Route::put('/AccessControl/UserInfo/SetUp', [HikvisionMockController::class, 'setupUserInfo']);
        Route::put('/AccessControl/UserRightPlan/SetUp', [HikvisionMockController::class, 'setupUserRightPlan']);
    });
}

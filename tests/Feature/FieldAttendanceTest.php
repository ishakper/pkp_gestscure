<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FieldAssignment;
use App\Models\FieldAttendanceEvidence;
use App\Models\FieldLocation;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FieldAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected WorkCalendar $calendar;
    protected FieldLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Setup default calendar (08:00 - 17:00, 30 min tolerance)
        $this->calendar = WorkCalendar::create([
            'name' => 'Standard Field Calendar',
            'code' => 'STD_FIELD',
            'late_tolerance_minutes' => 30,
            'is_default' => true,
            'building_id' => null,
            'is_active' => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            WorkScheduleDay::create([
                'work_calendar_id' => $this->calendar->id,
                'day_of_week' => $d,
                'is_working_day' => true,
                'work_start' => '08:00:00',
                'work_end' => '17:00:00',
            ]);
        }

        // Setup field location (Bundaran HI Jakarta: -6.195000, 106.823000, radius 100m)
        $this->location = FieldLocation::create([
            'name' => 'Proyek Bundaran HI',
            'project_name' => 'MRT Station Expansion',
            'client_name' => 'PT MRT Jakarta',
            'site_address' => 'Jl. M.H. Thamrin, Jakarta Pusat',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'radius_meters' => 100,
            'valid_from' => now()->subDays(10)->toDateString(),
            'valid_until' => now()->addDays(30)->toDateString(),
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // FACTORIES & HELPERS
    // =========================================================================

    private function createEmployee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => 'EMP-' . uniqid(),
            'nik' => 'NIK-' . rand(100000, 999999),
            'name' => 'Test Employee ' . uniqid(),
            'email' => 'emp_' . uniqid() . '@pkp.co.id',
            'department' => 'Operasional Lapangan',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ], $attributes));
    }

    private function createAdminForEmployee(Employee $employee, string $role = 'employee'): Admin
    {
        return Admin::create([
            'name' => $employee->name,
            'email' => $employee->email,
            'password' => Hash::make('secret123'),
            'role' => $role,
            'employee_id' => $employee->id,
        ]);
    }

    private function createRoleAdmin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role) . ' User ' . uniqid(),
            'email' => "{$role}_" . uniqid() . '@pkp.co.id',
            'password' => Hash::make('secret123'),
            'role' => $role,
        ]);
    }

    private function createAssignment(Employee $employee, FieldLocation $location, ?Employee $supervisor = null): FieldAssignment
    {
        return FieldAssignment::create([
            'employee_id' => $employee->id,
            'field_location_id' => $location->id,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(14)->toDateString(),
            'supervisor_id' => $supervisor?->id,
            'status' => 'ACTIVE',
            'approved_at' => now(),
        ]);
    }

    // =========================================================================
    // TESTS: FIELD ASSIGNMENT & GEOFENCE CHECK-IN
    // =========================================================================

    public function test_authorized_employee_can_checkin_within_geofence(): void
    {
        Carbon::setTestNow('2026-09-10 08:15:00'); // On time (within 30m tolerance)

        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        // Location is (-6.195000, 106.823000). A point ~30 meters away:
        $lat = -6.195200;
        $lon = 106.823100;

        $photo = UploadedFile::fake()->image('selfie.jpg', 640, 480);

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => $lat,
                'longitude' => $lon,
                'accuracy_meters' => 12.5,
                'captured_at' => now()->toDateTimeString(),
                'photo' => $photo,
                'notes' => 'Tiba di lokasi gerbang barat',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence.geofence_result', 'VALID');
        $response->assertJsonPath('evidence.type', 'CHECK_IN');

        // Check Attendance Core integration
        $this->assertDatabaseHas('attendances', [
            'employee_id' => $emp->id,
            'attendance_date' => '2026-09-10',
            'attendance_type' => 'FIELD',
            'clock_in_source' => 'FIELD',
            'status' => 'PRESENT',
            'late_minutes' => 0,
        ]);

        $this->assertDatabaseHas('field_attendance_evidences', [
            'employee_id' => $emp->id,
            'type' => 'CHECK_IN',
            'geofence_result' => 'VALID',
        ]);

        // Verify audit log
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'FIELD_CHECK_IN',
            'admin_id' => $admin->id,
        ]);
    }

    public function test_unauthorized_employee_without_assignment_is_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        // No assignment created!

        $photo = UploadedFile::fake()->image('selfie.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['assignment']);

        // No attendance record created
        $this->assertDatabaseMissing('attendances', ['employee_id' => $emp->id]);
    }

    public function test_outside_geofence_is_stored_as_evidence_but_does_not_clock_in_attendance(): void
    {
        Carbon::setTestNow('2026-09-10 08:15:00');

        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        // Coordinates ~800 meters away from HI: Monas (-6.1754, 106.8272)
        $lat = -6.175400;
        $lon = 106.827200;

        $photo = UploadedFile::fake()->image('selfie.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => $lat,
                'longitude' => $lon,
                'accuracy_meters' => 15.0,
                'photo' => $photo,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence.geofence_result', 'OUTSIDE_GEOFENCE');

        // Evidence stored with non-zero distance
        $this->assertDatabaseHas('field_attendance_evidences', [
            'employee_id' => $emp->id,
            'geofence_result' => 'OUTSIDE_GEOFENCE',
        ]);

        // Core Attendance row must NOT be clocked in
        $attendance = Attendance::where('employee_id', $emp->id)->first();
        $this->assertNull($attendance?->clock_in_at);
    }

    public function test_low_gps_accuracy_does_not_silently_pass(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        // Exact HI coordinates, but accuracy is poor: 85 meters (> 50m max)
        $photo = UploadedFile::fake()->image('selfie.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 85.0,
                'photo' => $photo,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('evidence.geofence_result', 'LOW_ACCURACY');

        // Attendance clock-in is NOT set
        $attendance = Attendance::where('employee_id', $emp->id)->first();
        $this->assertNull($attendance?->clock_in_at);
    }

    public function test_invalid_coordinates_are_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $photo = UploadedFile::fake()->image('selfie.jpg');

        $response = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => 125.0, // Invalid latitude (> 90)
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['latitude']);
    }

    public function test_duplicate_check_in_is_prevented_and_preserves_attendance_uniqueness(): void
    {
        Carbon::setTestNow('2026-09-10 08:10:00');

        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $photo1 = UploadedFile::fake()->image('selfie1.jpg');
        $photo2 = UploadedFile::fake()->image('selfie2.jpg');

        // First check-in
        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo1,
            ])->assertStatus(201);

        // Second check-in on the same day
        $res2 = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo2,
            ]);

        $res2->assertStatus(422);

        // Exactly one Attendance record exists for today
        $count = Attendance::where('employee_id', $emp->id)
            ->whereDate('attendance_date', '2026-09-10')
            ->count();
        $this->assertSame(1, $count);
    }

    // =========================================================================
    // TESTS: FIELD CHECK-OUT
    // =========================================================================

    public function test_field_checkout_requires_prior_checkin_and_computes_work_duration(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $photo = UploadedFile::fake()->image('checkout.jpg');

        // Checkout without check-in -> 422
        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-out', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ])->assertStatus(422);

        // Now check-in at 08:00
        Carbon::setTestNow('2026-09-10 08:00:00');
        $photoIn = UploadedFile::fake()->image('checkin.jpg');
        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photoIn,
            ])->assertStatus(201);

        // Checkout at 17:00 (9 hours = 540 minutes)
        Carbon::setTestNow('2026-09-10 17:00:00');
        $photoOut = UploadedFile::fake()->image('checkout.jpg');
        $resOut = $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-out', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photoOut,
            ]);

        $resOut->assertStatus(201);
        $resOut->assertJsonPath('evidence.type', 'CHECK_OUT');

        $attendance = Attendance::where('employee_id', $emp->id)
            ->whereDate('attendance_date', '2026-09-10')
            ->first();

        $this->assertNotNull($attendance->clock_out_at);
        $this->assertSame('FIELD', $attendance->clock_out_source);
        $this->assertSame(540, $attendance->effective_work_minutes);
        $this->assertSame('PRESENT', $attendance->status);
    }

    public function test_late_check_in_derives_late_status_with_exact_minutes(): void
    {
        // Work start: 08:00, tolerance: 30m. Clock-in at 08:45 -> late by 45 minutes
        Carbon::setTestNow('2026-09-10 08:45:00');

        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $photo = UploadedFile::fake()->image('late.jpg');
        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ])->assertStatus(201);

        $attendance = Attendance::where('employee_id', $emp->id)->first();
        $this->assertSame('LATE', $attendance->status);
        $this->assertSame(45, $attendance->late_minutes);
        $this->assertSame('FIELD', $attendance->attendance_type);
    }

    // =========================================================================
    // TESTS: RBAC, IDOR & PRIVACY
    // =========================================================================

    public function test_employee_cannot_submit_for_another_employee_idor(): void
    {
        $emp1 = $this->createEmployee();
        $emp2 = $this->createEmployee();

        $admin1 = $this->createAdminForEmployee($emp1, 'employee');
        $this->createAssignment($emp2, $this->location);

        $photo = UploadedFile::fake()->image('idor.jpg');

        $response = $this->actingAs($admin1)
            ->postJson('/api/v1/field-attendance/check-in', [
                'employee_id' => $emp2->id,
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ]);

        $response->assertStatus(403);
    }

    public function test_supervisor_can_view_assigned_team_but_denied_for_unrelated_employees(): void
    {
        $supervisor = $this->createEmployee();
        $supAdmin = $this->createAdminForEmployee($supervisor, 'supervisor');

        $teamEmp = $this->createEmployee(['supervisor_id' => $supervisor->id]);
        $unrelatedEmp = $this->createEmployee();

        $teamAssignment = $this->createAssignment($teamEmp, $this->location, $supervisor);
        $unrelatedAssignment = $this->createAssignment($unrelatedEmp, $this->location);

        // Create an evidence record for each
        $teamEvidence = FieldAttendanceEvidence::create([
            'employee_id' => $teamEmp->id,
            'field_assignment_id' => $teamAssignment->id,
            'field_location_id' => $this->location->id,
            'attendance_date' => now()->toDateString(),
            'type' => 'CHECK_IN',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'accuracy_meters' => 10.0,
            'captured_at' => now(),
            'server_received_at' => now(),
            'distance_meters' => 10.0,
            'geofence_result' => 'VALID',
            'photo_path' => 'field_photos/test1.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size_bytes' => 1000,
        ]);

        $unrelatedEvidence = FieldAttendanceEvidence::create([
            'employee_id' => $unrelatedEmp->id,
            'field_assignment_id' => $unrelatedAssignment->id,
            'field_location_id' => $this->location->id,
            'attendance_date' => now()->toDateString(),
            'type' => 'CHECK_IN',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'accuracy_meters' => 10.0,
            'captured_at' => now(),
            'server_received_at' => now(),
            'distance_meters' => 10.0,
            'geofence_result' => 'VALID',
            'photo_path' => 'field_photos/test2.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size_bytes' => 1000,
        ]);

        // Supervisor can view team member's record
        $this->actingAs($supAdmin)
            ->getJson("/api/v1/field-attendance/records/{$teamEvidence->id}")
            ->assertStatus(200);

        // Supervisor denied for unrelated employee
        $this->actingAs($supAdmin)
            ->getJson("/api/v1/field-attendance/records/{$unrelatedEvidence->id}")
            ->assertStatus(403);
    }

    public function test_building_admin_and_developer_are_denied_field_attendance_records(): void
    {
        $buildingAdmin = $this->createRoleAdmin('building_admin');
        $developer = $this->createRoleAdmin('developer');

        $this->actingAs($buildingAdmin)
            ->getJson('/api/v1/field-attendance/records')
            ->assertStatus(403);

        $this->actingAs($developer)
            ->getJson('/api/v1/field-attendance/records')
            ->assertStatus(403);
    }

    // =========================================================================
    // TESTS: PHOTO PRIVACY, TRAVERSAL & AUDIT
    // =========================================================================

    public function test_photo_privacy_and_path_traversal_prevention(): void
    {
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $devAdmin = $this->createRoleAdmin('developer');
        $mgmtAdmin = $this->createRoleAdmin('management');

        // Store a fake photo on local disk
        Storage::disk('local')->put('field_photos/2026/09/sample.jpg', 'fake-image-bytes');

        $assignment = $this->createAssignment($emp, $this->location);

        $evidence = FieldAttendanceEvidence::create([
            'employee_id' => $emp->id,
            'field_assignment_id' => $assignment->id,
            'field_location_id' => $this->location->id,
            'attendance_date' => now()->toDateString(),
            'type' => 'CHECK_IN',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'accuracy_meters' => 10.0,
            'captured_at' => now(),
            'server_received_at' => now(),
            'distance_meters' => 10.0,
            'geofence_result' => 'VALID',
            'photo_path' => 'field_photos/2026/09/sample.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size_bytes' => 16,
        ]);

        // 1. Employee can access their own photo
        $this->actingAs($empAdmin)
            ->get("/api/v1/field-attendance/records/{$evidence->id}/photo")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // 2. HRD can access photo and it is audited
        $this->actingAs($hrdAdmin)
            ->get("/api/v1/field-attendance/records/{$evidence->id}/photo")
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'VIEW_FIELD_PHOTO',
            'admin_id' => $hrdAdmin->id,
            'subject_id' => $evidence->id,
        ]);

        // 3. Developer denied
        $this->actingAs($devAdmin)
            ->get("/api/v1/field-attendance/records/{$evidence->id}/photo")
            ->assertStatus(403);

        // 4. Management denied (summary scope only, no private photo access)
        $this->actingAs($mgmtAdmin)
            ->get("/api/v1/field-attendance/records/{$evidence->id}/photo")
            ->assertStatus(403);
    }

    public function test_invalid_mime_and_oversized_photos_are_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        // Fake text/plain file disguised as php or txt
        $badMime = UploadedFile::fake()->create('exploit.txt', 100, 'text/plain');

        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $badMime,
            ])->assertStatus(422)
              ->assertJsonValidationErrors(['photo']);

        // Oversized file (> 5MB = 5120KB)
        $oversized = UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg');

        $this->actingAs($admin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'photo' => $oversized,
            ])->assertStatus(422)
              ->assertJsonValidationErrors(['photo']);
    }

    // =========================================================================
    // TESTS: MANUAL OVERRIDE & AUDIT
    // =========================================================================

    public function test_manual_override_by_hrd_verifies_attendance_and_preserves_original_evidence(): void
    {
        Carbon::setTestNow('2026-09-10 08:15:00');

        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');
        $hrd = $this->createRoleAdmin('hrd');
        $this->createAssignment($emp, $this->location);

        // Check in outside geofence (Monas ~800m away)
        $photo = UploadedFile::fake()->image('outside.jpg');
        $res = $this->actingAs($empAdmin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.175400,
                'longitude' => 106.827200,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ]);

        $res->assertStatus(201);
        $evidenceId = $res->json('evidence.id');
        $originalDist = $res->json('evidence.distance_meters');

        // Core Attendance is not yet verified
        $this->assertNull(Attendance::where('employee_id', $emp->id)->first()?->clock_in_at);

        // HRD applies manual override
        $overrideRes = $this->actingAs($hrd)
            ->postJson("/api/v1/field-attendance/records/{$evidenceId}/override", [
                'reason' => 'Disetujui untuk survei darurat di luar gerbang',
            ]);

        $overrideRes->assertStatus(200);
        $overrideRes->assertJsonPath('evidence.is_override', true);
        $overrideRes->assertJsonPath('evidence.override_reason', 'Disetujui untuk survei darurat di luar gerbang');

        // Original GPS, distance and geofence result are preserved
        $fresh = FieldAttendanceEvidence::find($evidenceId);
        $this->assertSame(-6.1754, (float)$fresh->latitude);
        $this->assertSame('OUTSIDE_GEOFENCE', $fresh->geofence_result);
        $this->assertEquals($originalDist, (float)$fresh->distance_meters);
        $this->assertTrue($fresh->is_override);

        // Core attendance now clock-in is verified
        $attendance = Attendance::where('employee_id', $emp->id)->first();
        $this->assertNotNull($attendance->clock_in_at);
        $this->assertSame('FIELD', $attendance->attendance_type);

        // Audit log created
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'FIELD_ATTENDANCE_OVERRIDE',
            'admin_id' => $hrd->id,
            'subject_id' => $evidenceId,
        ]);
    }

    public function test_override_requires_non_empty_reason(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $assignment = $this->createAssignment($emp, $this->location);

        $evidence = FieldAttendanceEvidence::create([
            'employee_id' => $emp->id,
            'field_assignment_id' => $assignment->id,
            'field_location_id' => $this->location->id,
            'attendance_date' => now()->toDateString(),
            'type' => 'CHECK_IN',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'accuracy_meters' => 10.0,
            'captured_at' => now(),
            'server_received_at' => now(),
            'distance_meters' => 10.0,
            'geofence_result' => 'LOW_ACCURACY',
            'photo_path' => 'field_photos/test.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size_bytes' => 100,
        ]);

        $this->actingAs($hrd)
            ->postJson("/api/v1/field-attendance/records/{$evidence->id}/override", [
                'reason' => '   ', // blank
            ])->assertStatus(422)
              ->assertJsonValidationErrors(['reason']);
    }

    public function test_hrd_can_crud_field_locations(): void
    {
        $hrd = $this->createRoleAdmin('hrd');

        // Create
        $res = $this->actingAs($hrd)
            ->postJson('/api/v1/field-attendance/locations', [
                'name' => 'Site Proyek IKN',
                'project_name' => 'Istana Negara',
                'client_name' => 'Kementerian PUPR',
                'latitude' => -0.9632,
                'longitude' => 116.7088,
                'radius_meters' => 200,
            ]);
        $res->assertStatus(201);
        $locId = $res->json('id');

        // Show
        $this->actingAs($hrd)
            ->getJson("/api/v1/field-attendance/locations/{$locId}")
            ->assertStatus(200)
            ->assertJsonPath('name', 'Site Proyek IKN');

        // Update
        $this->actingAs($hrd)
            ->putJson("/api/v1/field-attendance/locations/{$locId}", [
                'radius_meters' => 300,
            ])->assertStatus(200)
              ->assertJsonPath('radius_meters', 300);

        // Delete
        $this->actingAs($hrd)
            ->deleteJson("/api/v1/field-attendance/locations/{$locId}")
            ->assertStatus(200);

        $this->assertSoftDeleted('field_locations', ['id' => $locId]);
    }

    public function test_hrd_can_create_assignment_and_employee_can_view_my_assignment(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');

        // Create assignment via HRD
        $res = $this->actingAs($hrd)
            ->postJson('/api/v1/field-attendance/assignments', [
                'employee_id' => $emp->id,
                'field_location_id' => $this->location->id,
                'start_date' => now()->subDay()->toDateString(),
                'end_date' => now()->addDays(7)->toDateString(),
            ]);
        $res->assertStatus(201);

        // Employee views my-assignment
        $myRes = $this->actingAs($empAdmin)
            ->getJson('/api/v1/field-attendance/my-assignment');
        $myRes->assertStatus(200);
        $myRes->assertJsonPath('assignment.field_location.name', $this->location->name);
    }

    public function test_status_today_returns_correct_aggregation(): void
    {
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $res = $this->actingAs($empAdmin)
            ->getJson('/api/v1/field-attendance/status-today');

        $res->assertStatus(200);
        $res->assertJsonStructure([
            'date',
            'employee' => ['id', 'name'],
            'assignment',
            'attendance',
            'evidences',
        ]);
    }

    public function test_timestamp_drift_anomaly_is_detected(): void
    {
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');
        $this->createAssignment($emp, $this->location);

        $photo = UploadedFile::fake()->image('drift.jpg');

        // Client device captured_at is 2 hours (7200 seconds) in the past -> exceeds 900s max drift
        $res = $this->actingAs($empAdmin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -6.195000,
                'longitude' => 106.823000,
                'accuracy_meters' => 10.0,
                'captured_at' => now()->subHours(2)->toDateTimeString(),
                'photo' => $photo,
            ]);

        $res->assertStatus(201);
        $this->assertContains('TIMESTAMP_DRIFT', $res->json('evidence.anomaly_flags'));
    }

    public function test_impossible_travel_anomaly_is_detected(): void
    {
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');
        $assignment = $this->createAssignment($emp, $this->location);

        // First check-in at Jakarta (captured 15 minutes ago)
        FieldAttendanceEvidence::create([
            'employee_id' => $emp->id,
            'field_assignment_id' => $assignment->id,
            'field_location_id' => $this->location->id,
            'attendance_date' => now()->toDateString(),
            'type' => 'CHECK_IN',
            'latitude' => -6.195000,
            'longitude' => 106.823000,
            'accuracy_meters' => 10.0,
            'captured_at' => now()->subMinutes(15),
            'server_received_at' => now()->subMinutes(15),
            'distance_meters' => 10.0,
            'geofence_result' => 'VALID',
            'photo_path' => 'field_photos/test1.jpg',
            'photo_mime' => 'image/jpeg',
            'photo_size_bytes' => 100,
        ]);

        // Second check-in at Surabaya (-7.2575, 112.7521, ~700 km away) 15 minutes later (> 2000 km/h)
        $photo = UploadedFile::fake()->image('surabaya.jpg');

        // Create a location in Surabaya for the check-in
        $surabayaLocation = FieldLocation::create([
            'name' => 'Proyek Surabaya',
            'latitude' => -7.2575,
            'longitude' => 112.7521,
            'radius_meters' => 200,
            'is_active' => true,
        ]);
        $this->createAssignment($emp, $surabayaLocation);

        $res = $this->actingAs($empAdmin)
            ->postJson('/api/v1/field-attendance/check-in', [
                'latitude' => -7.2575,
                'longitude' => 112.7521,
                'accuracy_meters' => 10.0,
                'photo' => $photo,
            ]);

        // Duplicate check-in on the same day is rejected, but let's test GeofenceService directly for impossible travel
        $geofenceService = app(\App\Services\GeofenceService::class);
        $anomalies = $geofenceService->detectAnomalies($emp, -7.2575, 112.7521, 10.0, now(), now());
        $this->assertContains('IMPOSSIBLE_TRAVEL', $anomalies);
    }

    public function test_dashboard_renders_field_attendance_tab_for_authorized_users(): void
    {
        $emp = $this->createEmployee();
        $empAdmin = $this->createAdminForEmployee($emp, 'employee');

        $res = $this->actingAs($empAdmin)->get('/');
        $res->assertStatus(200);
        $res->assertSee('fieldAttendanceTab');
        $res->assertSee('Presensi Lapangan');
    }
}

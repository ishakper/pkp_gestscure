<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\AttendanceEvidence;
use App\Models\Door;
use App\Models\Employee;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected WorkCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->calendar = WorkCalendar::create([
            'name'                   => 'Standard Corporate Calendar',
            'code'                   => 'STD_CORP',
            'late_tolerance_minutes' => 15,
            'is_default'             => true,
            'building_id'            => null,
            'is_active'              => true,
        ]);

        for ($d = 0; $d <= 6; $d++) {
            $isWorking = ($d >= 1 && $d <= 5);
            WorkScheduleDay::create([
                'work_calendar_id' => $this->calendar->id,
                'day_of_week'      => $d,
                'is_working_day'   => $isWorking,
                'work_start'       => $isWorking ? '08:00:00' : null,
                'work_end'         => $isWorking ? '17:00:00' : null,
            ]);
        }
    }

    private function createEmployee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id'       => 'EMP-' . uniqid(),
            'nik'               => 'NIK-' . rand(100000, 999999),
            'name'              => 'Employee ' . uniqid(),
            'email'             => 'emp_' . uniqid() . '@pkp.co.id',
            'department'        => 'Engineering',
            'role'              => 'Developer',
            'employment_type'   => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ], $attributes));
    }

    private function createAdminForEmployee(Employee $employee, string $role = 'employee'): Admin
    {
        return Admin::create([
            'name'        => $employee->name,
            'email'       => $employee->email,
            'password'    => Hash::make('secret123'),
            'role'        => $role,
            'employee_id' => $employee->id,
        ]);
    }

    private function createRoleAdmin(string $role): Admin
    {
        return Admin::create([
            'name'     => ucfirst($role) . ' Admin ' . uniqid(),
            'email'    => "{$role}_" . uniqid() . '@pkp.co.id',
            'password' => Hash::make('secret123'),
            'role'     => $role,
        ]);
    }

    // =========================================================================
    // 1. SUBMISSIONS & IDOR
    // =========================================================================

    public function test_employee_can_submit_own_attendance_correction(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date'     => now()->subDay()->toDateString(),
            'request_type'        => 'CHECK_IN_AND_OUT',
            'requested_check_in'  => now()->subDay()->setTime(8, 5)->toDateTimeString(),
            'requested_check_out' => now()->subDay()->setTime(17, 10)->toDateTimeString(),
            'reason'              => 'Lupa tap out karena pemadaman listrik di gedung',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.employee_id', $emp->id);

        $this->assertDatabaseHas('attendance_correction_requests', [
            'employee_id' => $emp->id,
            'status'      => 'SUBMITTED',
            'reason'      => 'Lupa tap out karena pemadaman listrik di gedung',
        ]);
    }

    public function test_intern_can_submit_own_attendance_correction(): void
    {
        $intern = $this->createEmployee(['employment_type' => 'INTERNSHIP']);
        $admin = $this->createAdminForEmployee($intern, 'intern');

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date'     => now()->subDays(2)->toDateString(),
            'request_type'        => 'CHECK_IN',
            'requested_check_in'  => now()->subDays(2)->setTime(8, 0)->toDateTimeString(),
            'reason'              => 'Kartu akses tertinggal di rumah',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.employee_id', $intern->id);
    }

    public function test_cross_employee_correction_denied_anti_idor(): void
    {
        $empA = $this->createEmployee();
        $empB = $this->createEmployee();
        $adminA = $this->createAdminForEmployee($empA);

        $response = $this->actingAs($adminA, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'employee_id'     => $empB->id,
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Trying to bypass IDOR',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    public function test_invalid_future_date_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date' => now()->addDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Future correction',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correction_date']);
    }

    public function test_check_in_greater_than_check_out_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date'     => now()->subDay()->toDateString(),
            'request_type'        => 'CHECK_IN_AND_OUT',
            'requested_check_in'  => now()->subDay()->setTime(17, 0)->toDateTimeString(),
            'requested_check_out' => now()->subDay()->setTime(8, 0)->toDateTimeString(),
            'reason'              => 'Invalid time range',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['requested_check_out']);
    }

    public function test_duplicate_pending_correction_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $date = now()->subDay()->toDateString();

        AttendanceCorrectionRequest::create([
            'employee_id'     => $emp->id,
            'correction_date' => $date,
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Initial pending correction',
            'status'          => 'SUBMITTED',
            'submitted_at'    => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date' => $date,
            'request_type'    => 'CHECK_OUT',
            'reason'          => 'Duplicate pending correction attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['correction_date']);
    }

    // =========================================================================
    // 2. APPROVAL SCOPES & ANTI-SELF-APPROVAL
    // =========================================================================

    public function test_employee_self_approval_denied(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'     => $emp->id,
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Self-approval attempt',
            'status'          => 'SUBMITTED',
            'submitted_at'    => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");

        $response->assertStatus(403);
        $this->assertEquals('SUBMITTED', $correction->fresh()->status);
    }

    public function test_supervisor_direct_report_can_approve(): void
    {
        $supEmp = $this->createEmployee();
        $supAdmin = $this->createAdminForEmployee($supEmp, 'supervisor');

        $emp = $this->createEmployee(['supervisor_id' => $supEmp->id]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'correction_date'    => now()->subDay()->toDateString(),
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => now()->subDay()->setTime(8, 5)->toDateTimeString(),
            'reason'             => 'Sensor rusak',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($supAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'APPROVED');

        $this->assertEquals('APPROVED', $correction->fresh()->status);
    }

    public function test_supervisor_unrelated_report_denied(): void
    {
        $supEmpA = $this->createEmployee();
        $supAdminA = $this->createAdminForEmployee($supEmpA, 'supervisor');

        $supEmpB = $this->createEmployee();
        $emp = $this->createEmployee(['supervisor_id' => $supEmpB->id]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'     => $emp->id,
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Cross-supervisor attempt',
            'status'          => 'SUBMITTED',
            'submitted_at'    => now(),
        ]);

        $response = $this->actingAs($supAdminA, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");

        $response->assertStatus(403);
        $this->assertEquals('SUBMITTED', $correction->fresh()->status);
    }

    public function test_hrd_can_approve_org_wide(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'correction_date'    => now()->subDay()->toDateString(),
            'request_type'       => 'CHECK_IN_AND_OUT',
            'requested_check_in' => now()->subDay()->setTime(8, 0)->toDateTimeString(),
            'reason'             => 'HRD approval',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_technical_role_and_building_admin_denied(): void
    {
        $devAdmin = $this->createRoleAdmin('developer');
        $bldAdmin = $this->createRoleAdmin('building_admin');
        $emp = $this->createEmployee();

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'     => $emp->id,
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Technical role test',
            'status'          => 'SUBMITTED',
            'submitted_at'    => now(),
        ]);

        $this->actingAs($devAdmin, 'sanctum')
            ->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")
            ->assertStatus(403);

        $this->actingAs($bldAdmin, 'sanctum')
            ->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")
            ->assertStatus(403);
    }

    public function test_double_approval_prevented(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'     => $emp->id,
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Double approval test',
            'status'          => 'APPROVED',
            'submitted_at'    => now(),
            'approved_at'     => now(),
            'approved_by'     => $hrdAdmin->id,
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // =========================================================================
    // 3. ATTENDANCE CORE INTEGRATION & RAW EVIDENCE IMMUTABILITY
    // =========================================================================

    public function test_approved_correction_updates_derived_attendance_record(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $date = now()->subDays(3)->startOfWeek()->toDateString(); // Monday

        // Pre-existing derived attendance with LATE status
        $attendance = Attendance::create([
            'employee_id'      => $emp->id,
            'work_calendar_id' => $this->calendar->id,
            'attendance_date'  => $date,
            'status'           => 'LATE',
            'clock_in_at'      => Carbon::parse($date . ' 09:30:00'),
            'clock_out_at'     => Carbon::parse($date . ' 17:00:00'),
            'late_minutes'     => 75,
            'attendance_type'  => 'OFFICE',
            'clock_in_source'  => 'MANUAL',
            'clock_out_source' => 'MANUAL',
        ]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'attendance_id'      => $attendance->id,
            'correction_date'    => $date,
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => Carbon::parse($date . ' 08:00:00'),
            'reason'             => 'Hadir tepat waktu 08:00, salah input sistem',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve");
        $response->assertStatus(200);

        $attendance->refresh();
        $this->assertEquals('PRESENT', $attendance->status);
        $this->assertEquals(0, $attendance->late_minutes);
        $this->assertEquals(Carbon::parse($date . ' 08:00:00')->toDateTimeString(), $attendance->clock_in_at->toDateTimeString());
        $this->assertStringContainsString('[KOREKSI_MANUAL: Hadir tepat waktu 08:00, salah input sistem]', $attendance->notes);

        // Immutable original snapshot verified
        $correction->refresh();
        $this->assertEquals('APPROVED', $correction->status);
        $this->assertEquals('LATE', $correction->original_status);
        $this->assertEquals('PRESENT', $correction->corrected_status);
    }

    public function test_raw_access_log_and_attendance_evidence_remain_untouched(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $date = now()->subDays(2)->startOfWeek()->toDateString();

        $door = Door::create([
            'door_id'   => 'DOOR-TEST-1',
            'door_name' => 'Main Lobby Entrance',
            'location'  => 'Main Lobby',
            'status'    => 'online',
        ]);

        // Raw physical log (MUST REMAIN COMPLETELY IMMUTABLE)
        $rawLog = AccessLog::create([
            'log_id'        => 'LOG-' . uniqid(),
            'door_id'       => $door->id,
            'employee_id'   => $emp->id,
            'card_number'   => 'CARD123456',
            'name'          => $emp->name,
            'timestamp'     => Carbon::parse($date . ' 09:15:00'),
            'access_status' => 'Granted',
            'event_type'    => 'Entry',
        ]);

        $evidence = AttendanceEvidence::create([
            'access_log_id'   => $rawLog->id,
            'employee_id'     => $emp->id,
            'event_timestamp' => Carbon::parse($date . ' 09:15:00'),
            'direction'       => 'IN',
            'status'          => 'MAPPED',
        ]);

        $attendance = Attendance::create([
            'employee_id'      => $emp->id,
            'work_calendar_id' => $this->calendar->id,
            'attendance_date'  => $date,
            'status'           => 'LATE',
            'clock_in_at'      => Carbon::parse($date . ' 09:15:00'),
            'access_log_in_id' => $rawLog->id,
            'attendance_type'  => 'OFFICE',
        ]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'attendance_id'      => $attendance->id,
            'correction_date'    => $date,
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => Carbon::parse($date . ' 08:00:00'),
            'reason'             => 'Peralatan gerbang lambat merespon',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/approve")->assertStatus(200);

        // Assert Raw AccessLog is completely untouched
        $rawLog->refresh();
        $this->assertEquals(Carbon::parse($date . ' 09:15:00')->toDateTimeString(), $rawLog->timestamp->toDateTimeString());
        $this->assertEquals('Granted', $rawLog->access_status);

        // Assert AttendanceEvidence is completely untouched
        $evidence->refresh();
        $this->assertEquals(Carbon::parse($date . ' 09:15:00')->toDateTimeString(), $evidence->event_timestamp->toDateTimeString());
        $this->assertEquals('MAPPED', $evidence->status);

        // Derived Attendance is corrected
        $attendance->refresh();
        $this->assertEquals(Carbon::parse($date . ' 08:00:00')->toDateTimeString(), $attendance->clock_in_at->toDateTimeString());
        $this->assertEquals('MANUAL', $attendance->clock_in_source);
        $this->assertEquals($rawLog->id, $attendance->access_log_in_id); // Reference preserved
    }

    public function test_rejected_correction_does_not_modify_attendance(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $date = now()->subDay()->toDateString();

        $attendance = Attendance::create([
            'employee_id'      => $emp->id,
            'work_calendar_id' => $this->calendar->id,
            'attendance_date'  => $date,
            'status'           => 'ABSENT',
        ]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'attendance_id'      => $attendance->id,
            'correction_date'    => $date,
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => Carbon::parse($date . ' 08:00:00'),
            'reason'             => 'Bohong hadir',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/reject", [
            'reason' => 'Bukti rekaman CCTV membuktikan tidak hadir',
        ]);

        $response->assertStatus(200);
        $attendance->refresh();
        $this->assertEquals('ABSENT', $attendance->status);
        $this->assertNull($attendance->clock_in_at);
    }

    public function test_cancelled_correction_does_not_modify_attendance(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $date = now()->subDay()->toDateString();

        $attendance = Attendance::create([
            'employee_id'      => $emp->id,
            'work_calendar_id' => $this->calendar->id,
            'attendance_date'  => $date,
            'status'           => 'ABSENT',
        ]);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'        => $emp->id,
            'attendance_id'      => $attendance->id,
            'correction_date'    => $date,
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => Carbon::parse($date . ' 08:00:00'),
            'reason'             => 'Salah hari pengajuan',
            'status'             => 'SUBMITTED',
            'submitted_at'       => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/attendance-corrections/{$correction->id}/cancel");
        $response->assertStatus(200);

        $attendance->refresh();
        $this->assertEquals('ABSENT', $attendance->status);
        $this->assertEquals('CANCELLED', $correction->fresh()->status);
    }

    // =========================================================================
    // 4. ATTACHMENT & AUDIT
    // =========================================================================

    public function test_attachment_upload_and_idor_protected_download(): void
    {
        $empA = $this->createEmployee();
        $adminA = $this->createAdminForEmployee($empA);

        $empB = $this->createEmployee();
        $adminB = $this->createAdminForEmployee($empB);

        $file = UploadedFile::fake()->create('surat_tugas.pdf', 100, 'application/pdf');

        $response = $this->actingAs($adminA, 'sanctum')->post('/api/v1/attendance-corrections', [
            'correction_date'    => now()->subDay()->toDateString(),
            'request_type'       => 'CHECK_IN',
            'requested_check_in' => now()->subDay()->setTime(8, 0)->toDateTimeString(),
            'reason'             => 'Tugas luar kantor pagi',
            'attachment'         => $file,
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);
        $correctionId = $response->json('data.id');

        // Other employee B cannot download attachment (Anti-IDOR)
        $this->actingAs($adminB, 'sanctum')
            ->get("/api/v1/attendance-corrections/{$correctionId}/attachment")
            ->assertStatus(403);

        // Owner employee A can download attachment
        $downloadRes = $this->actingAs($adminA, 'sanctum')
            ->get("/api/v1/attendance-corrections/{$correctionId}/attachment");
        $downloadRes->assertStatus(200);
    }

    public function test_audit_log_recorded_across_lifecycle(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $hrd = $this->createRoleAdmin('hrd');

        // Submit
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/attendance-corrections', [
            'correction_date' => now()->subDay()->toDateString(),
            'request_type'    => 'CHECK_IN',
            'reason'          => 'Audit lifecycle test',
        ]);
        $id = $res->json('data.id');

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'ATTENDANCE_CORRECTION_CREATED',
            'subject_type' => 'AttendanceCorrectionRequest',
            'subject_id'   => $id,
        ]);

        // Approve
        $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/attendance-corrections/{$id}/approve");

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'ATTENDANCE_CORRECTION_APPROVED',
            'subject_type' => 'AttendanceCorrectionRequest',
            'subject_id'   => $id,
        ]);
    }

    public function test_dashboard_renders_attendance_correction_tab_for_authorized_users(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin)->get('/');
        $response->assertStatus(200);
        $response->assertSee('attendanceCorrectionsTab');
        $response->assertSee('Koreksi Presensi');
    }
}

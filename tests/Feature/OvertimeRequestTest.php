<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\PublicHoliday;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OvertimeRequestTest extends TestCase
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
    // 1. SUBMISSION & VALIDATION
    // =========================================================================

    public function test_employee_can_submit_own_overtime_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $date = now()->addDay()->toDateString();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'overtime_date'          => $date,
            'requested_start'        => '17:30',
            'requested_end'          => '19:30',
            'reason'                 => 'Rilis sistem produksi Sprint 12',
            'project_task_reference' => 'TASK-SPRINT-12',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.requested_minutes', 120)
            ->assertJsonPath('data.approved_minutes', 0); // Unapproved overtime = 0

        $this->assertDatabaseHas('overtime_requests', [
            'employee_id'       => $emp->id,
            'requested_minutes' => 120,
            'approved_minutes'  => 0,
            'status'            => 'SUBMITTED',
        ]);
    }

    public function test_cross_employee_submit_denied_anti_idor(): void
    {
        $empA = $this->createEmployee();
        $empB = $this->createEmployee();
        $adminA = $this->createAdminForEmployee($empA);

        $response = $this->actingAs($adminA, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'employee_id'     => $empB->id,
            'overtime_date'   => now()->addDay()->toDateString(),
            'requested_start' => '17:30',
            'requested_end'   => '19:30',
            'reason'          => 'IDOR attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id']);
    }

    public function test_invalid_interval_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'overtime_date'   => now()->addDay()->toDateString(),
            'requested_start' => '19:30',
            'requested_end'   => '17:30', // End before start
            'reason'          => 'Invalid interval',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['requested_end']);
    }

    public function test_duplicate_overlapping_overtime_request_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $date = now()->addDay()->toDateString();

        OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => $date,
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Existing overtime',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'overtime_date'   => $date,
            'requested_start' => '18:00',
            'requested_end'   => '20:00', // Overlaps 18:00 - 19:30
            'reason'          => 'Overlapping overtime attempt',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['overtime_date']);
    }

    public function test_overtime_during_approved_leave_or_sick_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $date = now()->addDays(2)->toDateString();

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => AttendanceRequest::TYPE_LEAVE,
            'start_date'   => $date,
            'end_date'     => $date,
            'reason'       => 'Cuti tahunan',
            'status'       => AttendanceRequest::STATUS_APPROVED,
            'submitted_at' => now(),
            'approved_at'  => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'overtime_date'   => $date,
            'requested_start' => '17:30',
            'requested_end'   => '20:30',
            'reason'          => 'Attempt overtime while on leave',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['overtime_date']);
    }

    // =========================================================================
    // 2. APPROVAL & SCOPES
    // =========================================================================

    public function test_self_approval_denied(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Self approval attempt',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve");

        $response->assertStatus(403);
        $this->assertEquals('SUBMITTED', $ot->fresh()->status);
    }

    public function test_supervisor_direct_report_can_approve(): void
    {
        $supEmp = $this->createEmployee();
        $supAdmin = $this->createAdminForEmployee($supEmp, 'supervisor');

        $emp = $this->createEmployee(['supervisor_id' => $supEmp->id]);

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Pekerjaan mendesak',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($supAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve", [
            'approved_minutes' => 120,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_minutes', 120);

        $this->assertEquals('APPROVED', $ot->fresh()->status);
    }

    public function test_supervisor_unrelated_report_denied(): void
    {
        $supEmpA = $this->createEmployee();
        $supAdminA = $this->createAdminForEmployee($supEmpA, 'supervisor');

        $supEmpB = $this->createEmployee();
        $emp = $this->createEmployee(['supervisor_id' => $supEmpB->id]);

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Cross-supervisor attempt',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $this->actingAs($supAdminA, 'sanctum')
            ->postJson("/api/v1/overtime-requests/{$ot->id}/approve")
            ->assertStatus(403);
    }

    public function test_hrd_can_approve_org_wide(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'HRD approval',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.approved_minutes', 120);
    }

    public function test_technical_role_and_building_admin_denied(): void
    {
        $devAdmin = $this->createRoleAdmin('developer');
        $bldAdmin = $this->createRoleAdmin('building_admin');
        $emp = $this->createEmployee();

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Technical role test',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $this->actingAs($devAdmin, 'sanctum')
            ->postJson("/api/v1/overtime-requests/{$ot->id}/approve")
            ->assertStatus(403);

        $this->actingAs($bldAdmin, 'sanctum')
            ->postJson("/api/v1/overtime-requests/{$ot->id}/approve")
            ->assertStatus(403);
    }

    public function test_double_approval_prevented(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'approved_minutes'  => 120,
            'reason'            => 'Double approval test',
            'status'            => 'APPROVED',
            'submitted_at'      => now(),
            'approved_at'       => now(),
            'approved_by'       => $hrdAdmin->id,
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    // =========================================================================
    // 3. OVERTIME CALCULATION & ATTENDANCE INTEGRATION
    // =========================================================================

    public function test_late_checkout_alone_does_not_create_overtime(): void
    {
        $emp = $this->createEmployee();
        $date = now()->subDays(2)->startOfWeek()->toDateString(); // Monday (work_end = 17:00)

        // Employee clocks out at 19:30 (2.5 hours late checkout, but NO approved overtime)
        $attendance = Attendance::create([
            'employee_id'           => $emp->id,
            'work_calendar_id'      => $this->calendar->id,
            'attendance_date'       => $date,
            'status'                => 'PRESENT',
            'clock_in_at'           => Carbon::parse($date . ' 08:00:00'),
            'clock_out_at'          => Carbon::parse($date . ' 19:30:00'),
            'effective_work_minutes'=> 690,
            'overtime_minutes'      => 0, // MUST REMAIN 0 WITHOUT APPROVED OVERTIME
        ]);

        $this->assertEquals(0, $attendance->overtime_minutes);
    }

    public function test_approved_overtime_attaches_calculated_minutes_to_attendance(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $date = now()->subDays(2)->startOfWeek()->toDateString();

        $attendance = Attendance::create([
            'employee_id'           => $emp->id,
            'work_calendar_id'      => $this->calendar->id,
            'attendance_date'       => $date,
            'status'                => 'PRESENT',
            'clock_in_at'           => Carbon::parse($date . ' 08:00:00'),
            'clock_out_at'          => Carbon::parse($date . ' 19:30:00'),
            'effective_work_minutes'=> 690,
            'overtime_minutes'      => 0,
        ]);

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'attendance_id'     => $attendance->id,
            'overtime_date'     => $date,
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Penyelesaian bug kritis rilis',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve", [
            'approved_minutes' => 90, // Approver approves 90 minutes
        ]);

        $response->assertStatus(200);

        $attendance->refresh();
        $this->assertEquals(90, $attendance->overtime_minutes);
        $this->assertStringContainsString('[LEMBUR_DISETUJUI: 90 menit]', $attendance->notes);
    }

    public function test_approved_minutes_cannot_exceed_requested_minutes(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '18:30',
            'requested_minutes' => 60,
            'reason'            => 'Over-approval test',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve", [
            'approved_minutes' => 120, // Exceeds requested 60 minutes
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['approved_minutes']);
    }

    public function test_off_day_overtime_attaches_to_attendance_preserving_calendar(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $sunday = now()->startOfWeek()->subDay()->toDateString(); // Sunday (non-working day)

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => $sunday,
            'requested_start'   => '09:00',
            'requested_end'     => '13:00',
            'requested_minutes' => 240,
            'reason'            => 'Maintenance server hari libur',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve");
        $response->assertStatus(200);

        $attendance = Attendance::where('employee_id', $emp->id)->whereDate('attendance_date', $sunday)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals('OFF', $attendance->status); // Preserves calendar semantics (remains OFF)
        $this->assertEquals(240, $attendance->overtime_minutes);
    }

    public function test_public_holiday_overtime_attaches_to_attendance(): void
    {
        $hrdAdmin = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();
        $holidayDate = now()->addDays(5)->toDateString();

        PublicHoliday::create([
            'holiday_date' => $holidayDate,
            'name'         => 'Hari Libur Nasional',
            'is_recurring' => false,
        ]);

        $ot = OvertimeRequest::create([
            'employee_id'       => $emp->id,
            'overtime_date'     => $holidayDate,
            'requested_start'   => '10:00',
            'requested_end'     => '14:00',
            'requested_minutes' => 240,
            'reason'            => 'Dinas darurat hari libur nasional',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        $response = $this->actingAs($hrdAdmin, 'sanctum')->postJson("/api/v1/overtime-requests/{$ot->id}/approve");
        $response->assertStatus(200);

        $attendance = Attendance::where('employee_id', $emp->id)->whereDate('attendance_date', $holidayDate)->first();
        $this->assertNotNull($attendance);
        $this->assertEquals('OFF', $attendance->status); // Holiday status preserved
        $this->assertEquals(240, $attendance->overtime_minutes);
    }

    // =========================================================================
    // 4. ATTACHMENT, CANCEL & AUDIT
    // =========================================================================

    public function test_cancel_own_pending_overtime_allowed_other_denied(): void
    {
        $empA = $this->createEmployee();
        $adminA = $this->createAdminForEmployee($empA);

        $empB = $this->createEmployee();
        $adminB = $this->createAdminForEmployee($empB);

        $ot = OvertimeRequest::create([
            'employee_id'       => $empA->id,
            'overtime_date'     => now()->addDay()->toDateString(),
            'requested_start'   => '17:30',
            'requested_end'     => '19:30',
            'requested_minutes' => 120,
            'reason'            => 'Cancel test',
            'status'            => 'SUBMITTED',
            'submitted_at'      => now(),
        ]);

        // Employee B cannot cancel Employee A's overtime
        $this->actingAs($adminB, 'sanctum')
            ->postJson("/api/v1/overtime-requests/{$ot->id}/cancel")
            ->assertStatus(403);

        // Employee A can cancel own overtime
        $this->actingAs($adminA, 'sanctum')
            ->postJson("/api/v1/overtime-requests/{$ot->id}/cancel")
            ->assertStatus(200);

        $this->assertEquals('CANCELLED', $ot->fresh()->status);
    }

    public function test_overtime_audit_log_recorded(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $hrd = $this->createRoleAdmin('hrd');

        // Submit
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/overtime-requests', [
            'overtime_date'   => now()->addDay()->toDateString(),
            'requested_start' => '17:30',
            'requested_end'   => '19:30',
            'reason'          => 'Audit trail test',
        ]);
        $id = $res->json('data.id');

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'OVERTIME_REQUEST_CREATED',
            'subject_type' => 'OvertimeRequest',
            'subject_id'   => $id,
        ]);

        // Approve
        $this->actingAs($hrd, 'sanctum')->postJson("/api/v1/overtime-requests/{$id}/approve");

        $this->assertDatabaseHas('activity_logs', [
            'action'       => 'OVERTIME_REQUEST_APPROVED',
            'subject_type' => 'OvertimeRequest',
            'subject_id'   => $id,
        ]);
    }

    public function test_dashboard_renders_overtime_tab_for_authorized_users(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $response = $this->actingAs($admin)->get('/');
        $response->assertStatus(200);
        $response->assertSee('overtimeRequestsTab');
        $response->assertSee('Pengajuan Lembur');
    }
}

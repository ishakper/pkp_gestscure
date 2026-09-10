<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected WorkCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Setup default calendar (08:00 - 17:00, Monday to Friday working days)
        $this->calendar = WorkCalendar::create([
            'name'                   => 'Standard Corporate Calendar',
            'code'                   => 'STD_CORP',
            'late_tolerance_minutes' => 15,
            'is_default'             => true,
            'building_id'            => null,
            'is_active'              => true,
        ]);

        // 0=Sunday (off), 1..5=Mon..Fri (working), 6=Saturday (off)
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

    // =========================================================================
    // FACTORIES & HELPERS
    // =========================================================================

    private function createEmployee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id'       => 'EMP-' . uniqid(),
            'nik'               => 'NIK-' . rand(100000, 999999),
            'name'              => 'Employee ' . uniqid(),
            'email'             => 'emp_' . uniqid() . '@pkp.co.id',
            'department'        => 'Human Capital',
            'role'              => 'Staff',
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
            'name'     => ucfirst($role) . ' User ' . uniqid(),
            'email'    => "{$role}_" . uniqid() . '@pkp.co.id',
            'password' => Hash::make('secret123'),
            'role'     => $role,
        ]);
    }

    // =========================================================================
    // 1. SELF-SERVICE SUBMISSIONS
    // =========================================================================

    public function test_employee_can_create_own_wfh_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $res = $this->actingAs($admin)
            ->postJson('/api/v1/attendance-requests', [
                'request_type' => 'WFH',
                'start_date'   => '2026-09-15',
                'end_date'     => '2026-09-16',
                'reason'       => 'Pengerjaan modul arsitektur backend di rumah',
                'metadata'     => ['work_location' => 'Home Office Jakarta'],
            ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.request_type', 'WFH');
        $res->assertJsonPath('data.status', 'SUBMITTED');
        $res->assertJsonPath('data.employee_id', $emp->id);

        $this->assertDatabaseHas('attendance_requests', [
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
        ]);
    }

    public function test_employee_can_create_own_leave_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $res = $this->actingAs($admin)
            ->postJson('/api/v1/attendance-requests', [
                'request_type' => 'LEAVE',
                'category'     => 'ANNUAL',
                'start_date'   => '2026-09-20',
                'end_date'     => '2026-09-22',
                'reason'       => 'Cuti tahunan keluarga',
            ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.request_type', 'LEAVE');
        $res->assertJsonPath('data.category', 'ANNUAL');
    }

    public function test_employee_can_create_own_permission_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $res = $this->actingAs($admin)
            ->postJson('/api/v1/attendance-requests', [
                'request_type' => 'PERMISSION',
                'category'     => 'LATE_ARRIVAL',
                'start_date'   => '2026-09-17',
                'end_date'     => '2026-09-17',
                'start_time'   => '08:00',
                'end_time'     => '10:00',
                'reason'       => 'Izin mengurus perpanjangan SIM di Satpas',
            ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.request_type', 'PERMISSION');
        $res->assertJsonPath('data.start_time', '08:00');
        $res->assertJsonPath('data.end_time', '10:00');
    }

    public function test_employee_can_create_own_sick_request_with_supporting_document(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $document = UploadedFile::fake()->create('surat_dokter.pdf', 200, 'application/pdf');

        $res = $this->actingAs($admin)
            ->post('/api/v1/attendance-requests', [
                'request_type' => 'SICK',
                'start_date'   => '2026-09-18',
                'end_date'     => '2026-09-19',
                'reason'       => 'Demam tinggi dan istirahat dokter',
                'attachment'   => $document,
            ], ['Accept' => 'application/json']);

        $res->assertStatus(201);
        $res->assertJsonPath('data.request_type', 'SICK');
        $res->assertJsonPath('data.attachment_mime', 'application/pdf');

        $req = AttendanceRequest::first();
        $this->assertNotNull($req->attachment_path);
        Storage::disk('local')->assertExists($req->attachment_path);
    }

    public function test_employee_cannot_submit_request_for_another_employee_idor(): void
    {
        $emp1 = $this->createEmployee(['name' => 'Emp 1']);
        $emp2 = $this->createEmployee(['name' => 'Emp 2']);
        $admin1 = $this->createAdminForEmployee($emp1);

        $res = $this->actingAs($admin1)
            ->postJson('/api/v1/attendance-requests', [
                'employee_id'  => $emp2->id,
                'request_type' => 'LEAVE',
                'start_date'   => '2026-09-15',
                'end_date'     => '2026-09-16',
                'reason'       => 'IDOR attempt',
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['employee_id']);
    }

    // =========================================================================
    // 2. APPROVAL WORKFLOW & SCOPES
    // =========================================================================

    public function test_employee_self_approval_is_strictly_prevented(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Self-approval test',
        ]);

        $res = $this->actingAs($admin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve");

        $res->assertStatus(403);
    }

    public function test_supervisor_can_approve_assigned_team_member_request(): void
    {
        $supervisor = $this->createEmployee(['name' => 'Pak Supervisor']);
        $supervisorAdmin = $this->createAdminForEmployee($supervisor, 'supervisor');

        $emp = $this->createEmployee([
            'name'          => 'Staff Member',
            'supervisor_id' => $supervisor->id,
        ]);

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'Remote sprint work',
        ]);

        $res = $this->actingAs($supervisorAdmin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve");

        $res->assertStatus(200);
        $this->assertEquals('APPROVED', $req->fresh()->status);
        $this->assertEquals($supervisorAdmin->id, $req->fresh()->approved_by);
    }

    public function test_supervisor_cannot_approve_unrelated_employee_request(): void
    {
        $supervisor = $this->createEmployee(['name' => 'Pak Supervisor 1']);
        $supervisorAdmin = $this->createAdminForEmployee($supervisor, 'supervisor');

        $otherSupervisor = $this->createEmployee(['name' => 'Pak Supervisor 2']);
        $unrelatedEmp = $this->createEmployee([
            'name'          => 'Unrelated Staff',
            'supervisor_id' => $otherSupervisor->id,
        ]);

        $req = AttendanceRequest::create([
            'employee_id'  => $unrelatedEmp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'Unrelated team request',
        ]);

        $res = $this->actingAs($supervisorAdmin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve");

        $res->assertStatus(403);
    }

    public function test_hrd_can_approve_any_employee_request(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'HR approval test',
        ]);

        $res = $this->actingAs($hrd)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve");

        $res->assertStatus(200);
        $this->assertEquals('APPROVED', $req->fresh()->status);
    }

    public function test_technical_roles_denied_approval_and_management(): void
    {
        $dev = $this->createRoleAdmin('developer');
        $devops = $this->createRoleAdmin('devops');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Technical role test',
        ]);

        $this->actingAs($dev)->getJson('/api/v1/attendance-requests')->assertStatus(403);
        $this->actingAs($devops)->postJson("/api/v1/attendance-requests/{$req->id}/approve")->assertStatus(403);
    }

    public function test_building_admin_denied_hr_wide_approval(): void
    {
        $buildingAdmin = $this->createRoleAdmin('building_admin');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Building admin test',
        ]);

        $this->actingAs($buildingAdmin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve")
            ->assertStatus(403);
    }

    // =========================================================================
    // 3. VALIDATION & OVERLAP
    // =========================================================================

    public function test_invalid_date_range_is_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $res = $this->actingAs($admin)
            ->postJson('/api/v1/attendance-requests', [
                'request_type' => 'LEAVE',
                'start_date'   => '2026-09-20',
                'end_date'     => '2026-09-18', // before start
                'reason'       => 'Invalid dates',
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['end_date']);
    }

    public function test_overlapping_active_requests_are_prevented(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        // First request: 15 to 18 September
        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-18',
            'reason'       => 'Prior leave',
        ]);

        // Attempt overlapping request: 17 to 20 September
        $res = $this->actingAs($admin)
            ->postJson('/api/v1/attendance-requests', [
                'request_type' => 'WFH',
                'start_date'   => '2026-09-17',
                'end_date'     => '2026-09-20',
                'reason'       => 'Overlapping WFH attempt',
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['start_date']);
    }

    // =========================================================================
    // 4. CANCELLATION & REJECTION LIFECYCLE
    // =========================================================================

    public function test_employee_can_cancel_own_pending_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Cancel test',
        ]);

        $res = $this->actingAs($admin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/cancel", [
                'reason' => 'Rencana berubah',
            ]);

        $res->assertStatus(200);
        $this->assertEquals('CANCELLED', $req->fresh()->status);
    }

    public function test_employee_cannot_cancel_already_approved_request(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Approved request',
        ]);

        $res = $this->actingAs($admin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/cancel");

        $res->assertStatus(403);
        $this->assertEquals('APPROVED', $req->fresh()->status);
    }

    public function test_hrd_can_cancel_approved_request(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Approved request to cancel by HR',
        ]);

        $res = $this->actingAs($hrd)
            ->postJson("/api/v1/attendance-requests/{$req->id}/cancel", [
                'reason' => 'Pembatalan darurat oleh manajemen',
            ]);

        $res->assertStatus(200);
        $this->assertEquals('CANCELLED', $req->fresh()->status);
    }

    public function test_double_approval_is_prevented(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-16',
            'reason'       => 'Already approved',
        ]);

        $res = $this->actingAs($hrd)
            ->postJson("/api/v1/attendance-requests/{$req->id}/approve");

        $res->assertStatus(422);
    }

    public function test_supervisor_can_reject_request_with_reason(): void
    {
        $supervisor = $this->createEmployee(['name' => 'Pak SPV']);
        $supervisorAdmin = $this->createAdminForEmployee($supervisor, 'supervisor');

        $emp = $this->createEmployee(['supervisor_id' => $supervisor->id]);
        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'WFH request',
        ]);

        $res = $this->actingAs($supervisorAdmin)
            ->postJson("/api/v1/attendance-requests/{$req->id}/reject", [
                'reason' => 'Ada audit fisik wajib hadir di kantor',
            ]);

        $res->assertStatus(200);
        $this->assertEquals('REJECTED', $req->fresh()->status);
        $this->assertEquals('Ada audit fisik wajib hadir di kantor', $req->fresh()->rejection_reason);
    }

    public function test_rejection_requires_non_empty_reason(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        $req = AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'WFH request',
        ]);

        $res = $this->actingAs($hrd)
            ->postJson("/api/v1/attendance-requests/{$req->id}/reject", [
                'reason' => '',
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['reason']);
    }

    // =========================================================================
    // 5. ATTENDANCE PROCESSOR INTEGRATION & ABSENCE SAFETY
    // =========================================================================

    public function test_approved_leave_prevents_absent_generation(): void
    {
        $emp = $this->createEmployee();
        // Tuesday 15 September 2026 is a working day
        $date = Carbon::parse('2026-09-15');

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'Cuti tahunan',
        ]);

        $processor = app(AttendanceProcessor::class);
        $computed = $processor->computeStatus(null, null, $this->calendar, $date, $emp);

        $this->assertEquals('LEAVE', $computed['status']);
        $this->assertNotEquals('ABSENT', $computed['status']);
    }

    public function test_approved_sick_prevents_absent_generation(): void
    {
        $emp = $this->createEmployee();
        $date = Carbon::parse('2026-09-16'); // Wednesday

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'SICK',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-16',
            'end_date'     => '2026-09-16',
            'reason'       => 'Sakit flu',
        ]);

        $processor = app(AttendanceProcessor::class);
        $computed = $processor->computeStatus(null, null, $this->calendar, $date, $emp);

        $this->assertEquals('SICK', $computed['status']);
        $this->assertNotEquals('ABSENT', $computed['status']);
    }

    public function test_approved_wfh_prevents_absent_generation(): void
    {
        $emp = $this->createEmployee();
        $date = Carbon::parse('2026-09-17'); // Thursday

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-17',
            'end_date'     => '2026-09-17',
            'reason'       => 'Remote sprint work',
        ]);

        $processor = app(AttendanceProcessor::class);
        $computed = $processor->computeStatus(null, null, $this->calendar, $date, $emp);

        $this->assertEquals('WFH', $computed['status']);
        $this->assertNotEquals('ABSENT', $computed['status']);
    }

    public function test_rejected_request_does_not_prevent_absent_generation(): void
    {
        $emp = $this->createEmployee();
        $date = Carbon::parse('2026-09-15'); // Tuesday working day

        AttendanceRequest::create([
            'employee_id'      => $emp->id,
            'request_type'     => 'LEAVE',
            'status'           => 'REJECTED',
            'start_date'       => '2026-09-15',
            'end_date'         => '2026-09-15',
            'reason'           => 'Rejected leave',
            'rejection_reason' => 'Target rilis',
        ]);

        $processor = app(AttendanceProcessor::class);
        $computed = $processor->computeStatus(null, null, $this->calendar, $date, $emp);

        $this->assertEquals('ABSENT', $computed['status']);
    }

    public function test_off_day_is_unaffected_by_requests(): void
    {
        $emp = $this->createEmployee();
        // Sunday 20 September 2026 is OFF
        $sunday = Carbon::parse('2026-09-20');

        $processor = app(AttendanceProcessor::class);
        $computed = $processor->computeStatus(null, null, $this->calendar, $sunday, $emp);

        $this->assertEquals('OFF', $computed['status']);
    }

    // =========================================================================
    // 6. PRIVATE DOCUMENT SECURITY & PRIVACY
    // =========================================================================

    public function test_private_document_upload_and_secure_download(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $hrd = $this->createRoleAdmin('hrd');

        $file = UploadedFile::fake()->create('surat_sakit.pdf', 150, 'application/pdf');

        $res = $this->actingAs($admin)->post('/api/v1/attendance-requests', [
            'request_type' => 'SICK',
            'start_date'   => '2026-09-18',
            'end_date'     => '2026-09-18',
            'reason'       => 'Flu',
            'attachment'   => $file,
        ], ['Accept' => 'application/json']);
        $res->assertStatus(201);
        $reqId = $res->json('data.id');

        // Owner can download
        $downloadRes = $this->actingAs($admin)->get("/api/v1/attendance-requests/{$reqId}/attachment");
        $downloadRes->assertStatus(200);

        // HRD can download
        $hrdDownload = $this->actingAs($hrd)->get("/api/v1/attendance-requests/{$reqId}/attachment");
        $hrdDownload->assertStatus(200);
    }

    public function test_invalid_mime_and_oversized_attachment_are_rejected(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        // Invalid MIME (executable script)
        $badFile = UploadedFile::fake()->create('exploit.sh', 10, 'application/x-sh');
        $res = $this->actingAs($admin)->post('/api/v1/attendance-requests', [
            'request_type' => 'SICK',
            'start_date'   => '2026-09-18',
            'end_date'     => '2026-09-18',
            'reason'       => 'Flu',
            'attachment'   => $badFile,
        ], ['Accept' => 'application/json']);
        $res->assertStatus(422);

        // Oversized file (> 5MB)
        $hugeFile = UploadedFile::fake()->create('huge.pdf', 6000, 'application/pdf');
        $res2 = $this->actingAs($admin)->post('/api/v1/attendance-requests', [
            'request_type' => 'SICK',
            'start_date'   => '2026-09-18',
            'end_date'     => '2026-09-18',
            'reason'       => 'Flu',
            'attachment'   => $hugeFile,
        ], ['Accept' => 'application/json']);
        $res2->assertStatus(422);
    }

    public function test_unauthorized_document_access_is_denied(): void
    {
        $emp1 = $this->createEmployee(['name' => 'Emp 1']);
        $emp2 = $this->createEmployee(['name' => 'Emp 2']);
        $admin1 = $this->createAdminForEmployee($emp1);
        $admin2 = $this->createAdminForEmployee($emp2);

        $file = UploadedFile::fake()->create('surat.pdf', 100, 'application/pdf');
        $res = $this->actingAs($admin1)->post('/api/v1/attendance-requests', [
            'request_type' => 'SICK',
            'start_date'   => '2026-09-18',
            'end_date'     => '2026-09-18',
            'reason'       => 'Flu',
            'attachment'   => $file,
        ], ['Accept' => 'application/json']);
        $reqId = $res->json('data.id');

        // Unrelated employee 2 cannot download employee 1's document
        $this->actingAs($admin2)
            ->get("/api/v1/attendance-requests/{$reqId}/attachment")
            ->assertStatus(403);
    }

    // =========================================================================
    // 7. AUDIT & METRICS
    // =========================================================================

    public function test_audit_trail_recorded_on_lifecycle_events(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);
        $hrd = $this->createRoleAdmin('hrd');

        // Submit
        $res = $this->actingAs($admin)->postJson('/api/v1/attendance-requests', [
            'request_type' => 'WFH',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'Sprint audit test',
        ]);
        $reqId = $res->json('data.id');

        $this->assertDatabaseHas('activity_logs', [
            'admin_id'     => $admin->id,
            'action'       => 'ATTENDANCE_REQUEST_CREATED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $reqId,
        ]);

        // Approve
        $this->actingAs($hrd)->postJson("/api/v1/attendance-requests/{$reqId}/approve");

        $this->assertDatabaseHas('activity_logs', [
            'admin_id'     => $hrd->id,
            'action'       => 'ATTENDANCE_REQUEST_APPROVED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $reqId,
        ]);
    }

    public function test_metrics_endpoint_returns_accurate_counters(): void
    {
        $hrd = $this->createRoleAdmin('hrd');
        $emp = $this->createEmployee();

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'WFH',
            'status'       => 'SUBMITTED',
            'start_date'   => '2026-09-15',
            'end_date'     => '2026-09-15',
            'reason'       => 'WFH 1',
        ]);

        AttendanceRequest::create([
            'employee_id'  => $emp->id,
            'request_type' => 'LEAVE',
            'status'       => 'APPROVED',
            'start_date'   => '2026-09-20',
            'end_date'     => '2026-09-21',
            'reason'       => 'Leave 1',
        ]);

        $res = $this->actingAs($hrd)->getJson('/api/v1/attendance-requests/metrics');
        $res->assertStatus(200);
        $res->assertJsonPath('data.total', 2);
        $res->assertJsonPath('data.submitted', 1);
        $res->assertJsonPath('data.approved', 1);
        $res->assertJsonPath('data.wfh_count', 1);
        $res->assertJsonPath('data.leave_count', 1);
    }

    public function test_dashboard_renders_attendance_request_tab_for_authorized_users(): void
    {
        $emp = $this->createEmployee();
        $admin = $this->createAdminForEmployee($emp);

        $res = $this->actingAs($admin)->get('/');
        $res->assertStatus(200);
        $res->assertSee('attendanceRequestsTab');
        $res->assertSee('Pengajuan Absensi');
    }
}

<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\WorkTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $hrd;
    private Admin $supervisor;
    private Admin $employeeUser;
    private Employee $supervisorEmployee;
    private Employee $report;
    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hrd = $this->admin('hrd');
        $this->supervisorEmployee = $this->employee('EMP-SPV-14', 'Supervisor 14');
        $this->report = $this->employee('EMP-REPORT-14', 'Report 14', $this->supervisorEmployee->id);
        $this->otherEmployee = $this->employee('EMP-OTHER-14', 'Other 14');
        $this->supervisor = $this->admin('supervisor', $this->supervisorEmployee->id);
        $this->employeeUser = $this->admin('employee', $this->report->id);
    }

    public function test_management_can_assign_task_and_employee_can_manage_own_worklog(): void
    {
        Sanctum::actingAs($this->hrd);
        $response = $this->postJson('/api/v1/tasks', [
            'title' => 'Prepare access report',
            'description' => 'Compile the weekly report.',
            'employee_id' => $this->report->id,
            'project_name' => 'SecureGate',
            'priority' => 'HIGH',
            'due_date' => now()->addDay()->toDateString(),
        ]);
        $response->assertCreated()->assertJsonPath('data.priority', 'HIGH');
        $task = WorkTask::firstOrFail();

        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('pagination.total_records', 1);
        $this->postJson("/api/v1/tasks/{$task->id}/worklogs", [
            'work_date' => now()->toDateString(), 'duration_minutes' => 90, 'notes' => 'Reviewed source logs.',
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 90);
        $this->putJson("/api/v1/tasks/{$task->id}", ['status' => 'DONE'])->assertOk()->assertJsonPath('data.progress', 100);

        $this->assertDatabaseHas('task_worklogs', ['work_task_id' => $task->id, 'employee_id' => $this->report->id, 'duration_minutes' => 90]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task_created', 'subject_id' => $task->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'task_worklog_created', 'subject_id' => $task->id]);
    }

    public function test_employee_cannot_assign_or_view_another_employees_task(): void
    {
        Sanctum::actingAs($this->employeeUser);
        $this->postJson('/api/v1/tasks', ['title' => 'IDOR task', 'employee_id' => $this->otherEmployee->id])->assertForbidden();

        Sanctum::actingAs($this->hrd);
        $task = WorkTask::create(['task_code' => 'TASK-IDOR-14', 'title' => 'Private task', 'employee_id' => $this->otherEmployee->id, 'assigned_by' => $this->hrd->id]);
        Sanctum::actingAs($this->employeeUser);
        $this->getJson("/api/v1/tasks/{$task->id}")->assertForbidden();
        $this->getJson('/api/v1/tasks/metrics')->assertJsonPath('data.total', 0);
    }

    public function test_supervisor_can_see_direct_report_but_not_unrelated_task(): void
    {
        Sanctum::actingAs($this->hrd);
        $reportTask = WorkTask::create(['task_code' => 'TASK-REPORT-14', 'title' => 'Report task', 'employee_id' => $this->report->id, 'assigned_by' => $this->hrd->id]);
        $otherTask = WorkTask::create(['task_code' => 'TASK-OTHER-14', 'title' => 'Other task', 'employee_id' => $this->otherEmployee->id, 'assigned_by' => $this->hrd->id]);

        Sanctum::actingAs($this->supervisor);
        $this->getJson("/api/v1/tasks/{$reportTask->id}")->assertOk();
        $this->getJson("/api/v1/tasks/{$otherTask->id}")->assertForbidden();
        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonPath('pagination.total_records', 1);
    }

    private function employee(string $employeeId, string $name, ?int $supervisorId = null): Employee
    {
        return Employee::create(['employee_id' => $employeeId, 'nik' => 'NIK-' . $employeeId, 'name' => $name, 'department' => 'Operations', 'supervisor_id' => $supervisorId]);
    }

    private function admin(string $role, ?int $employeeId = null): Admin
    {
        return Admin::create(['name' => strtoupper($role) . ' 14', 'email' => $role . $employeeId . '@task.test', 'password' => bcrypt('password'), 'role' => $role, 'employee_id' => $employeeId]);
    }
}

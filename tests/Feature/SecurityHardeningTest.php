<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_isapi_routes_are_not_registered_in_production(): void
    {
        $route = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/mock/isapi/System/status');

        $this->assertNotNull($route);
        $this->assertContains(app()->environment(), ['local', 'testing']);
    }

    public function test_employee_cannot_assign_revoke_or_sync_doors(): void
    {
        Sanctum::actingAs($this->admin('employee'));

        $this->postJson('/api/v1/user-management/assign-doors')->assertForbidden();
        $this->postJson('/api/v1/user-management/revoke-doors')->assertForbidden();
        $this->postJson('/api/v1/admin/door-assignments/sync')->assertForbidden();
    }

    public function test_unauthorized_door_prevents_partial_batch_assignment(): void
    {
        Queue::fake();
        $admin = $this->admin('infra_admin', 'Gedung A');
        Sanctum::actingAs($admin);
        $employee = $this->employee();
        $this->door('DOOR-A', 'Gedung A');
        $this->door('DOOR-B', 'Gedung B');

        $this->postJson('/api/v1/user-management/assign-doors', [
            'employee_id' => $employee->id,
            'door_ids' => ['DOOR-A', 'DOOR-B'],
        ])->assertForbidden();

        $this->assertSame(0, DoorAssignment::count());
        Queue::assertNothingPushed();
    }

    public function test_super_admin_can_assign_doors(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin('super_admin'));
        $employee = $this->employee();
        $this->door('DOOR-A', 'Gedung A');

        $this->postJson('/api/v1/user-management/assign-doors', [
            'employee_id' => $employee->id,
            'door_id' => 'DOOR-A',
        ])->assertOk();

        $this->assertSame(1, DoorAssignment::count());
        Queue::assertPushed(SyncDoorAccessJob::class);
    }

    public function test_super_admin_can_revoke_one_door_and_audit_application_state(): void
    {
        $admin = $this->admin('super_admin');
        $employee = $this->employee();
        $door = $this->door('DOOR-A', 'Gedung A');
        $assignment = DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'synced', 'sync_attempts' => 1]);
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/v1/user-management/employees/{$employee->id}/door-access/{$door->door_id}")
            ->assertOk()->assertJsonPath('data.application_status', 'revoked')->assertJsonPath('data.device_status', 'not_queued');
        $this->assertDatabaseMissing('door_assignments', ['id' => $assignment->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'revoke_door_access', 'subject_type' => 'Employee', 'subject_id' => $employee->id]);
    }

    public function test_revoke_door_queues_device_revoke_for_active_credentials(): void
    {
        Sanctum::actingAs($this->admin('super_admin'));
        $employee = $this->employee();
        $door = $this->door('DOOR-A', 'Gedung A');
        $otherDoor = $this->door('DOOR-C', 'Gedung A');
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'synced']);
        $otherAssignment = DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $otherDoor->id, 'sync_status' => 'synced']);
        \App\Models\CredentialRecord::create(['credential_number' => 'CRD-1001', 'employee_id' => $employee->id, 'masked_identifier' => '****1001', 'status' => 'ACTIVE']);

        $this->deleteJson("/api/v1/user-management/employees/{$employee->id}/door-access/{$door->door_id}")
            ->assertOk()->assertJsonPath('data.device_status', 'queued')->assertJsonPath('data.application_status', 'revoked');

        $this->assertDatabaseMissing('door_assignments', ['employee_id' => $employee->id, 'door_id' => $door->id]);
        $this->assertDatabaseHas('door_assignments', ['id' => $otherAssignment->id]);
        $this->assertDatabaseHas('credential_device_syncs', ['employee_id' => $employee->id, 'door_id' => $door->id, 'operation' => 'REVOKE', 'status' => 'QUEUED']);
    }
    public function test_revoke_door_is_idempotent_when_assignment_is_missing(): void
    {
        Sanctum::actingAs($this->admin('super_admin'));
        $employee = $this->employee();
        $door = $this->door('DOOR-A', 'Gedung A');

        $this->deleteJson("/api/v1/user-management/employees/{$employee->id}/door-access/{$door->door_id}")
            ->assertOk()->assertJsonPath('data.application_status', 'revoked');
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_building_admin_cannot_revoke_door_outside_scope(): void
    {
        Sanctum::actingAs($this->admin('infra_admin', 'Gedung A'));
        $employee = $this->employee();
        $door = $this->door('DOOR-B', 'Gedung B');
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id]);

        $this->deleteJson("/api/v1/user-management/employees/{$employee->id}/door-access/{$door->door_id}")->assertForbidden();
        $this->assertDatabaseHas('door_assignments', ['employee_id' => $employee->id, 'door_id' => $door->id]);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'revoke_door_access']);
    }
    private function admin(string $role, ?string $building = null): Admin
    {
        return Admin::create([
            'name' => $role,
            'email' => $role . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_building' => $building,
        ]);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-1001',
            'name' => 'Employee',
            'department' => 'IT',
        ]);
    }

    private function door(string $id, string $location): Door
    {
        return Door::create([
            'door_id' => $id,
            'door_name' => $id,
            'location' => $location,
            'device_ip' => $id === 'DOOR-B' ? '192.168.90.15' : '192.168.90.11',
        ]);
    }
}


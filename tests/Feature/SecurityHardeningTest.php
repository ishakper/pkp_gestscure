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
        $doorA = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door A',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
        ]);
        $doorB = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
        ]);

        $this->postJson('/api/v1/user-management/assign-doors', [
            'employee_id' => $employee->id,
            'door_ids' => [$doorA->door_id, $doorB->door_id],
        ])->assertForbidden();

        $this->assertSame(0, DoorAssignment::count());
        Queue::assertNothingPushed();
    }

    public function test_super_admin_can_assign_doors(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin('super_admin'));
        $employee = $this->employee();
        $doorA = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door A',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
        ]);

        $this->postJson('/api/v1/user-management/assign-doors', [
            'employee_id' => $employee->id,
            'door_id' => $doorA->door_id,
        ])->assertOk();

        $this->assertSame(1, DoorAssignment::count());
        Queue::assertPushed(SyncDoorAccessJob::class);
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

    // door() helper removed - create doors directly in tests with unique IDs
}
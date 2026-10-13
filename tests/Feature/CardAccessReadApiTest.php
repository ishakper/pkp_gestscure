<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\CredentialRecord;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardAccessReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_card_access_requires_permission(): void
    {
        $admin = Admin::factory()->create(['role' => 'employee']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/card-access/overview')->assertForbidden();
    }

    public function test_building_admin_without_scope_is_denied_for_all_card_access_reads(): void
    {
        $admin = Admin::factory()->create(['role' => 'building_admin', 'employee_id' => null, 'assigned_building' => null]);
        Sanctum::actingAs($admin);

        foreach ([
            '/api/v1/card-access/overview',
            '/api/v1/card-access/activity',
            '/api/v1/card-access/cards',
            '/api/v1/card-access/employees/1',
        ] as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('message', 'Akun belum memiliki cakupan gedung. Hubungi administrator.');
        }

        $this->getJson('/api/v1/card-access/employees/1/audit')->assertForbidden();

    }
    public function test_canonical_employee_scope_wins_over_conflicting_assigned_building(): void
    {
        $buildingA = Building::create(['code' => 'TEST-A', 'name' => 'Test A', 'is_active' => true]);
        $buildingB = Building::create(['code' => 'TEST-B', 'name' => 'Test B', 'is_active' => true]);
        $adminEmployee = Employee::factory()->create(['building_id' => $buildingA->id]);
        $admin = Admin::factory()->create(['role' => 'building_admin', 'employee_id' => $adminEmployee->id, 'assigned_building' => $buildingB->name]);
        $employeeA = Employee::factory()->create(['building_id' => $buildingA->id]);
        $employeeB = Employee::factory()->create(['building_id' => $buildingB->id]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/card-access/cards?per_page=100')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($employeeA->id));
        $this->assertFalse($ids->contains($employeeB->id));
    }

    public function test_assigned_building_fallback_and_cross_scope_filters_are_enforced(): void
    {
        $buildingA = Building::create(['code' => 'TEST-FALLBACK-A', 'name' => 'Fallback A', 'is_active' => true]);
        $buildingB = Building::create(['code' => 'TEST-FALLBACK-B', 'name' => 'Fallback B', 'is_active' => true]);
        $employeeA = Employee::factory()->create(['building_id' => $buildingA->id, 'card_no' => 'CARD-1234']);
        $employeeB = Employee::factory()->create(['building_id' => $buildingB->id, 'card_no' => 'CARD-5678']);
        $admin = Admin::factory()->create(['role' => 'building_admin', 'assigned_building' => $buildingA->name, 'employee_id' => null]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/card-access/cards?building_id=' . $buildingB->id . '&search=5678&per_page=1')->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
        $this->assertSame([], $response->json('data'));
        $this->getJson('/api/v1/card-access/employees/' . $employeeB->id)->assertNotFound();
        $this->assertNotSame($employeeA->id, $employeeB->id);
    }

    public function test_building_activity_excludes_global_and_other_building_logs(): void
    {
        $buildingA = Building::create(['code' => 'TEST-ACT-A', 'name' => 'Activity A', 'is_active' => true]);
        $buildingB = Building::create(['code' => 'TEST-ACT-B', 'name' => 'Activity B', 'is_active' => true]);
        $adminEmployee = Employee::factory()->create(['building_id' => $buildingA->id]);
        $employeeA = Employee::factory()->create(['building_id' => $buildingA->id]);
        $employeeB = Employee::factory()->create(['building_id' => $buildingB->id]);
        $admin = Admin::factory()->create(['role' => 'building_admin', 'employee_id' => $adminEmployee->id]);
        ActivityLog::create(['admin_id' => $admin->id, 'action' => 'in_scope', 'subject_type' => Employee::class, 'subject_id' => $employeeA->id, 'description' => 'scope-a', 'timestamp' => now()]);
        ActivityLog::create(['admin_id' => $admin->id, 'action' => 'out_scope', 'subject_type' => Employee::class, 'subject_id' => $employeeB->id, 'description' => 'scope-b', 'timestamp' => now()]);
        ActivityLog::create(['admin_id' => $admin->id, 'action' => 'global', 'subject_type' => null, 'subject_id' => null, 'description' => 'global', 'timestamp' => now()]);
        Sanctum::actingAs($admin);

        $titles = collect($this->getJson('/api/v1/card-access/activity?limit=50')->assertOk()->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('In Scope'));
        $this->assertFalse($titles->contains('Out Scope'));
        $this->assertFalse($titles->contains('Global'));
    }

    public function test_legacy_card_is_not_unregistered_and_raw_values_are_hidden(): void
    {
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $employee = Employee::factory()->create(['card_no' => 'LEGACY-1234', 'employment_status' => 'ACTIVE']);
        $response = $this->getJson('/api/v1/card-access/cards')->assertOk();
        $response->assertJsonPath('data.0.credential.origin', 'LEGACY_EMPLOYEE_CARD');
        $response->assertJsonMissing(['card_no' => 'LEGACY-1234']);
        $response->assertJsonMissing(['card_number' => 'LEGACY-1234']);
        $this->assertNotSame('CARD_NOT_REGISTERED', $response->json('data.0.lifecycle_state'));
    }

    public function test_overview_shape_and_pagination_contract(): void
    {
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/card-access/overview')->assertOk()->assertJsonStructure(['data' => ['kpis', 'sync_health' => ['items'], 'action_required', 'as_of']]);
        $this->getJson('/api/v1/card-access/cards?per_page=1')->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page']]);
    }

    public function test_detail_reads_assignment_building_and_canonical_door(): void
    {
        $building = Building::create(['code' => 'TEST-DETAIL', 'name' => 'Detail Building', 'is_active' => true]);
        $door = Door::create(['door_id' => 'DETAIL-DOOR', 'name' => 'Detail Door', 'building_id' => $building->id, 'location' => 'Detail Building', 'status' => 'online']);
        $employee = Employee::factory()->create(['building_id' => $building->id]);
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'pending', 'sync_attempts' => 0]);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        Sanctum::actingAs($admin);

        $this->getJson("/api/v1/card-access/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.access.buildings.0', 'Detail Building')
            ->assertJsonPath('data.access.doors.0.door_id', 'DETAIL-DOOR');
    }

    public function test_audit_requires_audit_permission(): void
    {
        $admin = Admin::factory()->create(['role' => 'employee']);
        $employee = Employee::factory()->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/card-access/employees/{$employee->id}/audit")->assertForbidden();
    }
}

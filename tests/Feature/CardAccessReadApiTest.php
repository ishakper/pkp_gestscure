<?php

namespace Tests\Feature;

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

    public function test_audit_requires_audit_permission(): void
    {
        $admin = Admin::factory()->create(['role' => 'employee']);
        $employee = Employee::factory()->create();
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/card-access/employees/{$employee->id}/audit")->assertForbidden();
    }
}

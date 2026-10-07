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

    public function test_access_catalog_requires_credential_view(): void
    {
        $admin = Admin::factory()->create(['role' => 'employee']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/card-access/access-catalog')->assertForbidden();
    }

    public function test_access_catalog_obeys_building_scope(): void
    {
        $allowed = Building::factory()->create(['name' => 'Gedung Scope A']);
        $hidden = Building::factory()->create(['name' => 'Gedung Scope B']);
        $admin = Admin::factory()->create(['role' => 'building_admin', 'assigned_building' => $allowed->name]);
        Door::factory()->create(['building_id' => $allowed->id]);
        Door::factory()->create(['building_id' => $hidden->id]);
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/card-access/access-catalog')->assertOk();
        $names = collect($response->json('data.buildings'))->pluck('name')->all();
        $this->assertContains($allowed->name, $names);
        $this->assertNotContains($hidden->name, $names);
    }

    public function test_access_assignment_returns_existing_assignment_read_only(): void
    {
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $building = Building::factory()->create();
        $door = Door::factory()->create(['building_id' => $building->id, 'door_id' => 'DOOR-READ-1']);
        $employee = Employee::factory()->create(['building_id' => $building->id, 'card_no' => 'RAW-ASSIGNMENT-1']);
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'synced']);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/card-access/employees/{$employee->id}/access-assignment")->assertOk();
        $response->assertJsonPath('data.doors.0', 'DOOR-READ-1');
        $this->assertNoRawCardData($response->json());
    }

    public function test_access_assignment_rejects_employee_outside_scope(): void
    {
        $allowed = Building::factory()->create(['name' => 'Gedung Allowed']);
        $hidden = Building::factory()->create(['name' => 'Gedung Hidden']);
        $admin = Admin::factory()->create(['role' => 'building_admin', 'assigned_building' => $allowed->name]);
        $employee = Employee::factory()->create(['building_id' => $hidden->id]);
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/card-access/employees/{$employee->id}/access-assignment")->assertNotFound();
    }

    public function test_device_sync_returns_normalized_records(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        \App\Models\DevicePersonState::create([
            'door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-001',
            'device_name' => $employee->name, 'present_on_device' => true, 'card_masks' => ['••••1234'],
            'status' => 'MATCH', 'device_link_status' => 'SYNCED', 'card_status' => 'MATCH',
        ]);
        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/v1/card-access/device-sync')->assertOk();
        $response->assertJsonPath('data.0.employee.id', $employee->id);
        $response->assertJsonPath('data.0.device.sync_status', 'SYNCED');
        $this->assertNoRawCardData($response->json());
    }

    public function test_device_sync_status_filter_works(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        \App\Models\DevicePersonState::create([
            'door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-002',
            'device_name' => $employee->name, 'present_on_device' => true, 'status' => 'MATCH',
            'device_link_status' => 'SYNCED', 'card_status' => 'MATCH',
        ]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/card-access/device-sync?status=FAILED')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/card-access/device-sync?status=synced')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_diagnostics_returns_read_only_normalized_snapshot(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        \App\Models\DevicePersonState::create([
            'door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-003',
            'device_name' => $employee->name, 'present_on_device' => true, 'status' => 'MATCH',
            'device_link_status' => 'SYNCED', 'card_status' => 'MATCH', 'last_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/card-access/employees/{$employee->id}/diagnostics")->assertOk();
        $response->assertJsonStructure(['data' => ['employee_id', 'verification', 'sync_status', 'person_match', 'credential_match', 'access_match']]);
        $this->assertNoRawCardData($response->json());
    }

    public function test_read_endpoints_never_expose_raw_credentials_or_hashes(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        CredentialRecord::create([
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'credential_number' => 'TEST-CRED-9999',
            'card_number' => 'RAW-CARD-9999',
            'card_number_hash' => hash('sha256', 'RAW-CARD-9999'),
            'masked_identifier' => '••••9999',
            'status' => 'ACTIVE',
        ]);
        \App\Models\DevicePersonState::create([
            'door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'RAW-UID-9999',
            'card_hashes' => ['RAW-HASH-9999'], 'card_masks' => ['••••9999'], 'present_on_device' => true,
            'status' => 'MATCH', 'device_link_status' => 'SYNCED', 'card_status' => 'MATCH',
        ]);
        Sanctum::actingAs($admin);
        foreach ([
            '/api/v1/card-access/access-catalog',
            '/api/v1/card-access/device-sync',
            "/api/v1/card-access/employees/{$employee->id}/access-assignment",
            "/api/v1/card-access/employees/{$employee->id}/diagnostics",
        ] as $uri) {
            $this->assertNoRawCardData($this->getJson($uri)->assertOk()->json());
        }
    }

    public function test_legacy_card_fallback_does_not_create_credential_record(): void
    {
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $employee = Employee::factory()->create(['card_no' => 'LEGACY-ONLY-7777']);
        Sanctum::actingAs($admin);
        $this->getJson('/api/v1/card-access/device-sync')->assertOk();
        $this->assertDatabaseMissing('credential_records', ['employee_id' => $employee->id]);
    }

    public function test_all_phase_2b_read_endpoints_do_not_mutate_database(): void
    {
        [$admin, $employee] = $this->cardAccessFixture();
        Sanctum::actingAs($admin);
        $tables = ['employees', 'buildings', 'doors', 'door_assignments', 'credential_records', 'device_person_states'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => \DB::table($table)->count()]);
        $this->getJson('/api/v1/card-access/access-catalog')->assertOk();
        $this->getJson('/api/v1/card-access/device-sync')->assertOk();
        $this->getJson("/api/v1/card-access/employees/{$employee->id}/access-assignment")->assertOk();
        $this->getJson("/api/v1/card-access/employees/{$employee->id}/diagnostics")->assertOk();
        $after = collect($tables)->mapWithKeys(fn ($table) => [$table => \DB::table($table)->count()]);
        $this->assertSame($before->all(), $after->all());
    }

    private function cardAccessFixture(): array
    {
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $building = Building::factory()->create();
        $door = Door::factory()->create(['building_id' => $building->id]);
        $employee = Employee::factory()->create(['building_id' => $building->id, 'card_no' => 'LEGACY-1234']);
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'synced']);
        return [$admin, $employee, $door];
    }

    private function assertNoRawCardData(array $payload): void
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['card_no', 'card_number', 'card_number_hash', 'card_hashes', 'RAW-CARD-9999', 'RAW-UID-9999', 'RAW-HASH-9999'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_identity_conflict_never_uses_verification_as_sync_status(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        \App\Models\DevicePersonState::create(['door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-CONFLICT', 'present_on_device' => true, 'status' => 'MATCH', 'identity_status' => 'CONFLICT', 'device_link_status' => 'SYNCED', 'card_status' => 'MATCH']);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/card-access/employees/{$employee->id}/diagnostics")->assertOk();
        $this->assertNotSame('NEEDS_VERIFICATION', $response->json('data.sync_status'));
    }

    public function test_identity_conflict_still_requires_verification(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        \App\Models\DevicePersonState::create(['door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-CONFLICT-2', 'present_on_device' => true, 'status' => 'MATCH', 'identity_status' => 'CONFLICT', 'device_link_status' => 'SYNCED', 'card_status' => 'MATCH']);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/card-access/employees/{$employee->id}")->assertOk();
        $response->assertJsonPath('data.verification', 'NEEDS_VERIFICATION');
    }

    public function test_historical_observed_state_does_not_claim_device_online(): void
    {
        [$admin, $employee, $door] = $this->cardAccessFixture();
        $door->update(['health_status' => null, 'connection_status' => 'offline']);
        \App\Models\DevicePersonState::create(['door_id' => $door->id, 'employee_id' => $employee->id, 'device_employee_no' => 'DEV-HISTORICAL', 'present_on_device' => true, 'status' => 'MATCH', 'device_link_status' => 'SYNCED', 'card_status' => 'MATCH']);
        Sanctum::actingAs($admin);
        $response = $this->getJson("/api/v1/card-access/employees/{$employee->id}")->assertOk();
        $this->assertNotSame('ONLINE', $response->json('data.device.device_status'));
    }
}

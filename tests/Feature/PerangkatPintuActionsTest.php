<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PerangkatPintuActionsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $infraAdmin;
    protected Building $building;
    protected Door $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@test.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
            'assigned_building' => null,
        ]);

        $this->infraAdmin = Admin::create([
            'name' => 'Infra Admin',
            'email' => 'infra@test.local',
            'password' => bcrypt('password'),
            'role' => 'infra_admin',
            'assigned_building' => null,
        ]);

        $this->building = Building::create([
            'code' => 'BLD-TEST',
            'name' => 'Gedung Test',
            'description' => 'Gedung pengujian',
            'is_active' => true,
        ]);

        $this->door = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Door B - Server Room',
            'door_name' => 'Door B - Server Room',
            'location' => $this->building->name,
            'building_id' => $this->building->id,
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'connection_status' => 'online',
            'is_manual_override' => false,
        ]);
    }

    public function test_admin_can_update_door_configuration_and_audit_trail_is_logged(): void
    {
        $response = $this->actingAs($this->superAdmin)->putJson("/api/v1/admin/doors/{$this->door->door_id}", [
            'door_id' => 'DOOR-B',
            'name' => 'Door B - Updated Main Server',
            'building_id' => $this->building->id,
            'device_ip' => '192.168.90.15',
            'gateway' => '192.168.90.1',
            'device_model' => 'DS-K1T804AMF',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.door_name', 'Door B - Updated Main Server');

        $this->door->refresh();
        $this->assertEquals('Door B - Updated Main Server', $this->door->name);

        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $this->superAdmin->id,
            'action' => 'door_updated',
            'subject_type' => 'Door',
            'subject_id' => $this->door->id,
        ]);
    }

    public function test_admin_with_null_assigned_building_can_toggle_maintenance_status(): void
    {
        // 1. Enable maintenance as infra_admin (assigned_building is null)
        $response = $this->actingAs($this->infraAdmin)->patchJson("/api/v1/admin/doors/{$this->door->door_id}/status", [
            'is_manual_override' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_manual_override', true);

        $this->door->refresh();
        $this->assertTrue($this->door->is_manual_override);

        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $this->infraAdmin->id,
            'action' => 'override_door_status',
            'subject_type' => 'Door',
            'subject_id' => $this->door->id,
        ]);

        // 2. Disable maintenance (End Maintenance)
        $responseEnd = $this->actingAs($this->infraAdmin)->patchJson("/api/v1/admin/doors/{$this->door->door_id}/status", [
            'is_manual_override' => false,
        ]);

        $responseEnd->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.is_manual_override', false);

        $this->door->refresh();
        $this->assertFalse($this->door->is_manual_override);
    }

    public function test_remote_unlock_accepts_reason_and_logs_audit_with_reason(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $response = $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/doors/{$this->door->door_id}/open", [
            'reason' => 'Kunjungan VIP Direksi untuk Audit',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $this->superAdmin->id,
            'action' => 'remote_door_opened',
            'subject_type' => 'Door',
            'subject_id' => $this->door->id,
        ]);

        $log = ActivityLog::where('action', 'remote_door_opened')
            ->where('subject_id', $this->door->id)
            ->latest()
            ->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('Alasan: Kunjungan VIP Direksi untuk Audit', $log->description);
    }

    public function test_remote_unlock_honest_error_when_device_fails(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/RemoteControl/door/1' => Http::response('503 Service Unavailable', 503),
        ]);

        $response = $this->actingAs($this->superAdmin)->postJson("/api/v1/admin/doors/{$this->door->door_id}/open", [
            'reason' => 'Emergency Access',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'Gagal membuka pintu: 503 Service Unavailable');
    }

    public function test_ui_contains_modal_helpers_and_action_contracts(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));

        // Modal helper functions exist in dashboard.js
        $this->assertStringContainsString('function openModal(', $script);
        $this->assertStringContainsString('function closeModal(', $script);
        $this->assertStringContainsString('window.openModal = openModal', $script);
        $this->assertStringContainsString('window.closeModal = closeModal', $script);

        // Maintenance badge & button support in UI
        $this->assertStringContainsString('.status-warning', $blade);
        $this->assertStringContainsString("statusLabel = isMaintenance ? 'MAINTENANCE'", $script);
        $this->assertStringContainsString("badgeClass = isMaintenance ? 'status-warning'", $script);
        $this->assertStringContainsString("End Maintenance", $script);

        // Remote unlock confirmation modal includes door select & reason field
        $this->assertStringContainsString('id="remoteUnlockDoorSelect"', $blade);
        $this->assertStringContainsString('id="remoteUnlockReason"', $blade);
        $this->assertStringContainsString('id="confirmRemoteUnlockButton"', $blade);
        $this->assertStringContainsString('onRemoteUnlockDoorChange', $script);
    }
}

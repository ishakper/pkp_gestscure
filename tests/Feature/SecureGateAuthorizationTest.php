<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecureGateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_can_monitor_logs_but_cannot_run_physical_door_commands(): void
    {
        $door = $this->door();
        $manager = $this->admin('building_admin', 'Gedung B');

        $this->actingAs($manager)->getJson('/api/v1/admin/access-logs')->assertOk();
        $this->actingAs($manager)->postJson("/api/v1/admin/doors/{$door->door_id}/open")->assertForbidden();
        $this->actingAs($manager)->postJson("/api/v1/admin/doors/{$door->door_id}/check-connection")->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/admin/doors/check-all')->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/admin/access-logs/sync-hardware')->assertForbidden();
    }

    public function test_maintenance_override_never_fakes_observed_connectivity(): void
    {
        $door = $this->door();
        $admin = $this->admin('super_admin');

        $this->actingAs($admin)
            ->patchJson("/api/v1/admin/doors/{$door->door_id}/status", ['is_manual_override' => true])
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'offline')
            ->assertJsonPath('data.is_manual_override', true);

        $this->assertDatabaseHas('doors', [
            'id' => $door->id,
            'connection_status' => 'offline',
            'is_manual_override' => true,
        ]);
    }

    private function door(): Door
    {
        return Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'name' => 'Restricted Server Room',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'offline',
            'connection_status' => 'offline',
        ]);
    }

    private function admin(string $role, ?string $building = null): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'-securegate@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_building' => $building,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_cannot_view_system_health(): void
    {
        $this->actingAs($this->admin('employee'))->getJson('/api/v1/admin/system-health')->assertForbidden();
    }

    public function test_authorized_roles_receive_safe_health_payload(): void
    {
        foreach (['super_admin', 'infra_admin', 'security_engineer', 'management'] as $role) {
            $response = $this->actingAs($this->admin($role))->getJson('/api/v1/admin/system-health')->assertOk();
            $response->assertJsonPath('status', 'success')->assertJsonPath('data.database.status', 'HEALTHY');
            $payload = strtolower($response->getContent());
            foreach (['app_key', 'password', 'secret', 'cardno', 'php_version', 'laravel_version', 'environment'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $payload);
            }
        }
    }

    public function test_missing_door_and_webhook_are_unknown_and_degrade_app(): void
    {
        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.primary_door.status', 'UNKNOWN')
            ->assertJsonPath('data.webhook.status', 'UNKNOWN')
            ->assertJsonPath('data.app.status', 'DEGRADED');
    }

    public function test_door_health_distinguishes_stale_and_fresh_offline(): void
    {
        $door = $this->door(['connection_status' => 'online', 'last_checked_at' => now()->subMinutes(16)]);
        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.primary_door.status', 'STALE')
            ->assertJsonPath('data.primary_door.stale', true)
            ->assertJsonPath('data.app.status', 'DEGRADED');

        $door->update(['connection_status' => 'offline', 'last_checked_at' => now()]);
        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.primary_door.status', 'OFFLINE')
            ->assertJsonPath('data.primary_door.stale', false)
            ->assertJsonPath('data.app.status', 'DEGRADED');
    }

    public function test_old_hikvision_webhook_is_stale_and_queue_is_safe(): void
    {
        $door = $this->door();
        AccessLog::create([
            'log_id' => 'HEALTH-1', 'door_id' => $door->id, 'event_type' => 'AccessGranted',
            'source' => 'HIKVISION_WEBHOOK', 'access_status' => 'Granted', 'timestamp' => now()->subMinutes(20),
        ]);
        AccessLog::where('log_id', 'HEALTH-1')->update(['created_at' => now()->subMinutes(20)]);

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.webhook.status', 'STALE')
            ->assertJsonPath('data.webhook.stale', true)
            ->assertJsonPath('data.app.status', 'DEGRADED')
            ->assertJsonMissingPath('data.queue.driver');
    }

    public function test_only_fresh_physical_webhook_evidence_activates_health(): void
    {
        config(['queue.default' => 'database']);
        $door = $this->door(['connection_status' => 'online', 'last_checked_at' => now()]);
        foreach (['HIKVISION', 'SIMULATOR'] as $index => $source) {
            AccessLog::create([
                'log_id' => 'NON-WEBHOOK-'.$index, 'door_id' => $door->id, 'event_type' => 'AccessGranted',
                'source' => $source, 'access_status' => 'Granted', 'timestamp' => now(),
            ]);
        }

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.webhook.status', 'UNKNOWN')
            ->assertJsonPath('data.app.status', 'DEGRADED');

        AccessLog::create([
            'log_id' => 'WEBHOOK-ACTIVE', 'door_id' => $door->id, 'event_type' => 'AccessGranted',
            'source' => 'HIKVISION_WEBHOOK', 'access_status' => 'Granted', 'timestamp' => now(),
        ]);

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.database.status', 'HEALTHY')
            ->assertJsonPath('data.primary_door.status', 'HEALTHY')
            ->assertJsonPath('data.webhook.status', 'ACTIVE')
            ->assertJsonPath('data.webhook.stale', false)
            ->assertJsonPath('data.queue.status', 'HEALTHY')
            ->assertJsonPath('data.app.status', 'HEALTHY');
    }

    private function admin(string $role): Admin
    {
        return Admin::create(['name' => $role, 'email' => $role.uniqid().'@example.test', 'password' => bcrypt('password'), 'role' => $role]);
    }

    private function door(array $overrides = []): Door
    {
        return Door::create(array_merge([
            'door_id' => 'DOOR-B', 'name' => 'Door B', 'location' => 'Gedung B',
            'device_ip' => '192.168.90.15', 'status' => 'offline', 'connection_status' => 'offline',
        ], $overrides));
    }
}

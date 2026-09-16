<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->assertJsonPath('data.queue.status', 'UNKNOWN')
            ->assertJsonPath('data.app.status', 'HEALTHY');
    }

    public function test_queue_health_needs_runtime_evidence_and_degrades_on_failures(): void
    {
        config(['queue.default' => 'database']);

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.queue.status', 'UNKNOWN');

        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'safe test failure',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.queue.status', 'DEGRADED')
            ->assertJsonPath('data.queue.failed_jobs', 1);
    }

    public function test_door_summary_uses_freshness_and_any_unhealthy_door_degrades_app(): void
    {
        Carbon::setTestNow('2026-09-15 12:00:00');
        config(['queue.default' => 'database', 'securegate_health.door_fresh_minutes' => 15]);
        $primary = $this->door(['connection_status' => 'online', 'last_checked_at' => now()]);
        $this->door(['door_id' => 'DOOR-OFF', 'connection_status' => 'offline', 'last_checked_at' => now()]);
        $this->door(['door_id' => 'DOOR-STALE', 'connection_status' => 'online', 'last_checked_at' => now()->subSeconds(901)]);
        $this->door(['door_id' => 'DOOR-UNKNOWN', 'connection_status' => 'online', 'last_checked_at' => null]);
        AccessLog::create(['log_id' => 'WEBHOOK-SUMMARY', 'door_id' => $primary->id, 'event_type' => 'AccessGranted', 'source' => 'HIKVISION_WEBHOOK', 'access_status' => 'Granted', 'timestamp' => now()]);

        $this->actingAs($this->admin('super_admin'))->getJson('/api/v1/admin/system-health')
            ->assertJsonPath('data.doors.total', 4)
            ->assertJsonPath('data.doors.online', 1)
            ->assertJsonPath('data.doors.healthy', 1)
            ->assertJsonPath('data.doors.offline', 1)
            ->assertJsonPath('data.doors.stale', 1)
            ->assertJsonPath('data.doors.unknown', 1)
            ->assertJsonPath('data.primary_door.door_id', 'DOOR-B')
            ->assertJsonPath('data.primary_door.status', 'HEALTHY')
            ->assertJsonPath('data.primary_door.device_ip', '192.168.90.15')
            ->assertJsonPath('data.app.status', 'DEGRADED');
    }

    public function test_building_admin_uses_canonical_scope_with_null_id_legacy_fallback(): void
    {
        $buildingA = Building::create(['code' => 'A', 'name' => 'Gedung A', 'is_active' => true]);
        $buildingB = Building::create(['code' => 'B', 'name' => 'Gedung B', 'is_active' => true]);
        $employee = Employee::create(['employee_id' => 'EMP-A', 'nik' => 'NIK-A', 'name' => 'Admin A', 'department' => 'Ops', 'building_id' => $buildingA->id]);
        $admin = $this->admin('building_admin');
        $admin->update(['employee_id' => $employee->id, 'assigned_building' => 'Gedung A']);
        $this->door(['door_id' => 'DOOR-B', 'building_id' => $buildingA->id, 'location' => 'Renamed Location', 'connection_status' => 'online', 'last_checked_at' => now()]);
        $this->door(['door_id' => 'LEGACY-A', 'building_id' => null, 'location' => 'Gedung A', 'connection_status' => 'offline', 'last_checked_at' => now()]);
        $this->door(['door_id' => 'WRONG-CANONICAL', 'building_id' => $buildingB->id, 'location' => 'Gedung A', 'connection_status' => 'offline', 'last_checked_at' => now()]);
        $this->door(['door_id' => 'LEGACY-B', 'building_id' => null, 'location' => 'Gedung B', 'connection_status' => 'offline', 'last_checked_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/system-health')
            ->assertOk()
            ->assertJsonPath('data.doors.total', 2)
            ->assertJsonPath('data.doors.healthy', 1)
            ->assertJsonPath('data.doors.offline', 1)
            ->assertJsonPath('data.primary_door.status', 'HEALTHY');
        $this->assertSame(2, collect($response->json('data.buildings'))->sum('total'));
    }

    public function test_building_admin_without_canonical_or_legacy_scope_is_forbidden(): void
    {
        $this->actingAs($this->admin('building_admin'))->getJson('/api/v1/admin/system-health')->assertForbidden();
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

<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private Door $offlineDoor;
    private Door $onlineDoor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'metrics-admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        Employee::create([
            'employee_id' => 'USR-METRICS',
            'nik' => 'NIK-METRICS',
            'name' => 'Metrics User',
            'department' => 'IT',
        ]);
        $this->offlineDoor = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Door A',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'connection_status' => 'offline',
        ]);
        $this->onlineDoor = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'connection_status' => 'online',
        ]);
    }

    public function test_dashboard_metrics_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/dashboard-metrics')->assertUnauthorized();
    }

    public function test_authorized_dashboard_metrics_match_the_frontend_contract_and_values(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $this->createAccessLog($this->offlineDoor, 'LOG-GRANTED', 'Granted');
        $this->createAccessLog($this->onlineDoor, 'LOG-DENIED', 'Denied');

        $this->getJson('/api/v1/admin/dashboard-metrics')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.totalUsers', 1)
            ->assertJsonPath('data.totalDoors', 2)
            ->assertJsonPath('data.activeDoors', 1)
            ->assertJsonPath('data.grantedLogs', 1)
            ->assertJsonPath('data.deniedLogs', 1)
            ->assertJsonStructure([
                'status',
                'data' => ['totalUsers', 'activeDoors', 'totalDoors', 'grantedLogs', 'deniedLogs'],
            ]);
    }

    public function test_building_admin_metrics_are_limited_to_their_door_scope(): void
    {
        $buildingAdmin = Admin::create([
            'name' => 'Gedung A Administrator',
            'email' => 'metrics-building@example.test',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A',
        ]);
        $this->createAccessLog($this->offlineDoor, 'LOG-A-GRANTED', 'Granted');
        $this->createAccessLog($this->onlineDoor, 'LOG-B-DENIED', 'Denied');
        Sanctum::actingAs($buildingAdmin, ['*']);

        $this->getJson('/api/v1/admin/dashboard-metrics')
            ->assertOk()
            ->assertJsonPath('data.totalDoors', 1)
            ->assertJsonPath('data.activeDoors', 0)
            ->assertJsonPath('data.grantedLogs', 1)
            ->assertJsonPath('data.deniedLogs', 0);
    }

    public function test_system_accounts_are_explicitly_redacted_and_planned(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/v1/admin/system-accounts')->assertOk()
            ->assertJsonPath('lifecycle', 'PLANNED')
            ->assertJsonPath('data.0.status', null)
            ->assertJsonPath('data.0.last_login', null)
            ->assertJsonMissingPath('data.0.password')
            ->assertJsonMissingPath('data.0.remember_token');
    }

    public function test_system_health_preserves_primary_door_and_adds_aggregates(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->getJson('/api/v1/admin/system-health')->assertOk()
            ->assertJsonPath('data.primary_door.door_id', 'DOOR-B')
            ->assertJsonPath('data.doors.total', 2)
            ->assertJsonCount(2, 'data.buildings');
    }

    private function createAccessLog(Door $door, string $logId, string $status): void
    {
        AccessLog::create([
            'log_id' => $logId,
            'door_id' => $door->id,
            'event_type' => 'STANDARD_TAP',
            'verify_method' => 'Card',
            'access_status' => $status,
            'timestamp' => now(),
        ]);
    }
}

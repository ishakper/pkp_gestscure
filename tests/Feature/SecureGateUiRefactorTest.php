<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecureGateUiRefactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_prioritizes_permission_aware_access_control_hierarchy(): void
    {
        $response = $this->actingAs($this->admin('super_admin'))->get('/');

        $response->assertOk()
            ->assertSee('OPERASIONAL')
            ->assertSee('KONFIGURASI')
            ->assertSeeInOrder(['Dashboard', 'Pengguna', 'Perangkat Pintu', 'Hak Akses', 'Rekap Kehadiran', 'Log Akses', 'Audit Log'], false)
            ->assertSee('MODUL TAMBAHAN', false);

        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));
        $this->assertStringContainsString('.advanced-nav { display: none !important; }', $blade);
        $this->assertStringContainsString("CHECKING terminal Gedung B", $script);
        $this->assertStringContainsString("apiFetch('/attendance/records')", $script);
        $this->assertStringNotContainsString("apiFetch('/api/v1/attendance/records')", $script);
    }

    public function test_remote_unlock_uses_safe_modal_and_admin_open_endpoint(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringContainsString('id="remoteUnlockModal"', $blade);
        $this->assertStringContainsString('id="confirmRemoteUnlockButton"', $blade);
        $this->assertStringContainsString('/admin/doors/${encodeURIComponent(door.door_id)}/open', $script);
        $this->assertStringNotContainsString('Konfirmasi: Apakah Anda yakin ingin membuka relay', $script);
        $this->assertStringNotContainsString("window.remoteUnlockDoor = remoteUnlockDoor;\n</script>", $blade);
    }

    public function test_offline_device_card_disables_unlock_and_keeps_connection_check(): void
    {
        Door::create([
            'door_id' => 'DOOR-OFF', 'name' => 'Offline Door', 'location' => 'Gedung A',
            'device_ip' => '192.168.90.99', 'device_model' => 'DS-K1T804AMF',
            'status' => 'offline', 'connection_status' => 'offline',
        ]);

        $script = file_get_contents(public_path('js/dashboard.js'));
        $this->assertStringContainsString("const unlockDisabled = !isOnline", $script);
        $this->assertStringContainsString('Terminal belum terhubung', $script);
        $this->assertStringContainsString('pingSingleDoor', $script);
    }

    public function test_realtime_fallback_never_invokes_physical_connection_checks(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));
        preg_match('/setInterval\(\(\) => \{(?<body>.*?)\},\s*60000\);/s', $script, $match);

        $this->assertNotEmpty($match['body'] ?? null);
        $this->assertStringContainsString('updateMetricCards()', $match['body']);
        $this->assertStringNotContainsString('checkAllDoors', $match['body']);
        $this->assertStringNotContainsString('pingSingleDoor', $match['body']);
    }

    public function test_employee_portal_cannot_read_security_or_trigger_device_actions(): void
    {
        $employee = $this->admin('employee');
        $this->actingAs($employee)->getJson('/api/v1/admin/access-logs')->assertForbidden();
        $this->actingAs($employee)->getJson('/api/v1/admin/activity-logs')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/v1/admin/doors/check-all')->assertForbidden();
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'-ui@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }
}

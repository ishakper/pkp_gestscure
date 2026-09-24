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
            'door_id' => 'DOOR-'.uniqid(), 'name' => 'Offline Door', 'location' => 'Gedung A',
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
        $start = strpos($script, 'function reconcileLiveData()');
        $end = strpos($script, 'function startFallbackPolling()', $start);
        $body = substr($script, $start, $end - $start);

        $this->assertNotEmpty($body);
        $this->assertStringContainsString('updateMetricCards()', $body);
        $this->assertStringNotContainsString('checkAllDoors', $body);
        $this->assertStringNotContainsString('pingSingleDoor', $body);
    }

    public function test_phase_nine_facility_dom_contract_preserves_existing_door_editor(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));

        foreach (['buildingHierarchyContainer', 'addBuildingModal', 'addBuildingForm', 'buildingCodeInput', 'buildingNameInput', 'addZoneModal', 'addZoneForm', 'zoneBuildingSelect', 'zoneCodeInput', 'zoneNameInput', 'facilityModal', 'facilityDoorBuilding', 'facilityOriginalDoorId'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $blade);
        }
        $this->assertStringContainsString('function loadBuildingHierarchy()', $script);
        $this->assertStringContainsString('function openFacilityModal(', $script);
        $this->assertStringNotContainsString('Floor 1 - Main Floor', $script);
    }

    public function test_employee_portal_cannot_read_security_or_trigger_device_actions(): void
    {
        $employee = $this->admin('employee');
        $this->actingAs($employee)->getJson('/api/v1/admin/access-logs')->assertForbidden();
        $this->actingAs($employee)->getJson('/api/v1/admin/activity-logs')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/v1/admin/doors/check-all')->assertForbidden();
    }

    public function test_system_status_dom_and_loader_contract_are_unique(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));

        foreach (['systemHealthCards', 'healthApp', 'healthDatabase', 'healthDoor', 'healthDoorsTotal', 'healthDoorsAggregate', 'healthWebhook', 'healthQueue', 'healthLastEvent', 'systemHealthError'] as $id) {
            $this->assertSame(1, substr_count($blade, 'id="'.$id.'"'), $id);
        }
        $this->assertStringContainsString("if (tabId === 'systemStatusTab') loadSystemHealth();", $script);
        $this->assertStringContainsString('function loadSystemHealth()', $script);
        $this->assertStringContainsString('data.doors || {}', $script);
        $this->assertStringContainsString('doors.healthy ?? doors.online ?? 0', $script);
        $this->assertStringContainsString('let isRedirectingToLogin = false;', $script);
        $this->assertStringContainsString("window.location.replace('/login')", $script);
    }

    public function test_phase_sixteen_frontend_contracts_are_read_only_and_permission_aware(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));

        foreach (['systemAccountsTableBody', 'systemAccountsLifecycle', 'auditLogTab', 'activityLogsTableBody'] as $id) {
            $this->assertSame(1, substr_count($blade, 'id="'.$id.'"'), $id);
        }
        $this->assertStringContainsString("switchTab('auditLogTab', this)", $blade);
        $this->assertStringNotContainsString("data-tooltip=\"Audit Log\" onclick=\"switchTab('logsTab', this)\"", $blade);
        $this->assertStringContainsString("in_array('organization.view', \$permissions ?? [])", $blade);
        $this->assertStringContainsString("in_array('organization.manage', \$permissions ?? [])", $blade);
        $this->assertStringContainsString("if (tabId === 'auditLogTab') loadActivityLogs();", $script);
        $this->assertStringContainsString("if (tabId === 'systemAccountsTab') loadSystemAccounts();", $script);
        $this->assertStringContainsString("apiFetch('/admin/system-accounts')", $script);
        $this->assertStringContainsString('function loadSystemAccounts()', $script);
        $this->assertStringContainsString('Lifecycle: ${response.lifecycle || \'PLANNED\'}', $script);
        $this->assertStringNotContainsString('Modul Akun Sistem dalam Pengembangan', $blade);
        $this->assertStringNotContainsString("method: 'POST',\n            body: JSON.stringify({", substr($script, strpos($script, 'async function loadSystemAccounts()'), strpos($script, 'function healthAge(') - strpos($script, 'async function loadSystemAccounts()')));
    }

    public function test_all_static_dashboard_ids_are_unique(): void
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        preg_match_all('/\\bid="([^"]+)"/', $blade, $matches);
        $duplicates = array_filter(array_count_values($matches[1]), fn (int $count) => $count > 1);

        $this->assertSame([], $duplicates, 'Duplicate DOM IDs: '.json_encode($duplicates));
    }

    public function test_api_client_never_duplicates_version_prefix_and_keeps_critical_bindings(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertDoesNotMatchRegularExpression('/apiFetch(?:Form)?\\(\\s*[\'`]\\/api\\/v1\\//', $script);
        foreach (['/field-attendance/status-today', '/attendance-requests/metrics', '/attendance-corrections/metrics', '/overtime-requests/metrics', '/user-management/employees?per_page=100'] as $endpoint) {
            $this->assertStringContainsString($endpoint, $script);
        }
        $this->assertStringContainsString('async function apiFetchForm(endpoint, formData)', $script);
        $this->assertStringContainsString("const role = (window.APP_CONFIG?.admin?.role || '').toLowerCase();", $script);
        $this->assertStringContainsString("if (window.APP_CONFIG?.sseEnabled !== false)", $script);
        $this->assertStringContainsString("sseEnabled: @json(!app()->environment('testing'))", file_get_contents(resource_path('views/dashboard.blade.php')));
    }

    public function test_realtime_transport_is_singleton_bounded_and_visibility_aware(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertSame(1, substr_count($script, "new EventSource('/live-stream')"));
        $this->assertStringContainsString("realtime.state = 'FALLBACK_POLLING'", $script);
        $this->assertStringContainsString('if (realtime.failures >= 4)', $script);
        $this->assertStringContainsString('Math.min(30000, 2000 * (2 ** (realtime.failures - 1)))', $script);
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $script);
        $this->assertStringContainsString("window.addEventListener('pagehide', stopRealtime)", $script);
        $this->assertStringContainsString("err.status !== 403 && err.status !== 429", $script);
        $this->assertStringNotContainsString("setTimeout(initLiveAccessStream, 5000)", $script);
    }

    public function test_dashboard_never_reads_raw_card_identifiers(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringNotContainsString('emp.card_no', $script);
        $this->assertStringNotContainsString('log.user?.card_no', $script);
        $this->assertStringNotContainsString('log.card_no', $script);
        $this->assertStringNotContainsString('biometric_template', $script);
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

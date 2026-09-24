<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard building selector (Semua Gedung / Gedung A-D), frontend only.
 *
 * Part 1 pins the frontend wiring and UX states. Part 2 pins the API parameters it already relies on.
 * Part 3 are self-activating contract tests for the endpoints Ishak still has to extend: they skip
 * until the endpoint echoes `scope.building_id`, then verify it really filters.
 */
class DashboardBuildingFilterTest extends TestCase
{
    use RefreshDatabase;

    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function body(string $signature): string
    {
        $start = strpos($this->script, $signature);
        $this->assertNotFalse($start, "{$signature} not found in dashboard.js");
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $this->script, $m, PREG_OFFSET_CAPTURE, $start + 1) ? $m[0][1] : strlen($this->script);

        return substr($this->script, $start, $next - $start);
    }

    // ---------------------------------------------------------------- Part 1: frontend contract

    public function test_selector_is_accessible_starts_disabled_and_is_wired(): void
    {
        $this->assertMatchesRegularExpression('/<select id="dashboardBuildingFilter"[^>]*onchange="onDashboardBuildingChange\(this\.value\)"[^>]*aria-label="[^"]+"[^>]*disabled>/', $this->blade);
        $this->assertMatchesRegularExpression('/id="dashboardScopeStatus"[^>]*role="status"[^>]*aria-live="polite"[^>]*hidden/', $this->blade);
        $this->assertStringContainsString("assigned_building: @json(Auth::user()->assigned_building ?? null)", $this->blade);

        foreach (['onDashboardBuildingChange', 'refreshDashboardScope', 'loadDashboardBuildings', 'restoreBuildingFilter', 'doorsInBuildingScope',
                  'fetchScoped', 'legacyBuildingMetrics', 'renderScopeStatus', 'reportScopeError', 'initScopeConnectivity', 'beginLoad', 'endLoad'] as $fn) {
            $this->assertMatchesRegularExpression('/function\s+' . $fn . '\s*\(/', $this->script, "{$fn}() must be defined");
        }
    }

    public function test_every_dashboard_section_honours_the_selected_building(): void
    {
        $this->assertStringContainsString('doorsInBuildingScope(state.allDoors)', $this->body('async function loadDoors('));
        $this->assertStringContainsString('&building_id=${encodeURIComponent(state.buildingFilter)}', $this->body('async function loadEmployees('));
        $this->assertStringContainsString("fetchScoped('/admin/dashboard-metrics', 'metrics', scope)", $this->body('async function updateMetricCards('));
        $this->assertStringContainsString("fetchScoped('/attendance/metrics', 'attendance', scope)", $this->body('async function updateMetricCards('));
        $this->assertStringContainsString("fetchScoped('/attendance/metrics', 'attendance', state.buildingFilter)", $this->body('async function loadAttendanceMetrics('));
        $logs = $this->body('async function loadAccessLogs(');
        $this->assertStringContainsString('&building_id=${encodeURIComponent(buildingId)}', $logs);
        $this->assertStringContainsString('/admin/access-logs?limit=40', $logs);
    }

    public function test_the_backend_echo_decides_whether_a_response_is_scoped(): void
    {
        $this->assertStringContainsString('res?.scope?.building_id', $this->body('function scopeHonoured('));
        $this->assertStringContainsString('String(echoed) === String(scope)', $this->body('function scopeHonoured('));
        $this->assertStringContainsString('do not show unfiltered rows as if they were scoped', $this->body('async function loadAccessLogs('));
    }

    public function test_the_legacy_fallback_does_not_fabricate_numbers(): void
    {
        $fallback = $this->body('async function legacyBuildingMetrics(');

        $this->assertStringContainsString('registeredCredentials: null', $fallback);
        $this->assertStringContainsString('? null : activeUsers', $fallback);
        $this->assertStringNotContainsString('.filter(emp', $fallback, 'never count credentials client-side');
        $this->assertStringNotContainsString('fetchEmployeesForBuilding', $this->script);
        $this->assertStringContainsString("element.innerText = missing ? '—' : value;", $this->body('function setMetric('));
    }

    public function test_loading_empty_offline_and_error_states_exist(): void
    {
        $status = $this->body('function renderScopeStatus(');
        foreach (["kind = 'offline'", "kind = 'error'", "kind = 'loading'", "kind = 'legacy'", "kind = 'ready'", 'scope-retry'] as $needle) {
            $this->assertStringContainsString($needle, $status);
        }
        $connectivity = $this->body('function initScopeConnectivity(');
        $this->assertStringContainsString("addEventListener('offline'", $connectivity);
        $this->assertStringContainsString("addEventListener('online'", $connectivity);

        $this->assertStringContainsString("buildingEmptyText('Belum ada terminal pintu terkonfigurasi')", $this->script);
        $this->assertStringContainsString("buildingEmptyText('Tidak ada data karyawan ditemukan')", $this->script);
        $this->assertStringContainsString("buildingEmptyText('Tidak ada data log yang sesuai dengan filter')", $this->script);
        foreach (['.scope-status[data-state="offline"]', '.scope-status[data-state="error"]', '.scope-status[data-state="legacy"]'] as $css) {
            $this->assertStringContainsString($css, $this->blade);
        }
    }

    public function test_stale_responses_are_dropped_everywhere(): void
    {
        $this->assertStringContainsString('if (seq !== doorsRequestSeq) return;', $this->body('async function loadDoors('));
        $this->assertStringContainsString('if (seq !== employeesRequestSeq) return;', $this->body('async function loadEmployees('));
        $this->assertStringContainsString('if (requestSeq !== accessLogsRequestSeq) return;', $this->body('async function loadAccessLogs('));
        $this->assertStringContainsString('if (seq !== metricsRequestSeq) return;', $this->body('async function updateMetricCards('));
    }

    public function test_storage_scroll_pagination_and_busy_state_are_preserved(): void
    {
        $this->assertStringContainsString('try { localStorage.setItem(BUILDING_FILTER_KEY', $this->body('function onDashboardBuildingChange('));
        $this->assertStringContainsString('try { state.buildingFilter = localStorage.getItem(BUILDING_FILTER_KEY)', $this->body('function restoreBuildingFilter('));

        $refresh = $this->body('function refreshDashboardScope(');
        $this->assertStringContainsString('const scrollY = window.scrollY;', $refresh);
        $this->assertStringContainsString('window.scrollTo({ top: scrollY })', $refresh);
        $this->assertStringContainsString('loadEmployees(1)', $refresh, 'a new building starts at page 1');

        // Old rows stay on screen (dimmed) while refreshing: no spinner collapse, so no jump to the top.
        $begin = $this->body('function beginLoad(');
        $this->assertStringContainsString("el.dataset.loaded === '1'", $begin);
        $this->assertStringContainsString("classList.add('is-busy')", $begin);
        $this->assertStringContainsString('.is-busy { opacity: 0.55; pointer-events: none;', $this->blade);
    }

    public function test_building_admin_gets_a_disabled_selector_and_never_a_filter(): void
    {
        $this->assertStringContainsString("role === 'building_admin'", $this->body('function isBuildingAdmin('));
        $this->assertStringContainsString('if (isBuildingAdmin())', $this->body('function restoreBuildingFilter('));
        $this->assertStringContainsString('if (isBuildingAdmin()) return;', $this->body('function onDashboardBuildingChange('));
        $this->assertStringContainsString('lockBuildingSelect(select, window.APP_CONFIG?.admin?.assigned_building', $this->body('async function loadDashboardBuildings('));
        $this->assertStringContainsString('select.disabled = true;', $this->body('function lockBuildingSelect('));
    }

    public function test_layout_is_responsive(): void
    {
        $this->assertMatchesRegularExpression('/@media \(max-width: 768px\) \{\s*\.top-bar-actions \.scope-select \{ flex: 1 1 100%; \}/', $this->blade);
        $this->assertStringContainsString('.scope-select { min-width: 13rem; max-width: 100%; }', $this->blade);
    }

    // ---------------------------------------------------------------- Part 2: API the selector already relies on

    private function seedTwoBuildings(): array
    {
        Sanctum::actingAs(Admin::create(['name' => 'Super', 'email' => 'super@example.test', 'password' => bcrypt('password'), 'role' => 'super_admin']));

        $a = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $b = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);

        $doorA = Door::create(['door_id' => 'DOOR-A', 'name' => 'A', 'location' => 'Gedung A', 'device_ip' => '192.168.90.11', 'connection_status' => 'online', 'building_id' => $a->id]);
        $doorB = Door::create(['door_id' => 'DOOR-B', 'name' => 'B', 'location' => 'Gedung B', 'device_ip' => '192.168.90.15', 'connection_status' => 'offline', 'building_id' => $b->id]);

        Employee::create(['employee_id' => 'EA1', 'nik' => 'N-EA1', 'name' => 'A1', 'department' => 'X', 'building_id' => $a->id, 'employment_status' => 'ACTIVE']);
        Employee::create(['employee_id' => 'EA2', 'nik' => 'N-EA2', 'name' => 'A2', 'department' => 'X', 'building_id' => $a->id, 'employment_status' => 'INACTIVE']);
        Employee::create(['employee_id' => 'EB1', 'nik' => 'N-EB1', 'name' => 'B1', 'department' => 'X', 'building_id' => $b->id, 'employment_status' => 'ACTIVE']);

        foreach ([['L1', $doorA, 'Denied'], ['L2', $doorA, 'Denied'], ['L3', $doorA, 'Granted'], ['L4', $doorB, 'Denied']] as [$id, $door, $status]) {
            AccessLog::create(['log_id' => $id, 'door_id' => $door->id, 'event_type' => 'STANDARD_TAP', 'verify_method' => 'Card', 'status' => $status, 'access_status' => $status, 'timestamp' => now()]);
        }

        return [$a, $b];
    }

    public function test_api_parameters_the_selector_relies_on_exist(): void
    {
        [$a, $b] = $this->seedTwoBuildings();

        $doors = collect($this->getJson('/api/v1/admin/doors')->assertOk()->json('data'))->keyBy('door_id');
        $this->assertSame($a->id, $doors['DOOR-A']['building_id']);
        $this->assertSame($b->id, $doors['DOOR-B']['building_id']);

        $this->assertSame(2, $this->getJson('/api/v1/user-management/employees?building_id=' . $a->id . '&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/user-management/employees?building_id=' . $a->id . '&employment_status=ACTIVE&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/user-management/employees?building_id=' . $b->id . '&per_page=1')->json('pagination.total_records'));

        $this->assertSame(2, $this->getJson('/api/v1/admin/access-logs?door_id=DOOR-A&status=Denied&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/access-logs?door_id=DOOR-B&status=Denied&per_page=1')->json('pagination.total_records'));
    }

    // ---------------------------------------------------------------- Part 3: contract for Ishak (self-activating)

    public function test_contract_dashboard_metrics_building_id(): void
    {
        [$a] = $this->seedTwoBuildings();

        $json = $this->getJson('/api/v1/admin/dashboard-metrics?building_id=' . $a->id)->assertOk()->json();
        if (($json['scope']['building_id'] ?? null) === null) {
            $this->markTestSkipped('Menunggu backend: GET /admin/dashboard-metrics?building_id= belum mengembalikan scope.building_id.');
        }

        $this->assertSame($a->id, $json['scope']['building_id']);
        $this->assertSame(2, $json['data']['totalUsers']);
        $this->assertSame(1, $json['data']['activeEmployees']);
        $this->assertSame(1, $json['data']['totalDoors']);
        $this->assertSame(1, $json['data']['activeDoors']);
        $this->assertSame(2, $json['data']['deniedLogs']);
    }

    public function test_contract_attendance_metrics_building_id(): void
    {
        [$a] = $this->seedTwoBuildings();

        $json = $this->getJson('/api/v1/attendance/metrics?building_id=' . $a->id)->assertOk()->json();
        if (($json['scope']['building_id'] ?? null) === null) {
            $this->markTestSkipped('Menunggu backend: GET /attendance/metrics?building_id= belum mengembalikan scope.building_id.');
        }

        $this->assertSame($a->id, $json['scope']['building_id']);
        $this->assertArrayHasKey('today', $json['data']);
    }

    public function test_contract_access_logs_building_id(): void
    {
        [$a] = $this->seedTwoBuildings();

        $json = $this->getJson('/api/v1/admin/access-logs?limit=40&building_id=' . $a->id)->assertOk()->json();
        if (($json['scope']['building_id'] ?? null) === null) {
            $this->markTestSkipped('Menunggu backend: GET /admin/access-logs?building_id= belum mengembalikan scope.building_id.');
        }

        $this->assertSame($a->id, $json['scope']['building_id']);
        $ids = collect($json['data'])->pluck('log_id')->sort()->values()->all();
        $this->assertSame(['L1', 'L2', 'L3'], $ids, 'only rows from doors of the requested building');
    }
}

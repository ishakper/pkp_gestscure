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
 * The Dashboard building selector (Semua Gedung / Gedung A-D) is frontend-only: it reuses filters the
 * API already has. These tests pin (1) the frontend wiring and (2) the API parameters it depends on,
 * so a backend change that drops one of them fails here instead of silently breaking the dashboard.
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

    public function test_selector_is_in_the_top_bar_and_wired_to_a_defined_handler(): void
    {
        $this->assertMatchesRegularExpression('/id="dashboardBuildingFilter"[^>]*onchange="onDashboardBuildingChange\(this\.value\)"/', $this->blade);

        foreach (['onDashboardBuildingChange', 'refreshDashboardScope', 'loadDashboardBuildings', 'restoreBuildingFilter', 'doorsInBuildingScope', 'buildingMetrics'] as $fn) {
            $this->assertMatchesRegularExpression('/function\s+' . $fn . '\s*\(/', $this->script, "{$fn}() must be defined");
        }
    }

    public function test_every_dashboard_section_honours_the_selected_building(): void
    {
        $this->assertStringContainsString('doorsInBuildingScope(state.allDoors)', $this->body('async function loadDoors('));
        $this->assertStringContainsString('&building_id=${encodeURIComponent(state.buildingFilter)}', $this->body('async function loadEmployees('));
        $this->assertStringContainsString('buildingMetrics(scope)', $this->body('async function updateMetricCards('));
        $this->assertStringContainsString('doorsInBuildingScope(state.allDoors)', $this->body('async function loadAccessLogs('));
    }

    public function test_selection_is_remembered_defensively_and_stale_metric_responses_are_dropped(): void
    {
        $this->assertStringContainsString('try { localStorage.setItem(BUILDING_FILTER_KEY', $this->body('function onDashboardBuildingChange('));
        $this->assertStringContainsString('if (scope !== state.buildingFilter) return;', $this->body('async function updateMetricCards('));
    }

    public function test_api_parameters_the_selector_relies_on_exist(): void
    {
        Sanctum::actingAs(Admin::create([
            'name' => 'Super', 'email' => 'super@example.test', 'password' => bcrypt('password'), 'role' => 'super_admin',
        ]));

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

        // Doors carry the building id the frontend filters on.
        $doors = collect($this->getJson('/api/v1/admin/doors')->assertOk()->json('data'))->keyBy('door_id');
        $this->assertSame($a->id, $doors['DOOR-A']['building_id']);
        $this->assertSame($b->id, $doors['DOOR-B']['building_id']);

        // Employees: building_id, employment_status and total_records for the KPI counters.
        $this->assertSame(2, $this->getJson('/api/v1/user-management/employees?building_id=' . $a->id . '&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/user-management/employees?building_id=' . $a->id . '&employment_status=ACTIVE&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/user-management/employees?building_id=' . $b->id . '&per_page=1')->json('pagination.total_records'));

        // Denied count per door drives "Akses Ditolak".
        $this->assertSame(2, $this->getJson('/api/v1/admin/access-logs?door_id=DOOR-A&status=Denied&per_page=1')->json('pagination.total_records'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/access-logs?door_id=DOOR-B&status=Denied&per_page=1')->json('pagination.total_records'));
    }
}

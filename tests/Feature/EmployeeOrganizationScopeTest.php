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
 * Production has 108 active employees and none has building_id / division_id /
 * position_id. With the global building filter on Gedung B the Pengguna table was
 * correctly empty, but "Total Pengguna" still showed the unfiltered total (total_all),
 * the KPI cards kept the global numbers for ~15 s, and the scoped KPIs used different
 * active/registered definitions than the global ones. The "+ Tambah Pengguna" form never
 * loaded its Gedung/Divisi/Posisi options at all.
 */
class EmployeeOrganizationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Building $gedungA;
    private Building $gedungB;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(Admin::factory()->create(['role' => 'super_admin']));
        $this->gedungA = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $this->gedungB = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);
    }

    private function script(): string
    {
        return file_get_contents(public_path('js/dashboard.js'));
    }

    private function bladeSource(): string
    {
        return file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    private function functionBody(string $name): string
    {
        $script = $this->script();
        $this->assertSame(1, preg_match('/\n(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $script, $m, PREG_OFFSET_CAPTURE), "function {$name}() is not defined");
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($script);

        return substr($script, $offset, $next - $offset);
    }

    // ---- backend: one KPI contract for global and building scope -------------------

    public function test_dashboard_metrics_building_scope_uses_the_global_definitions(): void
    {
        // Production shape: active employees with no organization mapping at all.
        Employee::factory()->count(5)->create(['building_id' => null, 'employment_status' => 'ACTIVE', 'source_person_number' => null]);
        Employee::factory()->create(['building_id' => null, 'employment_status' => 'ACTIVE', 'source_person_number' => 'SRC-1']);
        // Two mapped to Gedung A, one of them registered (source_person_number).
        Employee::factory()->create(['building_id' => $this->gedungA->id, 'employment_status' => 'ACTIVE', 'source_person_number' => 'SRC-2']);
        Employee::factory()->create(['building_id' => $this->gedungA->id, 'employment_status' => '', 'source_person_number' => null]);
        $doorA = Door::factory()->create(['building_id' => $this->gedungA->id]);
        $doorB = Door::factory()->create(['building_id' => $this->gedungB->id]);
        AccessLog::factory()->create(['door_id' => $doorB->id, 'access_status' => 'Denied']);

        $global = $this->getJson('/api/v1/admin/dashboard-metrics')->assertOk()->json('data');
        $this->assertSame([8, 8, 2], [$global['totalUsers'], $global['activeEmployees'], $global['registeredCredentials']]);

        $a = $this->getJson('/api/v1/admin/dashboard-metrics?building_id=' . $this->gedungA->id)->assertOk()->json('data');
        // Same definitions as global: empty employment_status counts as active, registered = source_person_number.
        $this->assertSame([2, 2, 1, 1, 0], [$a['totalUsers'], $a['activeEmployees'], $a['registeredCredentials'], $a['totalDoors'], $a['deniedLogs']]);

        $b = $this->getJson('/api/v1/admin/dashboard-metrics?building_id=' . $this->gedungB->id)->assertOk()->json('data');
        // Nobody is mapped to Gedung B: zero, not the global totals. Door assignment is not organization mapping.
        $this->assertSame([0, 0, 0, 1, 1], [$b['totalUsers'], $b['activeEmployees'], $b['registeredCredentials'], $b['totalDoors'], $b['deniedLogs']]);

        $this->getJson('/api/v1/admin/dashboard-metrics?building_id=999999')->assertStatus(422);
    }

    public function test_employee_list_total_records_is_scoped_while_total_all_is_not(): void
    {
        Employee::factory()->count(3)->create(['building_id' => null]);
        Employee::factory()->create(['building_id' => $this->gedungA->id]);

        $pagination = $this->getJson('/api/v1/user-management/employees?building_id=' . $this->gedungB->id)->assertOk()->json('pagination');

        $this->assertSame(0, $pagination['total_records']);
        $this->assertSame(4, $pagination['total_all'], 'total_all ignores filters, so the UI must not present it as the scoped total');
    }

    public function test_organization_lookup_returns_buildings_even_when_divisions_and_positions_are_empty(): void
    {
        $data = $this->getJson('/api/v1/user-management/organization/lookup')->assertOk()->json('data');

        $this->assertSame(['Gedung A', 'Gedung B'], array_column($data['buildings'], 'name'));
        $this->assertSame([], $data['divisions']);
        $this->assertSame([], $data['positions']);
    }

    // ---- frontend contracts -----------------------------------------------------------

    public function test_total_pengguna_shows_the_scoped_query_result(): void
    {
        $body = $this->functionBody('loadEmployees');

        $this->assertStringContainsString('const scopedCount = res.pagination?.total_records ?? state.employees.length;', $body);
        $this->assertStringNotContainsString('pagination?.total_all', $body);
        $this->assertStringContainsString('Total Pengguna di ${escapeHtml(scopeLabel)}', $body);
    }

    public function test_unmapped_building_gets_an_explicit_empty_state(): void
    {
        $this->assertStringContainsString('unmappedBuilding: Boolean(state.buildingFilter) && !searchVal && !doorFilter && state.employees.length === 0', $this->functionBody('loadEmployees'));
        $render = $this->functionBody('renderEmployeesTable');
        $this->assertStringContainsString("'Belum ada karyawan yang dipetakan ke gedung ini.'", $render);
        $this->assertStringContainsString("'Tidak ada data karyawan ditemukan.'", $render);
    }

    public function test_scoped_kpis_come_from_the_backend_contract_without_client_fallbacks(): void
    {
        $update = $this->functionBody('updateMetricCards');
        $this->assertStringContainsString('`/admin/dashboard-metrics?building_id=${encodeURIComponent(scope)}`', $update);
        $this->assertDoesNotMatchRegularExpression('/function\s+buildingMetrics\s*\(/', $this->script());
        $this->assertDoesNotMatchRegularExpression('/function\s+fetchEmployeesForBuilding\s*\(/', $this->script());
        // No organization fallback from the department text or from door assignments.
        $this->assertStringNotContainsString('department', $update);
        $this->assertStringNotContainsString('door_assign', $update);
    }

    public function test_building_change_does_not_leave_previous_scope_kpis_on_screen(): void
    {
        $scope = $this->functionBody('refreshDashboardScope');
        $this->assertStringContainsString("['metricActiveEmployees', 'metricRegisteredCredentials', 'metricDeniedLogs']", $scope);
        $this->assertStringContainsString("el.textContent = '…';", $scope);
        $this->assertStringContainsString('scheduleMetricCardsUpdate(true);', $scope);

        $scheduler = $this->functionBody('scheduleMetricCardsUpdate');
        $this->assertStringContainsString('if (metricsForcedPending && !force) return;', $scheduler);
        $this->assertStringContainsString('metricsForcedPending = force;', $scheduler);
        $this->assertStringContainsString('const cooldown = 15000;', $scheduler, 'The dfe4f53 cooldown stays');
    }

    public function test_employee_form_loads_and_cascades_organization_options(): void
    {
        $this->assertStringContainsString('loadEmployeeOrganizationOptions();', $this->functionBody('openAddEmployeeModal'));
        $this->assertStringContainsString('loadEmployeeOrganizationOptions({ building: emp.building?.id', $this->functionBody('openEditEmployeeModal'));
        $this->assertStringContainsString("apiFetch('/user-management/organization/lookup')", $this->functionBody('ensureOrganizationLookup'));

        $render = $this->functionBody('renderEmployeeOrganizationOptions');
        $this->assertStringContainsString('String(d.building_id) === buildingId', $render);
        $this->assertStringContainsString('String(p.division_id) === divisionId', $render);
        $this->assertStringContainsString("'Belum ada data divisi'", $render);
        $this->assertStringContainsString("'Belum ada data posisi'", $render);

        $this->assertStringContainsString('<select id="empBuilding" onchange="onEmployeeOrganizationChange()">', $this->bladeSource());
        $this->assertStringContainsString('<select id="empDivision" onchange="onEmployeeOrganizationChange()">', $this->bladeSource());
    }
}

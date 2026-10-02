<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * After a92f9b9 the attendance requests were coalesced, but live taps still reloaded
 * door cards and the access log on every event, returning to the dashboard reloaded
 * doors/employees/logs each time, and building-scoped KPIs sent two extra per_page=1
 * employee counts per update. With a building selected that reached 51-59 req/min
 * against the shared 60/min budget.
 *
 * These contracts pin the background-refresh scheduling that keeps it well below that
 * while user-initiated reloads stay immediate.
 */
class DashboardBackgroundRefreshTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function functionBody(string $name): string
    {
        $this->assertSame(
            1,
            preg_match('/\n(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $this->script, $m, PREG_OFFSET_CAPTURE),
            "function {$name}() is not defined in dashboard.js"
        );
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $this->script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($this->script);

        return substr($this->script, $offset, $next - $offset);
    }

    private function constant(string $name): int
    {
        $this->assertSame(1, preg_match('/const ' . $name . ' = (\d+);/', $this->script, $m), "{$name} is not defined");

        return (int) $m[1];
    }

    public function test_live_taps_refresh_doors_and_logs_through_the_scheduler(): void
    {
        $body = $this->functionBody('reconcileLiveData');

        $this->assertStringContainsString("scheduleBackgroundRefresh('doors', loadDoors, LIVE_DOORS_COOLDOWN_MS, { trailing: true })", $body);
        $this->assertStringContainsString("scheduleBackgroundRefresh('accessLogs', loadAccessLogs, LIVE_ACCESS_LOGS_COOLDOWN_MS, { trailing: true })", $body);
        $this->assertStringNotContainsString('loadDoors()', $body);
        $this->assertStringNotContainsString('loadAccessLogs()', $body);
        // dfe4f53 / a92f9b9 contracts stay intact.
        $this->assertStringContainsString('scheduleMetricCardsUpdate()', $body);
        $this->assertStringNotContainsString('updateMetricCards()', $body);
        $this->assertStringContainsString('scheduleAttendanceRefresh()', $body);

        $this->assertGreaterThanOrEqual(30000, $this->constant('LIVE_DOORS_COOLDOWN_MS'));
        $this->assertGreaterThanOrEqual(10000, $this->constant('LIVE_ACCESS_LOGS_COOLDOWN_MS'));
    }

    public function test_navigation_reuses_recently_loaded_data(): void
    {
        $body = $this->functionBody('switchTab');

        $this->assertStringContainsString("scheduleBackgroundRefresh('doors', loadDoors, NAVIGATION_FRESH_MS)", $body);
        $this->assertStringContainsString("scheduleBackgroundRefresh('employees', () => loadEmployees(), NAVIGATION_FRESH_MS)", $body);
        $this->assertStringContainsString("scheduleBackgroundRefresh('accessLogs', loadAccessLogs, NAVIGATION_FRESH_MS)", $body);
        $this->assertStringNotContainsString("|| tabId === 'overviewTab') loadDoors();", $body);
        $this->assertStringNotContainsString("|| tabId === 'overviewTab') loadEmployees();", $body);
        $this->assertStringNotContainsString("|| tabId === 'overviewTab') loadAccessLogs();", $body);
    }

    public function test_scheduler_skips_fresh_data_and_queues_at_most_one_trailing_run(): void
    {
        $body = $this->functionBody('scheduleBackgroundRefresh');

        $this->assertStringContainsString('if (entry.timer) return;', $body);
        $this->assertStringContainsString('cooldownMs - (Date.now() - entry.lastAt)', $body);
        $this->assertStringContainsString('if (!trailing) return;', $body);
        $this->assertStringContainsString('if (!document.hidden) loader();', $body);
    }

    public function test_every_scheduled_loader_records_its_own_runs(): void
    {
        // Direct calls (boot, saves, filter changes) count as refreshes, so a background
        // call right after them is not a duplicate.
        $this->assertStringContainsString("noteRefreshed('doors');", $this->functionBody('loadDoors'));
        $this->assertStringContainsString("noteRefreshed('accessLogs');", $this->functionBody('loadAccessLogs'));
        $this->assertStringContainsString("noteRefreshed('employees');", $this->functionBody('loadEmployees'));
    }

    public function test_user_actions_still_reload_immediately(): void
    {
        $this->assertStringContainsString('await loadDoors();', $this->functionBody('toggleDoorStatus'));
        $this->assertStringContainsString('await Promise.all([loadDoors(), loadAccessLogs()]);', $this->functionBody('refreshOperationalData'));
        $scope = $this->functionBody('refreshDashboardScope');
        $this->assertStringContainsString('loadEmployees(1);', $scope);
        $this->assertStringContainsString('loadAccessLogs();', $scope);
        $this->assertStringContainsString('scheduleMetricCardsUpdate(true);', $scope);
    }

    public function test_employee_pagination_contracts_are_preserved(): void
    {
        $body = $this->functionBody('loadEmployees');

        $this->assertStringContainsString('employeeRequestController.abort();', $body);
        $this->assertStringContainsString('employeeRequestController = new AbortController();', $body);
        $this->assertStringContainsString('const requestSequence = ++employeeRequestSequence;', $body);
        $this->assertStringContainsString('new URLSearchParams()', $body);
        $this->assertStringContainsString('requestAnimationFrame', $body);
    }

    public function test_building_scoped_kpis_do_not_send_employee_count_requests(): void
    {
        // Scoped KPIs are one backend request (dashboard-metrics?building_id); the client no
        // longer pages through employees or counts per door to recompute them.
        $body = $this->functionBody('updateMetricCards');

        $this->assertStringContainsString('/admin/dashboard-metrics?building_id=', $body);
        $this->assertStringNotContainsString('/user-management/employees', $body);
        $this->assertStringNotContainsString('buildingMetrics(', $this->script);
        $this->assertStringNotContainsString('fetchEmployeesForBuilding(', $this->script);
    }
}

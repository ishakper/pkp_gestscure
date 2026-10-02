<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Production 429 after 9b006ef: /attendance/metrics was fetched by two independent paths
 * (dashboard KPI strip and Rekap Kehadiran cards), and every live tap while Rekap Kehadiran
 * was open reloaded metrics + the monthly report + records. During a morning rush that
 * exhausted the shared 60/min API budget and unrelated endpoints started returning 429.
 *
 * These contracts pin the coalescing that keeps one user action (or one tap) from
 * fanning out into redundant attendance requests.
 */
class AttendanceRequestCoalescingTest extends TestCase
{
    private string $script;
    private string $blade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = file_get_contents(public_path('js/dashboard.js'));
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
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

    public function test_attendance_metrics_has_a_single_request_site(): void
    {
        $this->assertSame(1, substr_count($this->script, "apiFetch('/attendance/metrics'"), 'Only fetchAttendanceMetrics() may request /attendance/metrics');
        $this->assertStringContainsString("apiFetch('/attendance/metrics'", $this->functionBody('fetchAttendanceMetrics'));
    }

    public function test_shared_fetch_reuses_in_flight_and_recent_results(): void
    {
        $body = $this->functionBody('fetchAttendanceMetrics');

        $this->assertStringContainsString('if (attendanceMetricsCache.inflight) return attendanceMetricsCache.inflight;', $body);
        $this->assertStringContainsString('Date.now() - attendanceMetricsCache.at < ATTENDANCE_METRICS_TTL_MS', $body);
        // The cache must cover at least the metric scheduler cooldown, or the two panels drift apart again.
        preg_match('/const ATTENDANCE_METRICS_TTL_MS = (\d+);/', $this->script, $ttl);
        preg_match('/const cooldown = (\d+);/', $this->functionBody('scheduleMetricCardsUpdate'), $cooldown);
        $this->assertGreaterThanOrEqual((int) $cooldown[1], (int) $ttl[1]);
    }

    public function test_initial_dashboard_load_fetches_attendance_metrics_through_the_scheduler_only(): void
    {
        $this->assertStringContainsString('fetchAttendanceMetrics()', $this->functionBody('updateMetricCards'));
        $boot = substr($this->script, strpos($this->script, "document.addEventListener('DOMContentLoaded'"));
        $boot = substr($boot, 0, strpos($boot, "\n});") ?: strlen($boot));
        $this->assertStringNotContainsString('loadAttendance', $boot, 'Dashboard boot must not load attendance panels directly');
        $this->assertStringNotContainsString('/attendance/', $boot);
    }

    public function test_opening_rekap_kehadiran_shares_the_metrics_request(): void
    {
        $this->assertStringContainsString('fetchAttendanceMetrics({ force })', $this->functionBody('loadAttendanceMetrics'));
        // The dashboard update repaints the Rekap Kehadiran cards from the same response.
        $this->assertStringContainsString('renderAttendanceMetricsPanel(attendance)', $this->functionBody('updateMetricCards'));

        $load = $this->functionBody('loadAttendanceData');
        $this->assertStringContainsString('if (attendanceDataInflight) return attendanceDataInflight;', $load);
        $this->assertStringContainsString('loadAttendanceMetrics(force)', $load);
        // Only the explicit Refresh button forces past the shared cache.
        $this->assertStringContainsString('onclick="loadAttendanceData(true);', $this->blade);
        $this->assertSame(1, substr_count($this->script, 'loadAttendanceData()'), 'Only switchTab() opens the panel without forcing');
    }

    public function test_month_and_building_changes_reload_only_report_and_records_debounced(): void
    {
        $body = $this->functionBody('onAttendanceFilterChange');

        $this->assertStringContainsString('clearTimeout(attendanceFilterTimer)', $body);
        $this->assertStringContainsString('attendanceFilterTimer = setTimeout(', $body);
        $this->assertStringContainsString('loadAttendanceReport()', $body);
        $this->assertStringContainsString('loadAttendanceRecords()', $body);
        $this->assertStringNotContainsString('loadAttendanceMetrics(', $body, 'Filter changes do not refetch today\'s metrics');
        $this->assertStringNotContainsString('fetchAttendanceMetrics(', $body);
        $this->assertMatchesRegularExpression('/id="attendanceReportMonth"[^>]*onchange="onAttendanceFilterChange\(\)"/', $this->blade);
        $this->assertMatchesRegularExpression('/id="attendanceReportBuilding"[^>]*onchange="onAttendanceFilterChange\(\)"/', $this->blade);
    }

    public function test_realtime_reconciliation_goes_through_schedulers(): void
    {
        $body = $this->functionBody('reconcileLiveData');

        $this->assertStringContainsString('scheduleMetricCardsUpdate()', $body);
        $this->assertStringNotContainsString('updateMetricCards()', $body);
        $this->assertStringContainsString("if (state.activeTab === 'attendanceTab') scheduleAttendanceRefresh();", $body);
        $this->assertStringNotContainsString('loadAttendanceData', $body);
        $this->assertStringNotContainsString('loadAttendanceReport', $body);
    }

    public function test_live_attendance_refresh_is_queued_once_cooled_down_and_current_month_only(): void
    {
        $body = $this->functionBody('scheduleAttendanceRefresh');

        $this->assertStringContainsString('if (attendanceLiveRefresh.timer) return;', $body);
        $this->assertStringContainsString('ATTENDANCE_LIVE_COOLDOWN_MS - (Date.now() - attendanceLiveRefresh.lastAt)', $body);
        $this->assertStringContainsString("if (month && month !== currentMonthValue()) return;", $body);
        $this->assertStringContainsString("state.activeTab !== 'attendanceTab'", $body);
        preg_match('/const ATTENDANCE_LIVE_COOLDOWN_MS = (\d+);/', $this->script, $cooldown);
        $this->assertGreaterThanOrEqual(30000, (int) $cooldown[1]);
        // Live taps are already debounced before reconciling.
        $this->assertStringContainsString('realtime.refreshTimer = setTimeout(reconcileLiveData, 500);', $this->functionBody('handleNewLiveEvent'));
    }
}

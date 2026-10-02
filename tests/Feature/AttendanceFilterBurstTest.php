<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Production 61b9ff3: switching the Rekap Kehadiran building filter back and forth about
 * once a second refetched the monthly report and records for every change, even for a
 * building loaded a moment earlier (25 changes -> 50 requests in 24 s), and one records
 * request hit 429. Report and records are now reused per (month, building) query for a
 * short window, and a filter reload that still hits 429 retries after the cooldown.
 */
class AttendanceFilterBurstTest extends TestCase
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

    public function test_report_and_records_go_through_the_query_cache(): void
    {
        $this->assertStringContainsString('fetchAttendanceQuery(`/attendance/reports/monthly?${query}`, fresh)', $this->functionBody('loadAttendanceReport'));
        $this->assertStringContainsString('fetchAttendanceQuery(`/attendance/records?${query}`, fresh)', $this->functionBody('loadAttendanceRecords'));
        $this->assertSame(0, substr_count($this->script, 'apiFetch(`/attendance/reports/monthly'), 'Only fetchAttendanceQuery() may request the monthly report');
        $this->assertSame(0, substr_count($this->script, 'apiFetch(`/attendance/records'), 'Only fetchAttendanceQuery() may request attendance records');
    }

    public function test_query_cache_shares_in_flight_and_reuses_recent_results(): void
    {
        $body = $this->functionBody('fetchAttendanceQuery');

        $this->assertStringContainsString('if (hit?.promise) return hit.promise;', $body);
        $this->assertStringContainsString('if (!fresh && hit && Date.now() - hit.at < ATTENDANCE_QUERY_TTL_MS) return Promise.resolve(hit.data);', $body);
        $this->assertStringContainsString('attendanceQueryCache.delete(url);', $body, 'Failed requests must not be cached');
        $this->assertSame(1, preg_match('/const ATTENDANCE_QUERY_TTL_MS = (\d+);/', $this->script, $ttl));
        $this->assertGreaterThanOrEqual(15000, (int) $ttl[1]);
        $this->assertLessThanOrEqual(60000, (int) $ttl[1], 'Keep the window short so filters do not show stale data for long');
    }

    public function test_filter_changes_use_the_cache_while_refresh_and_live_updates_fetch_fresh(): void
    {
        $filter = $this->functionBody('onAttendanceFilterChange');
        $this->assertStringContainsString('loadAttendanceReport();', $filter);
        $this->assertStringContainsString('loadAttendanceRecords();', $filter);
        $this->assertStringContainsString('clearTimeout(attendanceFilterTimer)', $filter);

        $load = $this->functionBody('loadAttendanceData');
        $this->assertStringContainsString('loadAttendanceReport(force)', $load);
        $this->assertStringContainsString('loadAttendanceRecords(force)', $load);

        $live = $this->functionBody('scheduleAttendanceRefresh');
        $this->assertStringContainsString('loadAttendanceReport(true);', $live);
        $this->assertStringContainsString('loadAttendanceRecords(true);', $live);
    }

    public function test_rate_limited_filter_reload_retries_only_for_the_current_selection(): void
    {
        $retry = $this->functionBody('retryAttendanceAfterRateLimit');
        $this->assertStringContainsString('if (error?.status !== 429) return false;', $retry);
        $this->assertStringContainsString('rateLimitCooldownUntil - Date.now()', $retry);
        $this->assertStringContainsString('setTimeout(() => { if (stillCurrent()) reload(); }, waitMs);', $retry);

        $this->assertStringContainsString('retryAttendanceAfterRateLimit(error, tbody, 7, () => seq === attendanceReportSeq, () => loadAttendanceReport(fresh))', $this->functionBody('loadAttendanceReport'));
        $this->assertStringContainsString('retryAttendanceAfterRateLimit(e, tbody, 10, () => seq === attendanceRecordsSeq, () => loadAttendanceRecords(fresh))', $this->functionBody('loadAttendanceRecords'));
    }

    public function test_stale_response_guards_are_kept(): void
    {
        $report = $this->functionBody('loadAttendanceReport');
        $this->assertStringContainsString('const seq = ++attendanceReportSeq;', $report);
        $this->assertGreaterThanOrEqual(2, substr_count($report, 'if (seq !== attendanceReportSeq) return;'));

        $records = $this->functionBody('loadAttendanceRecords');
        $this->assertStringContainsString('const seq = ++attendanceRecordsSeq;', $records);
        $this->assertGreaterThanOrEqual(2, substr_count($records, 'if (seq !== attendanceRecordsSeq) return'));
    }
}

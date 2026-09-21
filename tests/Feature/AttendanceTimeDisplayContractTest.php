<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Rekap Kehadiran table used to slice the UTC ISO string returned by the API
 * (clock_in_at.substring(11, 16)), so every time showed 7 hours behind WIB and the date
 * showed as "2026-09-20T17:00:00.000000Z". Keep it on the timezone-aware helpers.
 */
class AttendanceTimeDisplayContractTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function loadAttendanceDataBody(): string
    {
        $this->assertSame(1, preg_match('/async function loadAttendanceData\(\)\s*\{/', $this->script, $m, PREG_OFFSET_CAPTURE));
        $start = $m[0][1];
        $end = strpos($this->script, 'async function loadAttendanceMetrics', $start);
        $this->assertNotFalse($end);

        return substr($this->script, $start, $end - $start);
    }

    public function test_table_uses_timezone_aware_helpers(): void
    {
        $body = $this->loadAttendanceDataBody();

        $this->assertStringContainsString('formatAttendanceDate(r.attendance_date)', $body);
        $this->assertStringContainsString('formatAttendanceTime(r.clock_in_at)', $body);
        $this->assertStringContainsString('formatAttendanceTime(r.clock_out_at)', $body);
        $this->assertStringNotContainsString('substring(11, 16)', $body, 'Slicing the UTC string shows UTC, not WIB.');
    }

    public function test_helpers_convert_to_jakarta_time(): void
    {
        $this->assertStringContainsString("const ATTENDANCE_TIMEZONE = 'Asia/Jakarta';", $this->script);
        $this->assertMatchesRegularExpression('/function formatAttendanceTime\(/', $this->script);
        $this->assertMatchesRegularExpression('/function formatAttendanceDate\(/', $this->script);
        $this->assertGreaterThanOrEqual(2, substr_count($this->script, 'timeZone: ATTENDANCE_TIMEZONE'));
    }

    public function test_column_headers_state_the_timezone(): void
    {
        $this->assertStringContainsString('Jam Masuk (WIB)', $this->blade);
        $this->assertStringContainsString('Jam Keluar (WIB)', $this->blade);
    }
}

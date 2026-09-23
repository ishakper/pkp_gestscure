<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contract for the "Laporan Kehadiran Bulanan" toolbar (month, building filter, CSV/PDF export).
 * The export previously failed silently: no loading state, a generic English toast without the server's
 * reason, and a detached anchor whose object URL was revoked immediately.
 */
class AttendanceReportUiContractTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function functionBody(string $name): string
    {
        $this->assertSame(
            1,
            preg_match('/(?:async\s+)?function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $this->script, $m, PREG_OFFSET_CAPTURE),
            "function {$name}() is not defined in dashboard.js"
        );
        $offset = $m[0][1];
        $next = preg_match('/\n(?:async\s+)?function\s+\w+\s*\(/', $this->script, $n, PREG_OFFSET_CAPTURE, $offset + 1) ? $n[0][1] : strlen($this->script);

        return substr($this->script, $offset, $next - $offset);
    }

    public function test_toolbar_wires_every_control_to_a_defined_function(): void
    {
        $this->assertStringContainsString('id="attendanceReportBuilding"', $this->blade);
        $this->assertStringContainsString('id="attendanceReportMonth"', $this->blade);
        $this->assertMatchesRegularExpression('/id="attendanceReportBuilding"[^>]*onchange="loadAttendanceReport\(\)"/', $this->blade);
        $this->assertStringContainsString('onclick="exportAttendanceReport(this)"', $this->blade);
        $this->assertStringContainsString('onclick="printAttendanceReport()"', $this->blade);

        foreach (['loadAttendanceReport', 'exportAttendanceReport', 'printAttendanceReport', 'ensureAttendanceReportBuildings'] as $fn) {
            $this->functionBody($fn);
        }
    }

    public function test_csv_export_reports_failures_instead_of_failing_silently(): void
    {
        $body = $this->functionBody('exportAttendanceReport');

        $this->assertStringContainsString('response.ok', $body);
        $this->assertStringContainsString("includes('csv')", $body, 'A non-CSV body must never be saved as the report.');
        $this->assertStringContainsString('catch (error)', $body);
        $this->assertStringContainsString('finally', $body);
    }

    public function test_download_helper_attaches_the_link_and_delays_revoke(): void
    {
        $body = $this->functionBody('downloadBlobAsFile');

        $this->assertStringContainsString('document.body.appendChild(link)', $body);
        $this->assertStringContainsString('setTimeout(() => URL.revokeObjectURL', $body);
    }

    public function test_default_month_is_not_derived_from_utc(): void
    {
        $body = $this->functionBody('loadAttendanceReport') . $this->functionBody('currentMonthValue');

        $this->assertStringNotContainsString('toISOString().slice(0, 7)', $body);
        $this->assertStringContainsString('getFullYear()', $body);
    }

    public function test_report_ignores_superseded_responses(): void
    {
        $body = $this->functionBody('loadAttendanceReport');

        $this->assertStringContainsString('++attendanceReportSeq', $body);
        $this->assertGreaterThanOrEqual(3, substr_count($body, 'seq !== attendanceReportSeq'));
    }

    public function test_pdf_print_escapes_user_data_and_uses_no_external_library(): void
    {
        $body = $this->functionBody('printAttendanceReport');

        $this->assertStringContainsString('escapeHtml(row.employee_name)', $body);
        $this->assertStringContainsString('escapeHtml(scope)', $body);
        $this->assertStringNotContainsString('<script src', $body);
        $this->assertStringContainsString('.print()', $body);
    }
}

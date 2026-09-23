<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Building;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for what the "Laporan Kehadiran Bulanan" panel relies on:
 * the building lookup that fills the "Semua Gedung" dropdown, the building_id
 * filter on the monthly report, and the CSV export honouring the same filter.
 */
class AttendanceReportBuildingFilterTest extends TestCase
{
    use RefreshDatabase;

    private Admin $viewer;
    private Building $gedungA;
    private Building $gedungB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = Admin::create([
            'name' => 'Management',
            'email' => 'management@example.test',
            'password' => bcrypt('password'),
            'role' => 'management',
        ]);

        $this->gedungA = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $this->gedungB = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);

        $this->attendanceFor('EMP-A', $this->gedungA, 'PRESENT');
        $this->attendanceFor('EMP-B', $this->gedungB, 'LATE', 9);
        $this->attendanceFor('EMP-NONE', null, 'PRESENT');
    }

    private function attendanceFor(string $code, ?Building $building, string $status, int $lateMinutes = 0): void
    {
        $employee = Employee::create([
            'employee_id' => $code,
            'nik' => 'NIK-' . $code,
            'name' => 'Employee ' . $code,
            'department' => 'Operations',
            'building_id' => $building?->id,
        ]);

        Attendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => '2026-09-02',
            'status' => $status,
            'late_minutes' => $lateMinutes,
        ]);
    }

    private function codes(array $rows): array
    {
        $codes = array_column($rows, 'employee_code');
        sort($codes);

        return $codes;
    }

    public function test_building_lookup_returns_the_shape_the_dropdown_needs(): void
    {
        $response = $this->actingAs($this->viewer)->getJson('/api/v1/user-management/organization/lookup');

        $response->assertOk()->assertJsonStructure(['data' => ['buildings' => [['id', 'name']]]]);
        $this->assertSame(['Gedung A', 'Gedung B'], array_column($response->json('data.buildings'), 'name'));
    }

    public function test_report_without_building_filter_includes_every_employee_even_unassigned_ones(): void
    {
        $response = $this->actingAs($this->viewer)->getJson('/api/v1/attendance/reports/monthly?month=2026-09');

        $response->assertOk()->assertJsonPath('data.totals.employees', 3);
        $this->assertSame(['EMP-A', 'EMP-B', 'EMP-NONE'], $this->codes($response->json('data.rows')));
    }

    public function test_report_building_filter_returns_only_that_building(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/reports/monthly?month=2026-09&building_id=' . $this->gedungB->id);

        $response->assertOk()
            ->assertJsonPath('data.totals.employees', 1)
            ->assertJsonPath('data.rows.0.employee_code', 'EMP-B')
            ->assertJsonPath('data.rows.0.building', 'Gedung B');
    }

    public function test_report_building_filter_with_no_matching_data_is_an_empty_report_not_an_error(): void
    {
        $empty = Building::create(['code' => 'BLD-C', 'name' => 'Gedung C', 'is_active' => true]);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/reports/monthly?month=2026-09&building_id=' . $empty->id)
            ->assertOk()
            ->assertJsonPath('data.totals.employees', 0)
            ->assertJsonPath('data.rows', []);
    }

    public function test_unknown_building_id_is_rejected(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/reports/monthly?month=2026-09&building_id=999999')
            ->assertStatus(422);
    }

    public function test_csv_export_honours_the_building_filter(): void
    {
        $response = $this->actingAs($this->viewer)
            ->get('/api/v1/attendance/reports/monthly/export?month=2026-09&building_id=' . $this->gedungA->id);

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('EMP-A', $csv);
        $this->assertStringNotContainsString('EMP-B', $csv);
        $this->assertStringNotContainsString('EMP-NONE', $csv);
    }

    public function test_csv_export_requires_authentication(): void
    {
        $this->getJson('/api/v1/attendance/reports/monthly/export?month=2026-09')->assertUnauthorized();
    }
}

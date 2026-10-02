<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Building;
use App\Models\Contract;
use App\Models\EmployeeCalendarAssignment;
use App\Models\Employee;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekap Kehadiran must describe the employee population, not just the employees
 * who happen to have attendance rows. Production showed Employees = 0 with 108
 * employees on file, Absent always 0 (no ABSENT rows are ever persisted) and a
 * processed September event while the summary looked at October.
 *
 * "Now" is fixed at Thursday 1 Oct 2026 09:00 WIB. September 2026 has 22 Mon-Fri days.
 */
class AttendanceMonthlyPopulationTest extends TestCase
{
    use RefreshDatabase;

    private const SEPTEMBER_WORKING_DAYS = 22;

    private Admin $viewer;
    private Building $gedungA;
    private Building $gedungB;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));

        $this->viewer = Admin::create([
            'name' => 'Management',
            'email' => 'management@example.test',
            'password' => bcrypt('password'),
            'role' => 'management',
        ]);
        $this->gedungA = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);
        $this->gedungB = Building::create(['code' => 'BLD-B', 'name' => 'Gedung B', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function employee(string $code, ?Building $building, array $extra = []): Employee
    {
        return Employee::create(array_merge([
            'employee_id' => $code,
            'nik' => 'NIK-' . $code,
            'name' => 'Employee ' . $code,
            'department' => 'Operations',
            'building_id' => $building?->id,
        ], $extra));
    }

    private function population(int $inA, int $inB): void
    {
        for ($i = 1; $i <= $inA; $i++) $this->employee("A{$i}", $this->gedungA);
        for ($i = 1; $i <= $inB; $i++) $this->employee("B{$i}", $this->gedungB);
    }

    /** Company-wide Mon-Fri 08:00-17:00 default calendar. */
    private function weekdayCalendar(): WorkCalendar
    {
        $calendar = WorkCalendar::create([
            'name' => 'Reguler', 'code' => 'REG', 'building_id' => null,
            'late_tolerance_minutes' => 15, 'is_default' => true, 'is_active' => true,
        ]);
        foreach (range(0, 6) as $dow) {
            WorkScheduleDay::create([
                'work_calendar_id' => $calendar->id,
                'day_of_week' => $dow,
                'is_working_day' => $dow >= 1 && $dow <= 5,
                'work_start' => '08:00:00',
                'work_end' => '17:00:00',
            ]);
        }
        return $calendar;
    }

    /** Attendance recording began on $date (somebody's first row), so earlier days are not absences. */
    private function trackingStartedOn(string $date): void
    {
        $marker = $this->employee('MARK', null, ['employment_status' => 'TERMINATED']);
        Attendance::create(['employee_id' => $marker->id, 'attendance_date' => $date, 'status' => 'PRESENT']);
    }

    private function report(string $query)
    {
        return $this->actingAs($this->viewer)->getJson('/api/v1/attendance/reports/monthly?' . $query)->assertOk();
    }

    public function test_employee_total_reflects_population_when_there_are_no_attendance_rows(): void
    {
        $this->population(60, 48);

        $totals = $this->report('month=2026-09')->json('data.totals');

        $this->assertSame(108, $totals['employees']);
        $this->assertSame(0, $totals['employees_with_attendance']);
        $this->assertSame(0, $totals['attendance_rows']);
    }

    public function test_empty_current_month_does_not_erase_the_employee_count(): void
    {
        $this->population(60, 48);
        $this->weekdayCalendar();
        Attendance::create(['employee_id' => Employee::first()->id, 'attendance_date' => '2026-09-22', 'status' => 'PRESENT']);

        $data = $this->report('month=2026-10')->json('data');

        $this->assertSame('2026-10', $data['period']['month']);
        $this->assertSame(108, $data['totals']['employees']);
        $this->assertSame(0, $data['totals']['attendance_rows']);
        // 1 Oct is still in progress, so nobody is absent yet.
        $this->assertSame(0, $data['totals']['absent']);
        $this->assertSame([], $data['rows']);
    }

    public function test_inactive_employees_are_not_part_of_the_population(): void
    {
        $this->population(3, 0);
        $this->employee('GONE', $this->gedungA, ['employment_status' => 'TERMINATED']);

        $this->assertSame(3, $this->report('month=2026-09')->json('data.totals.employees'));
    }

    public function test_historical_event_is_reported_in_its_own_month(): void
    {
        $fauzi = $this->employee('FZ', $this->gedungB, ['name' => 'Fauzi']);
        Attendance::create([
            'employee_id' => $fauzi->id, 'attendance_date' => '2026-09-22',
            'status' => 'LATE', 'late_minutes' => 640,
        ]);

        $september = $this->report('month=2026-09')->json('data');
        $this->assertSame(1, $september['totals']['late']);
        $this->assertSame('Fauzi', $september['rows'][0]['employee_name']);
        $this->assertSame(640, $september['rows'][0]['late_minutes']);

        $october = $this->report('month=2026-10')->json('data');
        $this->assertSame(0, $october['totals']['late']);
        $this->assertSame(1, $october['totals']['employees']);

        $records = $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/records?from=2026-09-01&to=2026-09-30')->assertOk()->json('data.data');
        $this->assertCount(1, $records);
        $this->assertCount(0, $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/records?from=2026-10-01&to=2026-10-31')->json('data.data'));
    }

    public function test_building_filter_scopes_population_and_attendance_consistently(): void
    {
        $this->population(60, 48);
        Attendance::create(['employee_id' => Employee::where('employee_id', 'A1')->value('id'), 'attendance_date' => '2026-09-02', 'status' => 'PRESENT']);
        Attendance::create(['employee_id' => Employee::where('employee_id', 'B1')->value('id'), 'attendance_date' => '2026-09-02', 'status' => 'LATE', 'late_minutes' => 5]);

        $a = $this->report('month=2026-09&building_id=' . $this->gedungA->id)->json('data');
        $this->assertSame(60, $a['totals']['employees']);
        $this->assertSame(1, $a['totals']['present']);
        $this->assertSame(0, $a['totals']['late']);
        $this->assertSame(['Gedung A'], array_values(array_unique(array_column($a['rows'], 'building'))));

        $b = $this->report('month=2026-09&building_id=' . $this->gedungB->id)->json('data');
        $this->assertSame(48, $b['totals']['employees']);
        $this->assertSame(0, $b['totals']['present']);
        $this->assertSame(1, $b['totals']['late']);

        $recordsB = $this->actingAs($this->viewer)
            ->getJson('/api/v1/attendance/records?from=2026-09-01&to=2026-09-30&building_id=' . $this->gedungB->id)
            ->json('data.data');
        $this->assertSame(['Employee B1'], array_column(array_column($recordsB, 'employee'), 'name'));
    }

    public function test_month_selector_drives_the_report_and_records_queries(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringContainsString("new URLSearchParams({ month: month.value })", $script);
        $this->assertStringContainsString("query.set('from', `\${month.value}-01`)", $script);
        $this->assertStringContainsString("query.set('building_id', building.value)", $script);
        $this->assertSame('2026-08', $this->report('month=2026-08')->json('data.period.month'));
        $this->assertSame('2026-08-31', $this->report('month=2026-08')->json('data.period.to'));
    }

    public function test_present_late_absent_include_working_days_without_a_row(): void
    {
        $this->weekdayCalendar();
        $budi = $this->employee('BUDI', $this->gedungA);
        $sari = $this->employee('SARI', $this->gedungA, ['hire_date' => '2026-01-05']);

        // Budi: 20 present, 1 late, 1 explicit ABSENT row = all 22 working days covered.
        $days = collect(range(1, 30))
            ->map(fn ($d) => Carbon::create(2026, 9, $d))
            ->filter(fn (Carbon $d) => $d->isWeekday())->values();
        foreach ($days as $i => $day) {
            Attendance::create([
                'employee_id' => $budi->id, 'attendance_date' => $day->toDateString(),
                'status' => $i < 20 ? 'PRESENT' : ($i === 20 ? 'LATE' : 'ABSENT'),
            ]);
        }
        // Sari was hired before September and has no rows: every working day is a derived absence.

        $data = $this->report('month=2026-09')->json('data');
        $rows = collect($data['rows'])->keyBy('employee_code');

        $this->assertSame([20, 1, 1], [$rows['BUDI']['present'], $rows['BUDI']['late'], $rows['BUDI']['absent']]);
        $this->assertSame(self::SEPTEMBER_WORKING_DAYS, $rows['SARI']['absent']);
        $this->assertSame(20, $data['totals']['present']);
        $this->assertSame(1, $data['totals']['late']);
        $this->assertSame(1 + self::SEPTEMBER_WORKING_DAYS, $data['totals']['absent']);
        $this->assertSame(self::SEPTEMBER_WORKING_DAYS, $data['totals']['absent_derived']);
    }

    public function test_derived_absences_skip_days_before_hire_and_approved_leave(): void
    {
        $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-01');
        $new = $this->employee('NEW', $this->gedungA, ['hire_date' => '2026-09-28']); // Mon 28 - Wed 30 = 3 days
        $leave = $this->employee('LEAVE', $this->gedungA, ['hire_date' => '2026-01-05']);
        AttendanceRequest::create([
            'employee_id' => $leave->id, 'request_type' => AttendanceRequest::TYPE_LEAVE,
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'reason' => 'Cuti',
            'status' => AttendanceRequest::STATUS_APPROVED,
        ]);

        $rows = collect($this->report('month=2026-09')->json('data.rows'))->keyBy('employee_code');

        $this->assertSame(3, $rows['NEW']['absent']);
        $this->assertArrayNotHasKey('LEAVE', $rows->all());
    }

    public function test_days_before_attendance_tracking_started_are_not_absences(): void
    {
        $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-21'); // go-live Mon 21: 21-25 + 28-30 = 8 working days
        $this->employee('LATE-GO-LIVE', $this->gedungA, ['hire_date' => '2026-01-05']);

        $data = $this->report('month=2026-09')->json('data');

        $this->assertSame('2026-09-21', $data['totals']['tracking_started_on']);
        $this->assertSame(8, collect($data['rows'])->firstWhere('employee_code', 'LATE-GO-LIVE')['absent']);
    }

    public function test_no_attendance_ever_recorded_means_no_derived_absences(): void
    {
        $this->weekdayCalendar();
        $this->population(5, 0);

        $totals = $this->report('month=2026-09')->json('data.totals');

        $this->assertSame(5, $totals['employees']);
        $this->assertSame(0, $totals['absent']);
        $this->assertNull($totals['tracking_started_on']);
    }

    /**
     * Production shape: 108 active employees, none with hire_date, a Mon-Fri calendar,
     * and sparse attendance from 11 Sep. Only employees with their own evidence of a
     * start date may accrue derived absences, and only from that date.
     */
    public function test_missing_hire_dates_do_not_fabricate_historical_absences(): void
    {
        $this->weekdayCalendar();
        $this->population(60, 48);
        $a1 = Employee::where('employee_id', 'A1')->first();
        $b7 = Employee::where('employee_id', 'B7')->first();
        // Sparse history: A1 first seen Fri 11 Sep, B7 first seen Tue 22 Sep.
        Attendance::create(['employee_id' => $a1->id, 'attendance_date' => '2026-09-11', 'status' => 'PRESENT']);
        Attendance::create(['employee_id' => $a1->id, 'attendance_date' => '2026-09-15', 'status' => 'LATE', 'late_minutes' => 20]);
        Attendance::create(['employee_id' => $b7->id, 'attendance_date' => '2026-09-22', 'status' => 'LATE', 'late_minutes' => 640]);

        $data = $this->report('month=2026-09')->json('data');
        $rows = collect($data['rows'])->keyBy('employee_code');

        // A1: 11-30 Sep = 14 working days, 2 recorded -> 12 derived, counted from first attendance.
        $this->assertSame(12, $rows['A1']['absent']);
        $this->assertSame('2026-09-11', $rows['A1']['absence_counted_from']);
        $this->assertSame('first_attendance', $rows['A1']['absence_start_source']);
        // B7: 22-30 Sep = 7 working days, 1 recorded -> 6 derived.
        $this->assertSame(6, $rows['B7']['absent']);
        // Nobody else has a defensible start date: no rows, no absences, not 108 x 14.
        $this->assertCount(2, $data['rows']);
        $this->assertSame(18, $data['totals']['absent']);
        $this->assertSame(108, $data['totals']['employees']);
        $this->assertSame(2, $data['totals']['employees_absence_evaluated']);
        $this->assertSame(106, $data['totals']['employees_without_start_date']);
    }

    public function test_employee_with_no_prior_attendance_gets_no_derived_absence(): void
    {
        $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-01');
        $this->employee('NEVER', $this->gedungA);

        $data = $this->report('month=2026-09')->json('data');

        $this->assertNull(collect($data['rows'])->firstWhere('employee_code', 'NEVER'));
        $this->assertSame(1, $data['totals']['employees_without_start_date']);
    }

    public function test_first_attendance_mid_month_bounds_absences_and_later_months(): void
    {
        $this->weekdayCalendar();
        $employee = $this->employee('MID', $this->gedungA);
        Attendance::create(['employee_id' => $employee->id, 'attendance_date' => '2026-09-24', 'status' => 'PRESENT']); // Thu

        $september = collect($this->report('month=2026-09')->json('data.rows'))->firstWhere('employee_code', 'MID');
        // 24, 25, 28, 29, 30 = 5 working days, 1 recorded.
        $this->assertSame(4, $september['absent']);
        $this->assertSame(1, $september['present']);
        // August predates the first attendance entirely.
        $this->assertSame([], $this->report('month=2026-08')->json('data.rows'));
    }

    public function test_calendar_assignment_effective_from_defines_the_start(): void
    {
        $calendar = $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-01');
        $assigned = $this->employee('ASG', $this->gedungA);
        EmployeeCalendarAssignment::create([
            'employee_id' => $assigned->id, 'work_calendar_id' => $calendar->id,
            'effective_from' => '2026-09-14', 'effective_until' => null,
        ]);
        // A later first attendance does not push the start back: the earliest evidence wins.
        Attendance::create(['employee_id' => $assigned->id, 'attendance_date' => '2026-09-21', 'status' => 'PRESENT']);

        $row = collect($this->report('month=2026-09')->json('data.rows'))->firstWhere('employee_code', 'ASG');

        // 14-30 Sep = 13 working days, 1 recorded.
        $this->assertSame(12, $row['absent']);
        $this->assertSame('2026-09-14', $row['absence_counted_from']);
        $this->assertSame('calendar_assignment', $row['absence_start_source']);
    }

    public function test_only_contracts_that_took_effect_define_the_start(): void
    {
        $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-01');
        $signed = $this->employee('SIGNED', $this->gedungA);
        $draft = $this->employee('DRAFT', $this->gedungA);
        Contract::create(['contract_number' => 'K-1', 'employee_id' => $signed->id, 'contract_type' => 'PKWT', 'title' => 'PKWT', 'start_date' => '2026-09-28', 'status' => 'ACTIVE']);
        Contract::create(['contract_number' => 'K-2', 'employee_id' => $draft->id, 'contract_type' => 'PKWT', 'title' => 'PKWT', 'start_date' => '2026-09-01', 'status' => 'DRAFT']);

        $rows = collect($this->report('month=2026-09')->json('data.rows'))->keyBy('employee_code');

        $this->assertSame(3, $rows['SIGNED']['absent']); // 28, 29, 30
        $this->assertSame('contract', $rows['SIGNED']['absence_start_source']);
        $this->assertArrayNotHasKey('DRAFT', $rows->all());
    }

    public function test_no_work_calendar_means_no_derived_absences(): void
    {
        $this->population(5, 0);

        $totals = $this->report('month=2026-09')->json('data.totals');

        $this->assertSame(5, $totals['employees']);
        $this->assertSame(0, $totals['absent']);
        $this->assertSame(0.0, (float) $totals['attendance_rate']);
    }

    public function test_attendance_rate_is_present_plus_late_over_scheduled_days(): void
    {
        $this->weekdayCalendar();
        $employee = $this->employee('RATE', $this->gedungA);
        // 3 present + 1 late + 1 explicit absent on 5 days, the other 17 working days have no row.
        foreach (['2026-09-01' => 'PRESENT', '2026-09-02' => 'PRESENT', '2026-09-03' => 'PRESENT', '2026-09-04' => 'LATE', '2026-09-07' => 'ABSENT', '2026-09-05' => 'OFF'] as $date => $status) {
            Attendance::create(['employee_id' => $employee->id, 'attendance_date' => $date, 'status' => $status]);
        }

        $row = $this->report('month=2026-09')->json('data.rows.0');

        // absent = 1 row + 17 derived; OFF is not in the denominator.
        $this->assertSame(18, $row['absent']);
        $this->assertEquals(round(4 / 22 * 100, 1), $row['attendance_rate']);
        $this->assertEquals(round(4 / 22 * 100, 1), $this->report('month=2026-09')->json('data.totals.attendance_rate'));
    }

    public function test_csv_export_uses_the_same_rows_as_the_report(): void
    {
        $this->weekdayCalendar();
        $this->trackingStartedOn('2026-09-01');
        $this->employee('CSV', $this->gedungA, ['hire_date' => '2026-01-05']);

        $csv = $this->actingAs($this->viewer)->get('/api/v1/attendance/reports/monthly/export?month=2026-09')->assertOk()->streamedContent();

        $this->assertStringContainsString('CSV,"Employee CSV","Gedung A",0,0,' . self::SEPTEMBER_WORKING_DAYS . ',0%,0', $csv);
    }

    public function test_live_office_attendance_counts_people_in_the_office_today(): void
    {
        $inside = $this->employee('IN', $this->gedungA);
        $left = $this->employee('OUT', $this->gedungA);
        $this->employee('NOTYET', $this->gedungA);
        Attendance::create(['employee_id' => $inside->id, 'attendance_date' => '2026-10-01', 'status' => 'PRESENT', 'clock_in_at' => '2026-10-01 07:55:00']);
        Attendance::create(['employee_id' => $left->id, 'attendance_date' => '2026-10-01', 'status' => 'PRESENT', 'clock_in_at' => '2026-10-01 07:00:00', 'clock_out_at' => '2026-10-01 08:30:00']);
        Attendance::create(['employee_id' => $left->id, 'attendance_date' => '2026-09-30', 'status' => 'PRESENT', 'clock_in_at' => '2026-09-30 08:00:00']);

        $live = $this->actingAs($this->viewer)->getJson('/api/v1/attendance/metrics')->assertOk()->json('data.live');

        $this->assertSame(1, $live['in_office_now']);
        $this->assertSame(2, $live['checked_in_today']);
        $this->assertSame(3, $live['active_employees']);
    }
}

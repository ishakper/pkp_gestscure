<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documents how the backend applies the office hours (masuk 08:00 dengan toleransi 30 menit sampai 08:30,
 * pulang 17:00) so the Rekap Kehadiran table can be read against a known rule.
 * Only reads the existing AttendanceProcessor; it does not change it.
 */
class AttendanceWorkHoursRuleTest extends TestCase
{
    use RefreshDatabase;

    private function calendar(int $toleranceMinutes): WorkCalendar
    {
        $calendar = WorkCalendar::create([
            'name' => 'PKP Default Office Calendar',
            'code' => 'PKP_DEFAULT',
            'late_tolerance_minutes' => $toleranceMinutes,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (range(0, 6) as $day) {
            $working = $day >= 1 && $day <= 5;
            WorkScheduleDay::create([
                'work_calendar_id' => $calendar->id,
                'day_of_week' => $day,
                'is_working_day' => $working,
                'work_start' => $working ? '08:00:00' : null,
                'work_end' => $working ? '17:00:00' : null,
            ]);
        }

        return $calendar->load('scheduleDays');
    }

    private function computeFor(WorkCalendar $calendar, string $clockIn, ?string $clockOut = null): array
    {
        $date = Carbon::parse('2026-09-21'); // Monday
        $employee = Employee::create([
            'employee_id' => 'EMP-' . uniqid(),
            'nik' => 'NIK-' . uniqid(),
            'name' => 'Employee',
            'department' => 'Ops',
        ]);

        return app(AttendanceProcessor::class)->computeStatus(
            Carbon::parse("2026-09-21 {$clockIn}"),
            $clockOut ? Carbon::parse("2026-09-21 {$clockOut}") : null,
            $calendar,
            $date,
            $employee
        );
    }

    public function test_arrival_until_0830_is_on_time_with_a_30_minute_tolerance(): void
    {
        $calendar = $this->calendar(30);

        foreach (['07:01:00', '07:48:00', '08:00:00', '08:28:00', '08:30:00'] as $time) {
            $result = $this->computeFor($calendar, $time);
            $this->assertSame('PRESENT', $result['status'], "Masuk {$time} seharusnya Hadir");
            $this->assertSame(0, $result['late_minutes']);
        }
    }

    public function test_arrival_after_0830_is_late_and_minutes_are_counted_from_0800(): void
    {
        $calendar = $this->calendar(30);

        $result = $this->computeFor($calendar, '08:34:00');
        $this->assertSame('LATE', $result['status']);
        $this->assertSame(34, $result['late_minutes']);

        $result = $this->computeFor($calendar, '09:55:00');
        $this->assertSame('LATE', $result['status']);
        $this->assertSame(115, $result['late_minutes']);
    }

    public function test_tolerance_comes_from_the_calendar_not_from_code(): void
    {
        // A calendar configured with no tolerance flags 08:28 as late (+28), which is what the
        // Rekap Kehadiran screenshot shows. The rule is data, not code.
        $strict = $this->computeFor($this->calendar(0), '08:28:00');
        $this->assertSame('LATE', $strict['status']);
        $this->assertSame(28, $strict['late_minutes']);
    }

    public function test_leaving_before_1700_counts_early_leave(): void
    {
        $calendar = $this->calendar(30);

        $this->assertSame(0, $this->computeFor($calendar, '08:00:00', '17:00:00')['early_leave_minutes']);
        $this->assertSame(30, $this->computeFor($calendar, '08:00:00', '16:30:00')['early_leave_minutes']);
    }
}

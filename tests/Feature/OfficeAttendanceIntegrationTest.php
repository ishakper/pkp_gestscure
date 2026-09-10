<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Attendance;
use App\Models\AttendanceEvidence;
use App\Models\Door;
use App\Models\Employee;
use App\Models\WorkCalendar;
use App\Models\WorkScheduleDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OfficeAttendanceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $calendar = WorkCalendar::create([
            'name' => 'Standard 08-17', 'code' => 'STD_0817',
            'late_tolerance_minutes' => 30, 'is_default' => true,
            'building_id' => null, 'is_active' => true,
        ]);
        for ($day = 1; $day <= 5; $day++) {
            WorkScheduleDay::create([
                'work_calendar_id' => $calendar->id, 'day_of_week' => $day,
                'is_working_day' => true, 'work_start' => '08:00:00', 'work_end' => '17:00:00',
            ]);
        }
        Door::create(['door_id' => 'DOOR-TEST', 'name' => 'Main Door', 'device_ip' => '192.168.1.100', 'location' => 'Main Entrance']);
    }

    private function employee(): Employee
    {
        return Employee::create([
            'nik' => 'NIK12345', 'name' => 'John Doe', 'employee_id' => 'EMP-001',
            'email' => 'john@test.com', 'status' => 'ACTIVE', 'department' => 'IT', 'position' => 'Staff',
        ]);
    }

    private function tap(Carbon $at, string $direction = 'ENTRY', string $serial = 'SN-001', ?string $employeeNo = 'EMP-001'): void
    {
        $event = ['majorEventType' => 5, 'subEventType' => 75, 'serialNo' => $serial, 'direction' => $direction];
        if ($employeeNo !== null) $event['employeeNoString'] = $employeeNo;

        $this->postJson('/api/v1/isapi/event-notification', [
            'AccessControllerEvent' => $event,
            'dateTime' => $at->toIso8601String(), 'ipAddress' => '192.168.1.100',
        ])->assertOk();
    }

    /** @test */
    public function it_persists_one_granted_entry_through_the_full_attendance_pipeline(): void
    {
        $employee = $this->employee();
        $time = Carbon::parse('2026-09-14 08:00:00');
        $this->tap($time, 'ENTRY', 'ENTRY-001');

        $this->assertDatabaseCount('access_logs', 1);
        $accessLog = AccessLog::sole();
        $this->assertDatabaseCount('attendance_evidences', 1);
        $this->assertDatabaseHas('attendance_evidences', [
            'access_log_id' => $accessLog->id, 'employee_id' => $employee->id,
            'direction' => 'ENTRY', 'status' => 'MAPPED',
        ]);
        $this->assertDatabaseCount('attendances', 1);
        $attendance = Attendance::sole();
        $this->assertDatabaseHas('attendance_evidences', [
            'access_log_id' => $accessLog->id,
            'attendance_id' => $attendance->id,
        ]);
        $this->assertSame('2026-09-14', $attendance->attendance_date->toDateString());
        $this->assertSame('PRESENT', $attendance->status);
        $this->assertSame($time->toDateTimeString(), $attendance->clock_in_at->toDateTimeString());
        $this->assertNull($attendance->clock_out_at);
        $this->assertSame($accessLog->id, $attendance->access_log_in_id);
    }

    /** @test */
    public function it_uses_device_direction_and_keeps_one_daily_attendance(): void
    {
        $this->employee();
        $this->tap(Carbon::parse('2026-09-14 08:00:00'), 'ENTRY', 'DAY-01');
        $this->tap(Carbon::parse('2026-09-14 12:00:00'), 'EXIT', 'DAY-02');
        $this->tap(Carbon::parse('2026-09-14 13:00:00'), 'ENTRY', 'DAY-03');
        $this->tap(Carbon::parse('2026-09-14 17:05:00'), 'EXIT', 'DAY-04');

        $this->assertDatabaseCount('access_logs', 4);
        $this->assertDatabaseCount('attendance_evidences', 4);
        $this->assertDatabaseCount('attendances', 1);
        $attendance = Attendance::sole();
        $this->assertSame('2026-09-14 08:00:00', $attendance->clock_in_at->toDateTimeString());
        $this->assertSame('2026-09-14 17:05:00', $attendance->clock_out_at->toDateTimeString());
    }

    /** @test */
    public function it_never_turns_unknown_direction_into_checkout(): void
    {
        $this->employee();
        $this->tap(Carbon::parse('2026-09-14 08:00:00'), 'UNKNOWN', 'UNKNOWN-01');
        $this->tap(Carbon::parse('2026-09-14 17:05:00'), 'UNKNOWN', 'UNKNOWN-02');

        $this->assertDatabaseCount('attendance_evidences', 2);
        $this->assertDatabaseCount('attendances', 1);
        $this->assertNull(Attendance::sole()->clock_out_at);
    }

    /** @test */
    public function it_keeps_denied_and_unmapped_events_as_access_logs_only(): void
    {
        $this->tap(Carbon::parse('2026-09-14 08:00:00'), 'ENTRY', 'DENIED-01', null);
        $this->tap(Carbon::parse('2026-09-14 08:05:00'), 'ENTRY', 'UNMAPPED-01', 'UNKNOWN-EMPLOYEE');

        $this->assertDatabaseCount('access_logs', 2);
        $this->assertDatabaseCount('attendance_evidences', 0);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('access_logs', ['access_status' => 'Denied']);
    }

    /** @test */
    public function it_deduplicates_a_repeated_hardware_serial(): void
    {
        $this->employee();
        $at = Carbon::parse('2026-09-14 08:00:00');
        $this->tap($at, 'ENTRY', 'REPEAT-001');
        $this->tap($at, 'ENTRY', 'REPEAT-001');

        $this->assertDatabaseCount('access_logs', 1);
        $this->assertDatabaseCount('attendance_evidences', 1);
        $this->assertDatabaseCount('attendances', 1);
    }

    /** @test */
    public function it_reuses_the_attendance_processor_lateness_policy(): void
    {
        $this->employee();
        $this->tap(Carbon::parse('2026-09-14 08:30:00'), 'ENTRY', 'PRESENT-0830');
        $this->assertSame('PRESENT', Attendance::sole()->status);

        Attendance::query()->delete();
        AccessLog::query()->delete();
        AttendanceEvidence::query()->delete();

        $this->tap(Carbon::parse('2026-09-14 08:31:00'), 'ENTRY', 'LATE-0831');
        $this->assertSame('LATE', Attendance::sole()->status);
    }

    /** @test */
    public function it_does_not_mutate_the_access_log_while_deriving_attendance(): void
    {
        $this->employee();
        $at = Carbon::parse('2026-09-14 08:00:00');
        $this->tap($at, 'ENTRY', 'IMMUTABLE-001');

        $accessLog = AccessLog::sole()->fresh();
        $this->assertSame('STANDARD_TAP', $accessLog->event_type);
        $this->assertSame('Granted', $accessLog->access_status);
        $this->assertSame('IMMUTABLE-001', $accessLog->device_serial);
        $this->assertSame($at->toDateTimeString(), $accessLog->timestamp->toDateTimeString());
        $this->assertDatabaseCount('attendance_evidences', 1);
        $this->assertDatabaseCount('attendances', 1);
    }}

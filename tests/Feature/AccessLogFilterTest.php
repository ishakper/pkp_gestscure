<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceEvidence;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the filter bar on the "Aktivitas Terakhir" / Log Akses panel.
 * Every filter the dashboard sends (door_id, status, attendance_state, user, start_date,
 * end_date) is exercised alone and in combination against /admin/access-logs.
 */
class AccessLogFilterTest extends TestCase
{
    use RefreshDatabase;

    private Door $doorA;
    private Door $doorB;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]));

        $this->doorA = Door::create([
            'door_id' => 'DOOR-A',
            'name' => 'Door A',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'connection_status' => 'online',
        ]);
        $this->doorB = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'connection_status' => 'online',
        ]);

        $budi = Employee::create([
            'employee_id' => 'USR-100',
            'nik' => 'NIK-100',
            'name' => 'Budi Santoso',
            'department' => 'IT',
        ]);
        $siti = Employee::create([
            'employee_id' => 'USR-200',
            'nik' => 'NIK-200',
            'name' => 'Siti Rahma',
            'department' => 'HR',
        ]);

        $l1 = $this->log('L1', $this->doorA, 'Granted', 'STANDARD_TAP', '2026-09-10 09:00:00', $budi);
        $l2 = $this->log('L2', $this->doorB, 'Granted', 'STANDARD_TAP', '2026-09-11 23:59:30', $siti);
        $this->log('L3', $this->doorB, 'Denied', 'STANDARD_TAP', '2026-09-12 08:00:00');
        $this->log('L4', $this->doorB, 'Alarm', 'DOOR_FORCED_OPEN', '2026-09-12 10:00:00');
        $this->log('L5', $this->doorA, 'Duress', 'DURESS_FINGERPRINT', '2026-09-13 07:00:00', $budi);

        $this->evidence($l1, $budi, 'PRESENT');
        $this->evidence($l2, $siti, 'LATE');
    }

    private function log(string $id, Door $door, string $status, string $eventType, string $at, ?Employee $employee = null): AccessLog
    {
        return AccessLog::create([
            'log_id' => $id,
            'door_id' => $door->id,
            'employee_id' => $employee?->id,
            'nik' => $employee?->nik,
            'event_type' => $eventType,
            'verify_method' => 'Card',
            'status' => $status,
            'access_status' => $status,
            'timestamp' => $at,
        ]);
    }

    private function evidence(AccessLog $log, Employee $employee, string $result): void
    {
        $attendance = Attendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => $log->timestamp->toDateString(),
            'status' => $result,
        ]);

        AttendanceEvidence::create([
            'access_log_id' => $log->id,
            'attendance_id' => $attendance->id,
            'employee_id' => $employee->id,
            'door_id' => $log->door_id,
            'event_timestamp' => $log->timestamp,
            'direction' => 'ENTRY',
            'credential_type' => 'Card',
            'status' => 'ACCEPTED',
        ]);
    }

    /** @return array<int, string> sorted log ids returned by the endpoint */
    private function ids(array $query = []): array
    {
        $response = $this->getJson('/api/v1/admin/access-logs?' . http_build_query($query + ['limit' => 40]));
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('log_id')->sort()->values()->all();

        return $ids;
    }

    public function test_no_filter_returns_everything(): void
    {
        $this->assertSame(['L1', 'L2', 'L3', 'L4', 'L5'], $this->ids());
    }

    public function test_door_filter(): void
    {
        $this->assertSame(['L2', 'L3', 'L4'], $this->ids(['door_id' => 'DOOR-B']));
        $this->assertSame(['L1', 'L5'], $this->ids(['door_id' => 'DOOR-A']));
    }

    public function test_status_and_alarm_filter(): void
    {
        $this->assertSame(['L1', 'L2'], $this->ids(['status' => 'Granted']));
        $this->assertSame(['L3'], $this->ids(['status' => 'Denied']));
        $this->assertSame(['L4'], $this->ids(['status' => 'Alarm']));
        $this->assertSame(['L5'], $this->ids(['status' => 'Duress']));
    }

    public function test_attendance_state_filter(): void
    {
        $this->assertSame(['L1'], $this->ids(['attendance_state' => 'PRESENT']));
        $this->assertSame(['L2'], $this->ids(['attendance_state' => 'LATE']));
        $this->assertSame(['L3'], $this->ids(['attendance_state' => 'Ditolak']));
        $this->assertSame(['L4', 'L5'], $this->ids(['attendance_state' => 'Belum diproses']));
    }

    public function test_invalid_attendance_state_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/access-logs?attendance_state=BOGUS')->assertStatus(422);
    }

    public function test_user_search_by_nik_and_name_is_case_insensitive(): void
    {
        $this->assertSame(['L2'], $this->ids(['user' => 'NIK-200']));
        $this->assertSame(['L1', 'L5'], $this->ids(['user' => 'budi']));
        $this->assertSame(['L2'], $this->ids(['user' => 'SITI']));
        $this->assertSame([], $this->ids(['user' => 'tidak-ada']));
    }

    public function test_user_search_treats_like_wildcards_literally(): void
    {
        $this->assertSame([], $this->ids(['user' => '%']));
        $this->assertSame([], $this->ids(['user' => '_']));
    }

    public function test_date_range_filter_is_inclusive_of_the_whole_end_day(): void
    {
        $this->assertSame(['L3', 'L4', 'L5'], $this->ids(['start_date' => '2026-09-12']));
        $this->assertSame(['L1', 'L2'], $this->ids(['end_date' => '2026-09-11']));
        $this->assertSame(['L2', 'L3', 'L4'], $this->ids(['start_date' => '2026-09-11', 'end_date' => '2026-09-12']));
    }

    public function test_filters_combine_with_and_logic(): void
    {
        $this->assertSame(['L3'], $this->ids(['door_id' => 'DOOR-B', 'status' => 'Denied']));
        $this->assertSame(['L2'], $this->ids(['door_id' => 'DOOR-B', 'attendance_state' => 'LATE', 'user' => 'siti']));
        $this->assertSame([], $this->ids(['door_id' => 'DOOR-A', 'status' => 'Denied']));
    }
}

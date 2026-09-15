<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Building;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceReportingAndFacilityConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_gets_monthly_summary_scoped_to_assigned_building(): void
    {
        [$buildingA, $employeeA] = $this->employeeIn('BLD-A', 'Gedung A', 'EMP-A');
        [, $employeeB] = $this->employeeIn('BLD-B', 'Gedung B', 'EMP-B');
        $manager = $this->admin('management', 'Gedung A');

        $this->attendance($employeeA, '2026-09-01', 'PRESENT');
        $this->attendance($employeeA, '2026-09-02', 'LATE', 12);
        $this->attendance($employeeA, '2026-09-03', 'ABSENT');
        $this->attendance($employeeB, '2026-09-01', 'PRESENT');

        $response = $this->actingAs($manager)->getJson('/api/v1/attendance/reports/monthly?month=2026-09');

        $response->assertOk()
            ->assertJsonPath('data.totals.employees', 1)
            ->assertJsonPath('data.totals.present', 1)
            ->assertJsonPath('data.totals.late', 1)
            ->assertJsonPath('data.totals.absent', 1)
            ->assertJsonPath('data.rows.0.employee_code', 'EMP-A')
            ->assertJsonPath('data.rows.0.building', $buildingA->name)
            ->assertJsonPath('data.rows.0.attendance_rate', 66.7);
    }

    public function test_monthly_csv_export_is_filtered_and_formula_safe(): void
    {
        [$building, $employee] = $this->employeeIn('BLD-A', 'Gedung A', '=EMP-CSV');
        $this->attendance($employee, '2026-09-01', 'PRESENT');

        $response = $this->actingAs($this->admin('management', $building->name))
            ->get('/api/v1/attendance/reports/monthly/export?month=2026-09');

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Employee ID', $content);
        $this->assertStringContainsString('Employee,Building,Present,Late,Absent', $content);
        $this->assertStringContainsString("'=EMP-CSV", $content);
    }

    public function test_infra_can_register_building_and_door_without_mutating_existing_door_b(): void
    {
        $existing = Door::create([
            'door_id' => 'DOOR-B', 'name' => 'Stable Door B', 'location' => 'Gedung Lama',
            'device_ip' => '192.168.90.12', 'device_model' => 'DS-K1T804AMF',
            'status' => 'online', 'connection_status' => 'online',
        ]);
        $infra = $this->admin('infra_admin');

        $buildingResponse = $this->actingAs($infra)->postJson('/api/v1/admin/buildings', [
            'code' => 'bld-new', 'name' => 'Gedung Baru', 'description' => 'Expansion site',
        ])->assertCreated()->assertJsonPath('data.code', 'BLD-NEW');

        $buildingId = $buildingResponse->json('data.id');
        $this->actingAs($infra)->postJson('/api/v1/admin/zones', [
            'building_id' => $buildingId, 'code' => 'zn-new', 'name' => 'Zona Lobby Utama',
        ])->assertCreated()->assertJsonPath('data.code', 'ZN-NEW');

        $this->actingAs($infra)->postJson('/api/v1/admin/doors', [
            'door_id' => 'door-c', 'name' => 'Lobby Baru', 'building_id' => $buildingId,
            'device_ip' => '192.168.90.13', 'gateway' => '192.168.90.1', 'device_model' => 'DS-K1T804AMF',
        ])->assertCreated()
            ->assertJsonPath('data.door_id', 'DOOR-C')
            ->assertJsonPath('data.building_name', 'Gedung Baru')
            ->assertJsonPath('data.connection_status', 'offline');

        $this->assertDatabaseHas('doors', ['door_id' => 'DOOR-B', 'id' => $existing->id, 'device_ip' => '192.168.90.12', 'connection_status' => 'online']);
    }

    public function test_management_cannot_change_facility_configuration(): void
    {
        $manager = $this->admin('management');
        $this->actingAs($manager)->postJson('/api/v1/admin/buildings', ['code' => 'NO', 'name' => 'Denied'])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/admin/zones', ['building_id' => 1, 'code' => 'NO', 'name' => 'Denied'])->assertForbidden();
        $this->actingAs($manager)->postJson('/api/v1/admin/doors', [])->assertForbidden();
    }

    public function test_zone_code_is_normalized_before_duplicate_validation(): void
    {
        $infra = $this->admin('infra_admin');
        $building = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A', 'is_active' => true]);

        $this->actingAs($infra)->postJson('/api/v1/admin/zones', [
            'building_id' => $building->id, 'code' => 'zn-main', 'name' => 'Zona Utama',
        ])->assertCreated();

        $this->actingAs($infra)->postJson('/api/v1/admin/zones', [
            'building_id' => $building->id, 'code' => 'ZN-MAIN', 'name' => 'Duplikat',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_access_log_rejects_unknown_attendance_state(): void
    {
        $this->actingAs($this->admin('super_admin'))
            ->getJson('/api/v1/admin/access-logs?attendance_state=UNKNOWN')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance_state');
    }

    private function admin(string $role, ?string $building = null): Admin
    {
        return Admin::create(['name' => $role, 'email' => $role.uniqid().'@example.test', 'password' => bcrypt('password'), 'role' => $role, 'assigned_building' => $building]);
    }

    private function employeeIn(string $buildingCode, string $buildingName, string $employeeCode): array
    {
        $building = Building::create(['code' => $buildingCode, 'name' => $buildingName, 'is_active' => true]);
        $employee = Employee::create(['employee_id' => $employeeCode, 'nik' => 'NIK-'.uniqid(), 'name' => 'Employee '.$employeeCode, 'department' => 'Operations', 'building_id' => $building->id]);
        return [$building, $employee];
    }

    private function attendance(Employee $employee, string $date, string $status, int $lateMinutes = 0): Attendance
    {
        return Attendance::create(['employee_id' => $employee->id, 'attendance_date' => $date, 'status' => $status, 'late_minutes' => $lateMinutes]);
    }
}

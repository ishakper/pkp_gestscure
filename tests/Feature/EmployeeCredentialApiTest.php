<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeCredentialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_endpoint_exposes_credential_and_access_mapping_fields(): void
    {
        Sanctum::actingAs(Admin::create([
            'name' => 'API Admin',
            'email' => 'employee-api@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]));

        Employee::factory()->create([
            'employee_id' => '00001',
            'hikvision_employee_no' => '00001',
            'nik' => 'NIK-00001',
            'name' => '',
            'department' => 'UNASSIGNED',
            'credential_method' => 'fingerprint',
            'credential_status' => 'expected_from_backup',
            'credential_source' => 'hikvision_backup',
            'card_registered' => false,
            'card_count' => 0,
            'card_type' => null,
            'fingerprint_verified' => false,
        ]);

        $this->getJson('/api/v1/user-management/employees')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Nama belum tersedia')
            ->assertJsonPath('data.0.department', 'Belum Ditentukan')
            ->assertJsonPath('data.0.credential_method', 'fingerprint')
            ->assertJsonPath('data.0.credential_status', 'expected_from_backup')
            ->assertJsonPath('data.0.credential_source', 'hikvision_backup')
            ->assertJsonPath('data.0.card_registered', false)
            ->assertJsonPath('data.0.card_count', 0)
            ->assertJsonPath('data.0.card_type', null)
            ->assertJsonPath('data.0.fingerprint_verified', false)
            ->assertJsonPath('data.0.device_registered', true)
            ->assertJsonPath('data.0.door_assignment_count', 0)
            ->assertJsonPath('data.0.access_mapping_status', 'unmapped');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeRegistrationCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_registered_count_requires_active_source_mapping_not_door_access(): void
    {
        Sanctum::actingAs(Admin::create([
            'name' => 'Count Admin',
            'email' => 'count@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]));

        $this->employee('CARD', [
            'source_person_number' => 'SRC-CARD',
            'credential_method' => 'card',
            'credential_status' => 'confirmed_from_backup',
        ]);
        $this->employee('FP', [
            'source_person_number' => 'SRC-FP',
            'credential_method' => 'fingerprint',
            'credential_status' => 'expected_from_backup',
        ]);
        $this->employee('LEGACY', ['source_person_number' => 'SRC-LEGACY', 'card_no' => 'LEGACY-CARD']);
        $this->employee('UNKNOWN', ['source_person_number' => null]);
        $this->employee('INACTIVE', [
            'employment_status' => 'INACTIVE',
            'source_person_number' => 'SRC-INACTIVE',
            'credential_method' => 'card',
            'credential_status' => 'confirmed_from_backup',
        ]);
        $this->employee('NO-MAPPING', [
            'hikvision_employee_no' => null,
            'source_person_number' => null,
            'credential_method' => 'card',
            'credential_status' => 'confirmed_from_backup',
        ]);

        $response = $this->getJson('/api/v1/admin/dashboard-metrics')->assertOk();

        $this->assertSame(5, $response->json('data.activeEmployees'));
        $this->assertSame(3, $response->json('data.registeredCredentials'));
        $this->assertLessThanOrEqual(
            $response->json('data.activeEmployees'),
            $response->json('data.registeredCredentials')
        );
    }

    private function employee(string $id, array $attributes = []): Employee
    {
        return Employee::factory()->create(array_merge([
            'employee_id' => $id,
            'hikvision_employee_no' => $id,
            'nik' => 'NIK-'.$id,
        ], $attributes));
    }
}

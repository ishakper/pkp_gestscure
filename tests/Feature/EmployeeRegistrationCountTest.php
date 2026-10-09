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

    public function test_registered_count_requires_active_mapping_and_credential_evidence_not_door_access(): void
    {
        Sanctum::actingAs(Admin::create([
            'name' => 'Count Admin',
            'email' => 'count@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]));

        $this->employee('CARD', [
            'credential_method' => 'card',
            'credential_status' => 'confirmed_from_backup',
        ]);
        $this->employee('FP', [
            'credential_method' => 'fingerprint',
            'credential_status' => 'expected_from_backup',
        ]);
        $this->employee('LEGACY', ['card_no' => 'LEGACY-CARD']);
        $this->employee('UNKNOWN');
        $this->employee('INACTIVE', [
            'employment_status' => 'INACTIVE',
            'credential_method' => 'card',
            'credential_status' => 'confirmed_from_backup',
        ]);
        $this->employee('NO-MAPPING', [
            'hikvision_employee_no' => null,
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

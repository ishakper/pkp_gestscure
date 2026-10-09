<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeCredentialSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_summary_categories_equal_active_employee_count(): void
    {
        Sanctum::actingAs(Admin::create([
            'name' => 'Summary Admin',
            'email' => 'summary@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]));

        $this->employee('CARD', ['credential_method' => 'card', 'credential_status' => 'confirmed_from_backup']);
        $this->employee('FP-EXPECTED', ['credential_method' => 'fingerprint', 'credential_status' => 'expected_from_backup']);
        $this->employee('FP-VERIFIED', ['credential_method' => 'fingerprint', 'credential_status' => 'verified', 'fingerprint_verified' => true]);
        $this->employee('REVIEW', ['credential_method' => 'review', 'credential_status' => 'conflict']);
        $this->employee('UNKNOWN');
        $this->employee('INACTIVE', ['employment_status' => 'INACTIVE']);

        $response = $this->getJson('/api/v1/admin/dashboard-metrics')->assertOk();
        $summary = $response->json('data.credentialSummary');

        $this->assertSame(5, $response->json('data.activeEmployees'));
        $this->assertSame(1, $summary['card']);
        $this->assertSame(1, $summary['fingerprint_expected']);
        $this->assertSame(1, $summary['fingerprint_verified']);
        $this->assertSame(1, $summary['review']);
        $this->assertSame(1, $summary['unknown']);
        $this->assertSame($response->json('data.activeEmployees'), array_sum($summary));
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

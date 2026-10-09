<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BiometricStatus;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminEmail = 'test_'.uniqid().'@accesscontrol.local';
        $this->admin = Admin::create([
            'name' => 'Super Admin',
            'email' => $this->adminEmail,
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_can_list_employees_with_pagination(): void
    {
        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
            'role_jabatan' => 'Lead Infrastructure',
        ]);

        BiometricStatus::create([
            'employee_id' => $employee->id,
            'fingerprint_enrolled' => true,
            'card_enrolled' => true,
        ]);

        $response = $this->getJson('/api/v1/user-management/employees');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'pagination' => ['current_page', 'per_page', 'total_records', 'total_pages'],
                'data',
            ]);
    }

    public function test_can_create_employee(): void
    {
        $payload = [
            'employee_id' => 'USR-1099',
            'nik' => 'NIK-882199',
            'name' => 'Test Employee',
            'department' => 'Produksi',
            'role_jabatan' => 'Staff',
            'fingerprint_enrolled' => true,
            'card_enrolled' => false,
        ];

        $response = $this->postJson('/api/v1/user-management/employees', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'message' => 'Karyawan berhasil ditambahkan',
            ]);

        $this->assertDatabaseHas('employees', ['employee_id' => 'USR-1099', 'nik' => 'NIK-882199']);
    }

    public function test_can_update_employee(): void
    {
        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
            'role_jabatan' => 'Staff',
        ]);

        BiometricStatus::create([
            'employee_id' => $employee->id,
            'fingerprint_enrolled' => false,
            'card_enrolled' => false,
        ]);

        $response = $this->putJson("/api/v1/user-management/employees/{$employee->id}", [
            'name' => 'Budi Santoso Updated',
            'fingerprint_enrolled' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'name' => 'Budi Santoso Updated']);
    }

    public function test_can_deactivate_employee_without_deleting_history(): void
    {
        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
            'role_jabatan' => 'Staff',
        ]);

        $response = $this->deleteJson("/api/v1/user-management/employees/{$employee->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'employment_status' => 'INACTIVE', 'deleted_at' => null]);
    }
}

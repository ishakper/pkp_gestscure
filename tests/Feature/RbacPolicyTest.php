<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_admin_forbidden_from_other_building_resources(): void
    {
        // Admin assigned only to Gedung A
        $buildingAdmin = Admin::create([
            'name' => 'Admin Gedung A',
            'email' => 'admin.gedunga@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A',
        ]);

        $doorB = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B - Gedung B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.12',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $employeeB = Employee::create([
            'employee_id' => 'USR-2001',
            'nik' => 'NIK-992001',
            'name' => 'Gedung B Staff',
            'department' => 'Produksi',
        ]);

        DoorAssignment::create([
            'employee_id' => $employeeB->id,
            'door_id' => $doorB->id,
            'sync_status' => 'synced',
        ]);

        Sanctum::actingAs($buildingAdmin);

        // Attempting to assign access to Door B (which belongs to Gedung B) should be forbidden 403
        $response = $this->postJson("/api/v1/user-management/employees/{$employeeB->id}/door-access", [
            'door_id' => 'DOOR-B',
        ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 403,
            ]);
    }
}

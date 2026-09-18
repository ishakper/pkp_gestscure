<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\Admin;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoorAccessSyncTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_assign_door_access_dispatches_sync_job(): void
    {
        Queue::fake();

        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
        ]);

        $door = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Door A - Gedung Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $response = $this->postJson("/api/v1/user-management/employees/{$employee->id}/door-access", [
            'door_id' => 'DOOR-A',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'employee_id' => 'USR-1001',
                    'door_id' => 'DOOR-A',
                    'sync_status' => 'pending',
                ],
            ]);

        Queue::assertPushed(SyncDoorAccessJob::class);
    }

    public function test_bulk_access_matrix_grants_and_revokes_access(): void
    {
        Queue::fake();

        $emp1 = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
        ]);

        $emp2 = Employee::create([
            'employee_id' => 'USR-1002',
            'nik' => 'NIK-882102',
            'name' => 'Siti Aminah',
            'department' => 'HR & Admin',
        ]);

        $doorA = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Door A - Gedung Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $doorB = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B - Ruang Server',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        // Pre-create assignment for emp1 on doorB to test revoke
        \App\Models\DoorAssignment::create([
            'employee_id' => $emp1->id,
            'door_id' => $doorB->id,
            'sync_status' => 'synced',
        ]);

        $response = $this->postJson('/api/v1/user-management/bulk-access', [
            'changes' => [
                ['employee_id' => $emp1->employee_id, 'door_id' => 'DOOR-A', 'action' => 'grant'],
                ['employee_id' => $emp2->employee_id, 'door_id' => 'DOOR-B', 'action' => 'grant'],
                ['employee_id' => $emp1->employee_id, 'door_id' => 'DOOR-B', 'action' => 'revoke'],
            ],
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'processed_count' => 3,
                'success_count' => 3,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $emp1->id,
            'door_id' => $doorA->id,
        ]);

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $emp2->id,
            'door_id' => $doorB->id,
        ]);

        $this->assertDatabaseMissing('door_assignments', [
            'employee_id' => $emp1->id,
            'door_id' => $doorB->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'bulk_access_matrix_update',
        ]);

        Queue::assertPushed(SyncDoorAccessJob::class);
    }
}

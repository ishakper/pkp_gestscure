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
}

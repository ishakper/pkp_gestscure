<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\Admin;
use App\Models\CredentialRecord;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CardEnrollmentAndLostCardTest extends TestCase
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

    public function test_enroll_new_card_validates_uniqueness_issues_credential_and_dispatches_sync(): void
    {
        Queue::fake();

        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
        ]);

        $door = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door B - Ruang Server',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        DoorAssignment::create([
            'employee_id' => $employee->id,
            'door_id' => $door->id,
            'sync_status' => 'synced',
        ]);

        $response = $this->postJson("/api/v1/user-management/employees/{$employee->id}/enroll-card", [
            'card_number' => 'CARD-889900',
            'notes' => 'Mifare RFID card enrolled via Web UI',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'employee_id' => 'USR-1001',
                    'card_number' => 'CARD-889900',
                    'status' => 'ACTIVE',
                ],
            ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'card_no' => 'CARD-889900',
        ]);

        $this->assertDatabaseHas('credential_records', [
            'employee_id' => $employee->id,
            'card_number' => 'CARD-889900',
            'status' => 'ACTIVE',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'card_enrolled',
            'subject_id' => $employee->id,
        ]);

        Queue::assertPushed(SyncDoorAccessJob::class);
    }

    public function test_enroll_duplicate_card_number_is_rejected(): void
    {
        $emp1 = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
            'card_no' => 'CARD-889900',
        ]);

        CredentialRecord::create([
            'credential_number' => 'CRD-2026-0001',
            'employee_id' => $emp1->id,
            'credential_type' => 'CARD',
            'card_number' => 'CARD-889900',
            'masked_identifier' => '****8900',
            'status' => 'ACTIVE',
        ]);

        $emp2 = Employee::create([
            'employee_id' => 'USR-1002',
            'nik' => 'NIK-882102',
            'name' => 'Siti Aminah',
            'department' => 'HR & Admin',
        ]);

        $response = $this->postJson("/api/v1/user-management/employees/{$emp2->id}/enroll-card", [
            'card_number' => 'CARD-889900',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
                'code' => 422,
                'message' => 'Nomor kartu ini sudah terdaftar dan aktif untuk pengguna lain.',
            ]);
    }

    public function test_lost_card_workflow_blocks_card_revokes_all_door_assignments_and_audits(): void
    {
        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
            'card_no' => 'CARD-776655',
        ]);

        $crd = CredentialRecord::create([
            'credential_number' => 'CRD-2026-0002',
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'card_number' => 'CARD-776655',
            'masked_identifier' => '****6655',
            'status' => 'ACTIVE',
        ]);

        $door1 = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door A - Lobby',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $door2 = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door B - Ruang Server',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door1->id, 'sync_status' => 'synced']);
        DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door2->id, 'sync_status' => 'synced']);

        $response = $this->postJson("/api/v1/user-management/employees/{$employee->id}/block-lost-card", [
            'card_number' => 'CARD-776655',
            'reason' => 'Kartu dompet tertinggal di taksi / hilang',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'revoked_doors_count' => 2,
                'data' => [
                    'employee_id' => 'USR-1001',
                    'status' => 'BLOCKED',
                    'revoked_doors_count' => 2,
                ],
            ]);

        $this->assertDatabaseHas('credential_records', [
            'id' => $crd->id,
            'status' => 'BLOCKED',
        ]);

        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'card_no' => null,
        ]);

        $this->assertDatabaseMissing('door_assignments', [
            'employee_id' => $employee->id,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'card_blocked_lost',
            'subject_id' => $employee->id,
        ]);
    }

    public function test_unauthorized_user_cannot_enroll_or_block_card(): void
    {
        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT Support',
        ]);

        $this->app->get('auth')->forgetGuards();

        $response = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson("/api/v1/user-management/employees/{$employee->id}/enroll-card", [
                'card_number' => 'CARD-112233',
            ]);

        $response->assertStatus(401);
    }
}

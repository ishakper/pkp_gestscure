<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DeviceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'services.hikvision.use_mock' => true,
        ]);
    }

    public function test_admin_can_test_door_connection_successful(): void
    {
        $admin = $this->createAdmin('super_admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/doors/test-connection', [
            'device_ip' => '192.168.90.16',
            'isapi_username' => 'admin',
            'isapi_password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Koneksi ke terminal ISAPI berhasil diverifikasi.',
            ])
            ->assertJsonStructure([
                'data' => ['model', 'serialNumber', 'firmware', 'online'],
            ]);
    }

    public function test_admin_test_door_connection_handles_offline_device(): void
    {
        $admin = $this->createAdmin('super_admin');

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/doors/test-connection', [
            'device_ip' => '192.168.90.99',
            'isapi_username' => 'admin',
            'isapi_password' => 'secret123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    public function test_admin_can_onboard_new_device_with_encrypted_password_and_audit_trail(): void
    {
        $admin = $this->createAdmin('super_admin');
        $building = Building::create(['code' => 'BLD-TEST', 'name' => 'Gedung Test R&D', 'is_active' => true]);
        $uniqueDoorId = 'DOOR-'.uniqid();

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/doors/onboard', [
            'door_id' => $uniqueDoorId,
            'name' => 'Pintu Lab R&D Lt 2',
            'building_id' => $building->id,
            'floor_id' => 2,
            'device_ip' => '192.168.90.20',
            'gateway' => '192.168.90.1',
            'isapi_username' => 'admin',
            'isapi_password' => 'SuperSecretPass123!',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonMissing(['isapi_password']);

        // Assert database record exists
        $door = Door::where('door_id', $uniqueDoorId)->firstOrFail();
        $this->assertEquals('Pintu Lab R&D Lt 2', $door->name);
        $this->assertEquals('192.168.90.20', $door->device_ip);
        $this->assertEquals('admin', $door->isapi_username);
        $this->assertEquals('online', $door->connection_status);

        // Assert password is encrypted in raw database, but transparently decrypted via model cast
        $rawCiphertext = \Illuminate\Support\Facades\DB::table('doors')->where('door_id', $uniqueDoorId)->value('isapi_password');
        $this->assertNotEmpty($rawCiphertext);
        $this->assertNotEquals('SuperSecretPass123!', $rawCiphertext);
        $this->assertEquals('SuperSecretPass123!', Crypt::decryptString($rawCiphertext));
        $this->assertEquals('SuperSecretPass123!', $door->isapi_password);

        // Assert audit trail record created
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'device_onboarded',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'admin_id' => $admin->id,
        ]);
    }

    public function test_unauthorized_user_cannot_onboard_device(): void
    {
        $manager = $this->createAdmin('management');

        $response = $this->actingAs($manager)->postJson('/api/v1/admin/doors/onboard', [
            'door_id' => 'DOOR-006',
            'name' => 'Unauthorized Door',
            'building_id' => 1,
            'device_ip' => '192.168.90.21',
            'isapi_username' => 'admin',
            'isapi_password' => 'pass',
        ]);

        $response->assertStatus(403);
    }

    private function createAdmin(string $role): Admin
    {
        return Admin::create([
            'name' => 'Test ' . ucfirst($role),
            'email' => $role . '_' . uniqid() . '@test.local',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManualConnectionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private Building $building;
    private Door $door;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.hikvision.use_mock' => true,
            'services.hikvision.allowed_cidrs' => [],
        ]);

        $this->admin = Admin::create([
            'name' => 'Manual Connection Admin',
            'email' => 'manual-connection@example.test',
            'password' => bcrypt('not-a-production-password'),
            'role' => 'super_admin',
        ]);
        $this->building = Building::create(['code' => 'BLD-TEST', 'name' => 'Test Building']);
        $this->door = Door::create([
            'door_id' => 'DOOR-TEST',
            'door_name' => 'Test Door',
            'building_id' => $this->building->id,
            'location' => $this->building->name,
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
            'isapi_username' => 'device-user',
            'isapi_password' => 'existing-device-password',
        ]);
    }

    public function test_manual_connection_allows_only_an_authorized_door_target_and_never_echoes_password(): void
    {
        $password = 'request-only-password';
        $payload = $this->manualPayload(['isapi_password' => $password]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/doors/DOOR-TEST/test-manual', $payload);

        $response->assertOk()->assertJsonPath('status', 'success');
        $this->assertStringNotContainsString($password, $response->getContent());

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/doors/DOOR-TEST/test-manual', $this->manualPayload([
                'device_ip' => '192.168.90.16',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'IP is not authorized for this door');
    }

    public function test_updating_manual_configuration_preserves_an_omitted_password(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/doors/DOOR-TEST', [
                'door_id' => 'DOOR-TEST',
                'name' => 'Test Door Updated',
                'building_id' => $this->building->id,
                'device_ip' => '192.168.90.15',
                'gateway' => '192.168.90.1',
                'device_model' => 'DS-K1T804AMF',
                'connection_mode' => 'manual',
                'connection_scheme' => 'https',
                'device_port' => 443,
                'connect_timeout' => 4,
                'read_timeout' => 8,
                'verify_tls' => true,
                'isapi_username' => 'device-user',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.isapi_password');

        $door = $this->door->fresh();
        $this->assertSame('existing-device-password', $door->isapi_password);
        $this->assertSame('manual', $door->connection_mode);
        $this->assertSame(443, $door->device_port);
        $this->assertSame(8, $door->read_timeout);
    }

    public function test_manual_connection_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/doors/DOOR-TEST/test-manual', $this->manualPayload())
            ->assertUnauthorized();
    }

    private function manualPayload(array $overrides = []): array
    {
        return array_merge([
            'device_ip' => '192.168.90.15',
            'device_port' => 8200,
            'connection_scheme' => 'http',
            'connect_timeout' => 3,
            'read_timeout' => 5,
            'verify_tls' => true,
            'isapi_username' => 'device-user',
        ], $overrides);
    }
}

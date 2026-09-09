<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RemoteUnlockDoorTest extends TestCase
{
    use RefreshDatabase;

    protected Door $doorA;
    protected Door $doorB;
    protected Admin $superAdmin;
    protected Admin $buildingAdmin;
    protected HikvisionIsapiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(HikvisionIsapiService::class);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Door A - Kantor Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'connection_status' => 'online',
        ]);

        $this->doorB = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B - Restricted Server Room',
            'location' => 'Gedung B (IT & Infra)',
            'device_ip' => '192.168.90.15',
            'connection_status' => 'online',
        ]);

        $this->superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->buildingAdmin = Admin::create([
            'name' => 'Admin Gedung A',
            'email' => 'admin.gedunga@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A',
        ]);
    }

    /**
     * Test HikvisionIsapiService remoteControlDoor in mock mode.
     */
    public function test_remote_control_door_service_in_mock_mode(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $result = $this->service->remoteControlDoor($this->doorB, 'open');

        $this->assertTrue($result['status']);
        $this->assertEquals(1, $result['statusCode']);
        $this->assertEquals('Simulated door unlock successful', $result['message']);
    }

    /**
     * Test HikvisionIsapiService remoteControlDoor in real HTTP mode with XML response.
     */
    public function test_remote_control_door_service_in_real_http_mode_success(): void
    {
        Config::set('services.hikvision.use_mock', false);

        $xmlResponse = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ResponseStatus version="2.0" xmlns="http://www.hikvision.com/ver20/XMLSchema">
    <requestURL>/ISAPI/AccessControl/RemoteControl/door/1</requestURL>
    <statusCode>1</statusCode>
    <statusString>OK</statusString>
    <subStatusCode>ok</subStatusCode>
</ResponseStatus>
XML;

        Http::fake([
            '*/AccessControl/RemoteControl/door/1' => Http::response($xmlResponse, 200, ['Content-Type' => 'application/xml']),
        ]);

        $result = $this->service->remoteControlDoor($this->doorB, 'open');

        $this->assertTrue($result['status']);
        $this->assertEquals(200, $result['statusCode']);
        $this->assertNull($result['error']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/AccessControl/RemoteControl/door/1')
                && str_contains($request->body(), '<cmd>open</cmd>');
        });
    }

    /**
     * Test HikvisionIsapiService remoteControlDoor handles device error response.
     */
    public function test_remote_control_door_service_handles_error(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/RemoteControl/door/1' => Http::response('Device Busy', 503),
        ]);

        $result = $this->service->remoteControlDoor($this->doorB, 'open');

        $this->assertFalse($result['status']);
        $this->assertEquals(503, $result['statusCode']);
        $this->assertStringContainsString('Device Busy', $result['error']);
    }

    public function test_remote_unlock_rejects_http_200_with_device_error(): void
    {
        Config::set('services.hikvision.use_mock', false);
        Http::fake(['*' => Http::response('<ResponseStatus><statusString>Invalid Operation</statusString></ResponseStatus>', 200)]);

        $this->assertFalse($this->service->remoteControlDoor($this->doorB)['status']);
    }

    public function test_remote_unlock_rejects_http_error_with_ok_body(): void
    {
        Config::set('services.hikvision.use_mock', false);
        Http::fake(['*' => Http::response('<ResponseStatus><statusString>OK</statusString></ResponseStatus>', 500)]);

        $this->assertFalse($this->service->remoteControlDoor($this->doorB)['status']);
    }

    /**
     * Test API endpoint POST /api/v1/admin/doors/{door_id}/open successfully unlocks door and logs activity.
     */
    public function test_admin_can_remote_unlock_door(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/doors/DOOR-B/open");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Pintu Door B - Restricted Server Room (DOOR-B) berhasil dibuka via remote.',
                'data' => [
                    'door_id' => 'DOOR-B',
                    'location' => 'Gedung B (IT & Infra)',
                ],
            ]);

        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $this->superAdmin->id,
            'action' => 'remote_door_opened',
            'subject_type' => 'Door',
            'subject_id' => $this->doorB->id,
        ]);
    }

    /**
     * Test API endpoint returns 500 when hardware fails to unlock.
     */
    public function test_admin_receives_error_when_hardware_unlock_fails(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/RemoteControl/door/1' => Http::response('503 Service Unavailable', 503),
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/doors/DOOR-B/open");

        $response->assertStatus(500)
            ->assertJson([
                'status' => 'error',
            ]);
    }

    /**
     * Test RBAC: Building admin cannot unlock a door in another building.
     */
    public function test_building_admin_cannot_unlock_door_in_other_building(): void
    {
        $response = $this->actingAs($this->buildingAdmin)
            ->postJson("/api/v1/admin/doors/DOOR-B/open");

        $response->assertStatus(403);
    }

    /**
     * Test direct unlock endpoint POST /api/v1/doors/{door_id}/unlock.
     */
    public function test_can_unlock_door_via_direct_unlock_endpoint(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/doors/DOOR-B/unlock");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
    }
}

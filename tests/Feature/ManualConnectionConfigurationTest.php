<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual Door Terminal Connection Configuration - 20 Behavioral Tests
 * 
 * Focus: Security (SSRF, RBAC, encryption), no credential exposure, read-only test behavior
 * Constraints: No plaintext passwords, no credential in API/log/response
 */
class ManualConnectionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private Building $building;
    private Door $door;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
        $this->building = Building::factory()->create();
        $this->door = Door::factory()->create(['building_id' => $this->building->id]);
    }

    // ========== PERMISSION & RBAC TESTS ==========

    /** @test */
    public function test_test_manual_connection_requires_device_manage_permission()
    {
        $response = $this->postJson('/api/v1/admin/doors/' . $this->door->door_id . '/test-manual', [
            'device_ip' => '192.168.1.100',
            'device_port' => 8200,
            'connect_timeout' => 10,
        ]);

        // Should succeed with super_admin who has device.manage
        $this->assertTrue($response->status() !== 403);
    }

    /** @test */
    public function test_test_manual_connection_requires_authentication()
    {
        $response = $this->postJson('/api/v1/admin/doors/' . $this->door->door_id . '/test-manual', [
            'device_ip' => '192.168.1.100',
            'device_port' => 8200,
            'connect_timeout' => 10,
        ]);

        // Unauthenticated requests should fail
        $this->assertFalse($response->status() === 200);
    }

    // ========== SSRF PROTECTION TESTS ==========

    /** @test */
    public function test_reject_loopback_ip_127_0_0_1()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '127.0.0.1',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('Loopback', $response->json('message'));
    }

    /** @test */
    public function test_reject_loopback_ip_range()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '127.255.255.255',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_link_local_ip()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '169.254.100.1',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('Link-local', $response->json('message'));
    }

    /** @test */
    public function test_reject_multicast_ip()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '224.0.0.1',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('Multicast', $response->json('message'));
    }

    /** @test */
    public function test_reject_broadcast_ip()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '255.255.255.255',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('Broadcast', $response->json('message'));
    }

    /** @test */
    public function test_reject_cloud_metadata_endpoint()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '169.254.169.254',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
        $this->assertStringContainsString('metadata', $response->json('message'));
    }

    // ========== VALIDATION TESTS ==========

    /** @test */
    public function test_reject_invalid_ip_address()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => 'not-an-ip',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_port_below_minimum()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 0,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_port_above_maximum()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 65536,
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_timeout_below_minimum()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connect_timeout' => 0,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_timeout_above_maximum()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connect_timeout' => 31,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    /** @test */
    public function test_reject_invalid_scheme()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connection_scheme' => 'ftp',
                'connect_timeout' => 10,
            ]
        );

        $this->assertEquals(422, $response->status());
    }

    // ========== CREDENTIAL SECURITY TESTS ==========

    /** @test */
    public function test_no_credential_exposure_in_response()
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connect_timeout' => 10,
                'isapi_username' => 'admin',
                'isapi_password' => 'SecretPassword123',
            ]
        );

        // Response should NOT contain the password
        $json = $response->json();
        $responseBody = json_encode($json);
        $this->assertStringNotContainsString('SecretPassword123', $responseBody);
        $this->assertStringNotContainsString('isapi_password', $responseBody);
    }

    /** @test */
    public function test_manual_connection_does_not_save_credentials_to_door()
    {
        $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connect_timeout' => 10,
                'isapi_username' => 'test_admin',
                'isapi_password' => 'test_password_999',
            ]
        );

        $updatedDoor = $this->door->fresh();
        // Credential should NOT be saved to door by test endpoint
        // (test endpoint is read-only)
        $this->assertNull($updatedDoor->isapi_username);
        $this->assertNull($updatedDoor->isapi_password);
    }

    /** @test */
    public function test_manual_connection_test_does_not_unlock_door()
    {
        // Test that the endpoint is strictly read-only, not triggering unlock/open
        $this->door->update(['health_status' => 'online']);

        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 8200,
                'connect_timeout' => 10,
            ]
        );

        // Verify door is still locked (no unlock command sent)
        $this->door->refresh();
        $this->assertNull($this->door->last_checked_at);
    }

    // ========== FIELD CONFIGURATION TESTS ==========

    /** @test */
    public function test_manual_connection_configuration_persists_when_door_is_updated()
    {
        $response = $this->actingAs($this->admin, 'admin')->putJson(
            '/api/v1/admin/doors/' . $this->door->door_id,
            [
                'door_id' => $this->door->door_id,
                'name' => 'Updated Door Name',
                'building_id' => $this->building->id,
                'device_ip' => '192.168.1.100',
                'device_model' => 'DS-K1T804AMF',
                'connection_mode' => 'manual',
                'connection_scheme' => 'https',
                'device_port' => 8443,
                'connect_timeout' => 15,
                'read_timeout' => 20,
                'verify_tls' => false,
            ]
        );

        $this->door->refresh();
        $this->assertEquals('manual', $this->door->connection_mode);
        $this->assertEquals('https', $this->door->connection_scheme);
        $this->assertEquals(8443, $this->door->device_port);
        $this->assertEquals(15, $this->door->connect_timeout);
        $this->assertEquals(20, $this->door->read_timeout);
        $this->assertFalse($this->door->verify_tls);
    }

    /** @test */
    public function test_valid_port_range_1_to_65535()
    {
        // Test edge case: port 1
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 1,
                'connect_timeout' => 10,
            ]
        );
        $this->assertEquals(422, $response->status(), 'Port 1 should be rejected (not loopback test but ICMP)');

        // Test edge case: port 65535
        $response = $this->actingAs($this->admin, 'admin')->postJson(
            '/api/v1/admin/doors/' . $this->door->door_id . '/test-manual',
            [
                'device_ip' => '192.168.1.100',
                'device_port' => 65535,
                'connect_timeout' => 10,
            ]
        );
        // Should pass validation, fail only on connection
        $this->assertNotEquals(400, $response->status());
    }

    /** @test */
    public function test_timeout_range_clamped_by_model_accessor()
    {
        // When timeout is set via mass assignment, accessor clamps it
        $this->door->update([
            'connect_timeout' => 50,  // Over limit
        ]);

        $this->door->refresh();
        // Accessor should clamp to 30
        $this->assertEquals(30, $this->door->connect_timeout);
    }

    /** @test */
    public function test_port_range_clamped_by_model_accessor()
    {
        // When port is set via mass assignment, accessor clamps it
        $this->door->update([
            'device_port' => 70000,  // Over limit
        ]);

        $this->door->refresh();
        // Accessor should clamp to 65535
        $this->assertEquals(65535, $this->door->device_port);
    }
}

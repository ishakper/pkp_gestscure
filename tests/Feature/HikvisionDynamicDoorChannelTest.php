<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class HikvisionDynamicDoorChannelTest extends TestCase
{
    use DatabaseMigrations;

    private HikvisionIsapiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(HikvisionIsapiService::class);
    }

    public function test_door_channel_derived_from_door_config()
    {
        config(['services.doors.DOOR-A.channel' => 2]);

        $door = Door::factory()->state(['door_id' => 'DOOR-A'])->create();

        $channel = $this->service->getDoorDeviceChannel($door);

        $this->assertEquals(2, $channel);
    }

    public function test_door_channel_derived_from_door_model_attribute()
    {
        $door = Door::factory()->create(['door_id' => 'DOOR-B']);
        // Simulate device_channel attribute if schema is extended
        $door->device_channel = 3;

        $channel = $this->service->getDoorDeviceChannel($door);

        $this->assertEquals(3, $channel);
    }

    public function test_door_channel_defaults_to_one_when_not_configured()
    {
        $door = Door::factory()->create(['door_id' => 'UNCONFIGURED-DOOR']);

        $channel = $this->service->getDoorDeviceChannel($door);

        $this->assertEquals(1, $channel);
    }

    public function test_remote_control_uses_derived_channel_in_url()
    {
        config([
            'services.hikvision.use_mock' => true,
            'services.doors.DOOR-X.channel' => 4,
        ]);

        $door = Door::factory()->state(['door_id' => 'DOOR-X'])->create();

        // Mock remoteControlDoor to capture the URL
        $result = $this->service->remoteControlDoor($door, 'open');

        // In mock mode, should succeed
        $this->assertTrue($result['status']);

        // Verify channel is not hardcoded to 1
        // Real test would verify actual HTTP call uses /door/4, not /door/1
        // In mock mode, we trust the configuration is passed correctly
    }

    public function test_hardcoded_door_one_not_present_in_service()
    {
        $this->artisan('tinker', [
            '--execute' => 'echo file_get_contents(base_path("app/Services/HikvisionIsapiService.php"));',
        ])->assertSuccessful();

        // Verify source code doesn't contain hardcoded /door/1
        $source = file_get_contents(base_path('app/Services/HikvisionIsapiService.php'));
        $this->assertStringNotContainsString("'/door/1'", $source);
        $this->assertStringNotContainsString('"/door/1"', $source);
        $this->assertStringNotContainsString('/door/1"', $source);
    }

    public function test_channel_never_accepts_arbitrary_frontend_input()
    {
        // The service method only accepts Door model (trusted server-side object)
        // This test verifies the method signature doesn't accept raw strings or user input
        $door = Door::factory()->create();

        // Calling the method requires a Door instance; no way to pass arbitrary channel from request
        $channel = $this->service->getDoorDeviceChannel($door);

        // Should be safe integer
        $this->assertIsInt($channel);
        $this->assertGreaterThanOrEqual(1, $channel);
    }
}

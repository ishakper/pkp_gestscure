<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RemoteUnlockReasonValidationTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;
    private Door $door;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->state(['role' => 'super_admin'])->create();
        $this->door = Door::factory()->state([
            'connection_status' => 'online',
            'health_status' => 'online',
            'device_ip' => '192.168.1.100',
        ])->create();
    }

    public function test_unlock_without_reason_rejected()
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            []
        );

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors(['reason']);

        // Verify no activity log created
        $this->assertDatabaseMissing('activity_logs', [
            'action' => 'remote_door_opened',
            'subject_id' => $this->door->id,
        ]);
    }

    public function test_unlock_with_empty_reason_rejected()
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => '']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_unlock_with_whitespace_only_reason_rejected()
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => '   ']
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_unlock_with_valid_reason_accepted()
    {
        // Mock the ISAPI service to return success
        $this->mockHikvisionSuccess();

        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => 'Pengunjung darurat memerlukan akses']
        );

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');

        // Verify activity log persists reason
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'remote_door_opened',
            'subject_id' => $this->door->id,
            'description' => "Remote unlock triggered for {$this->door->door_id} ({$this->door->door_name}) via web dashboard. Alasan: Pengunjung darurat memerlukan akses",
        ]);
    }

    public function test_unlock_reason_trimmed_in_audit_log()
    {
        $this->mockHikvisionSuccess();

        $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => '  Spaced reason  ']
        );

        // Verify whitespace was trimmed
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'remote_door_opened',
            'subject_id' => $this->door->id,
            'description' => "Remote unlock triggered for {$this->door->door_id} ({$this->door->door_name}) via web dashboard. Alasan: Spaced reason",
        ]);
    }

    public function test_unlock_reason_max_length_enforced()
    {
        $longReason = str_repeat('a', 501);

        $response = $this->actingAs($this->admin, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => $longReason]
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_unauthorized_unlock_rejected()
    {
        // Create an admin user WITHOUT super_admin role
        // The AuthServiceProvider gate policy only allows 'super_admin' role
        $user = Admin::factory()->state(['role' => 'building_admin'])->create();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            route('api.doors.open', $this->door->door_id),
            ['reason' => 'Valid reason']
        );

        $response->assertStatus(403);
    }

    private function mockHikvisionSuccess(): void
    {
        // Mock HikvisionIsapiService to return success without calling real device
        $this->app->bind('HikvisionIsapiService', function () {
            $mock = $this->createMock(\App\Services\HikvisionIsapiService::class);
            $mock->method('remoteControlDoor')->willReturn(['status' => true]);
            return $mock;
        });
    }
}

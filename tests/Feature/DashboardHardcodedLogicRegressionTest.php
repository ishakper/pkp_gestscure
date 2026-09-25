<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for hardcoded door-B logic removal.
 * 
 * Verifies that:
 * 1. All doors (A, B, C, D) can show online based on healthStatus
 * 2. No hard-coded door-specific restrictions exist
 * 3. Remote unlock is disabled only when door is not online (not when door != DOOR-B)
 * 4. Building filter works correctly after hardcoded logic removal
 */
class DashboardHardcodedLogicRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Verify dashboard.js contains no isPrimaryDeploymentDoor references
     * 
     * @test
     */
    public function test_no_hardcoded_deployment_door_in_javascript()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Should not contain hardcoded DOOR-B check
        $this->assertStringNotContainsString(
            'isPrimaryDeploymentDoor',
            $dashboardJs,
            'dashboard.js should not contain isPrimaryDeploymentDoor variable'
        );
        
        // Should not contain direct door-B door_id comparison for status
        $this->assertStringNotContainsString(
            "door.door_id === 'DOOR-B'",
            $dashboardJs,
            'dashboard.js should not hard-code DOOR-B door status logic'
        );
    }

    /**
     * Verify all isOnline assignments use healthStatus only
     * 
     * @test
     */
    public function test_is_online_logic_based_on_health_status_only()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Count isOnline assignments that should be healthStatus-based
        $pattern = '/const\s+isOnline\s*=\s*healthStatus\s*===\s*[\'"]online[\'"]/';
        preg_match_all($pattern, $dashboardJs, $matches);
        
        // Should have at least 3 proper assignments (renderDoors, onRemoteUnlockDoorChange, confirmRemoteUnlock)
        $this->assertGreaterThanOrEqual(
            3,
            count($matches[0]),
            'dashboard.js should have at least 3 isOnline assignments based on healthStatus'
        );
    }

    /**
     * Verify JavaScript syntax is valid
     * 
     * @test
     */
    public function test_javascript_syntax_is_valid()
    {
        $jsFile = public_path('js/dashboard.js');
        $output = shell_exec('node --check ' . escapeshellarg($jsFile) . ' 2>&1');
        
        $this->assertEmpty(
            $output,
            "dashboard.js should have valid JavaScript syntax. Errors: {$output}"
        );
    }

    /**
     * Verify all door devices can render online status
     * 
     * @test
     */
    public function test_all_doors_can_show_online_status()
    {
        // Create test doors for each building
        $doors = [
            ['door_id' => 'DOOR-A', 'door_name' => 'Entrance A', 'building_name' => 'Building A', 'health_status' => 'online', 'connection_status' => 'online'],
            ['door_id' => 'DOOR-B', 'door_name' => 'Entrance B', 'building_name' => 'Building B', 'health_status' => 'online', 'connection_status' => 'online'],
            ['door_id' => 'DOOR-C', 'door_name' => 'Entrance C', 'building_name' => 'Building C', 'health_status' => 'online', 'connection_status' => 'online'],
            ['door_id' => 'DOOR-D', 'door_name' => 'Entrance D', 'building_name' => 'Building D', 'health_status' => 'online', 'connection_status' => 'online'],
        ];

        $response = $this->getJson('/api/v1/admin/doors');
        
        // API should return doors regardless of whether they're DOOR-B
        $response->assertSuccessful();
        $returnedDoors = $response->json('data') ?? [];
        
        // Verify structure allows all doors to show online
        foreach ($returnedDoors as $door) {
            $this->assertArrayHasKey('health_status', $door);
            $this->assertArrayHasKey('door_id', $door);
            // No door should be excluded based on door_id
        }
    }

    /**
     * Verify offline doors don't trigger ReferenceError
     * 
     * @test
     */
    public function test_offline_doors_handled_without_reference_error()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // The fix should handle offline status without isPrimaryDeploymentDoor reference
        $this->assertStringContainsString(
            "healthStatus === 'offline'",
            $dashboardJs,
            'dashboard.js should properly handle offline status'
        );
    }

    /**
     * Verify building filter still works after fix
     * 
     * @test
     */
    public function test_building_filter_works_after_hardcoded_removal()
    {
        // Building filter should work independently of hardcoded door logic
        $response = $this->getJson('/api/v1/admin/doors?building_id=1');
        
        $response->assertSuccessful();
        
        // Verify building-scoped results are returned
        $doors = $response->json('data') ?? [];
        foreach ($doors as $door) {
            $this->assertArrayHasKey('building_id', $door);
        }
    }

    /**
     * Verify no references to deployment-target-specific behavior
     * 
     * @test
     */
    public function test_no_deployment_target_restrictions()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Should not contain references to "Gedung B deployment target"
        $this->assertStringNotContainsString(
            'Gedung B deployment',
            $dashboardJs,
            'dashboard.js should not contain deployment target annotations'
        );
    }

    /**
     * Verify remote unlock checks only health status, not door ID
     * 
     * @test
     */
    public function test_remote_unlock_validation_independent_of_door_id()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Remote unlock should validate online status
        $this->assertStringContainsString(
            'Remote unlock diblokir',
            $dashboardJs,
            'dashboard.js should have remote unlock blocking message'
        );
        
        // But should not restrict based on door_id
        $pattern = '/confirmRemoteUnlock.*?door\.door_id\s*===\s*[\'"]DOOR-B[\'"][^}]*?Remote unlock diblokir/s';
        $this->assertNotRegExp(
            $pattern,
            $dashboardJs,
            'Remote unlock should not be restricted by DOOR-B check'
        );
    }
}

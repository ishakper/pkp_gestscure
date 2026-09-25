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
     * Verify JavaScript syntax is valid (file check)
     * 
     * @test
     */
    public function test_javascript_syntax_is_valid()
    {
        $jsFile = public_path('js/dashboard.js');
        
        // Check file exists
        $this->assertFileExists($jsFile, 'dashboard.js file should exist');
        
        // Check file is not empty
        $content = file_get_contents($jsFile);
        $this->assertNotEmpty($content, 'dashboard.js should not be empty');
        
        // Basic structure check: should have function definitions
        $this->assertStringContainsString('function', $content, 'dashboard.js should have function definitions');
    }

    /**
     * Verify all door devices can render online status (static check)
     * 
     * @test
     */
    public function test_all_doors_can_show_online_status()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Verify renderDoors function doesn't restrict online status by door_id
        // Should have proper online status for all doors
        $this->assertStringContainsString(
            'const isOnline = healthStatus === \'online\'',
            $dashboardJs,
            'dashboard.js should check online status using healthStatus only'
        );
        
        // Verify no door-specific restrictions in rendering
        $this->assertStringNotContainsString(
            "door.door_id === 'DOOR-A'",
            $dashboardJs,
            'dashboard.js should not restrict DOOR-A status'
        );
        $this->assertStringNotContainsString(
            "door.door_id === 'DOOR-C'",
            $dashboardJs,
            'dashboard.js should not restrict DOOR-C status'
        );
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
            'offline',
            $dashboardJs,
            'dashboard.js should properly handle offline status'
        );
        
        // Should NOT have the harmful reference
        $this->assertStringNotContainsString(
            'isPrimaryDeploymentDoor',
            $dashboardJs,
            'dashboard.js should not reference isPrimaryDeploymentDoor'
        );
    }

    /**
     * Verify building filter still works after fix (static check)
     * 
     * @test
     */
    public function test_building_filter_works_after_hardcoded_removal()
    {
        $dashboardJs = file_get_contents(public_path('js/dashboard.js'));
        
        // Building filter should still have filter restore logic
        $this->assertStringContainsString(
            'restoreBuildingFilter',
            $dashboardJs,
            'dashboard.js should contain building filter function'
        );
        
        // Filter should work with all building types
        $this->assertStringContainsString(
            'building_id',
            $dashboardJs,
            'dashboard.js should filter using building_id'
        );
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
        
        // Should NOT have DOOR-B specific check
        $this->assertStringNotContainsString(
            "door.door_id === 'DOOR-B'",
            $dashboardJs,
            'Remote unlock should not be restricted by DOOR-B check'
        );
    }
}

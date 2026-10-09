<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dashboard Button & Modal Contract Test
 * Verifies all critical onclick handlers, tabs, and modals exist
 * and are wired correctly for the UI to function end-to-end.
 */
class DashboardButtonContractTest extends TestCase
{
    /**
     * Verify all critical tabs exist in dashboard view
     */
    public function test_dashboard_tabs_exist()
    {
        $requiredTabs = [
            'overviewTab',
            'employeesTab',
            'doorsTab',
            'logsTab',
            'auditLogTab',
            'buildingSetupTab',
            'systemAccountsTab',
            'systemStatusTab',
            'accessTab',
            'attendanceTab',
        ];

        $bladeContent = file_get_contents(
            resource_path('views/dashboard.blade.php')
        );

        foreach ($requiredTabs as $tabId) {
            $this->assertStringContainsString(
                "id=\"{$tabId}\"",
                $bladeContent,
                "Tab '{$tabId}' must be defined in dashboard.blade.php"
            );
        }
    }

    /**
     * Verify all critical onclick handlers are defined in JS
     */
    public function test_onclick_handlers_defined()
    {
        $criticalHandlers = [
            'refreshOperationalData',
            'openAddEmployeeModal',
            'syncHardwareLogs',
            'resetLogFilters',
            'openFacilityModal',
            'checkAllDoors',
            'switchTab',
            'loadTasks',
            'loadActivityLogs',
            'runEventSimulation',
            'handleSimEventTypeChange',
        ];

        $jsContent = file_get_contents(
            public_path('js/dashboard.js')
        );

        foreach ($criticalHandlers as $handler) {
            $this->assertStringContainsString(
                "function {$handler}",
                $jsContent,
                "Handler '{$handler}' must be defined in dashboard.js"
            );
        }
    }

    /**
     * Verify critical modals are defined in blade
     */
    public function test_critical_modals_exist()
    {
        $criticalModals = [
            'employeeModal',
            'facilityModal',
            'remoteUnlockModal',
            'modalAddAccessProfile',
            'modalAddAsset',
            'modalApproveAccessRequest',
            'modalMaintenance',
        ];

        $bladeContent = file_get_contents(
            resource_path('views/dashboard.blade.php')
        );

        foreach ($criticalModals as $modalId) {
            $this->assertStringContainsString(
                "id=\"{$modalId}\"",
                $bladeContent,
                "Modal '{$modalId}' must be defined in dashboard.blade.php"
            );
        }
    }

    /**
     * Verify no unresolved merge conflict markers exist
     */
    public function test_no_merge_conflicts()
    {
        $bladeContent = file_get_contents(
            resource_path('views/dashboard.blade.php')
        );
        $jsContent = file_get_contents(
            public_path('js/dashboard.js')
        );

        // Match actual merge conflict markers at line anchors, not decorative equals/angles
        $conflictPattern = '/^(?:<{7}(?: .*)?|={7}|>{7}(?: .*)?)[ \t]*$/m';

        $this->assertDoesNotMatchRegularExpression(
            $conflictPattern,
            $bladeContent,
            "Merge conflict marker found in dashboard.blade.php"
        );
        $this->assertDoesNotMatchRegularExpression(
            $conflictPattern,
            $jsContent,
            "Merge conflict marker found in dashboard.js"
        );
    }

    /**
     * Verify simulator endpoint is safe (protected, not direct hardware)
     */
    public function test_simulator_uses_safe_endpoint()
    {
        $jsContent = file_get_contents(
            public_path('js/dashboard.js')
        );

        // Should call /doors/simulate-event (protected endpoint)
        // NOT /isapi/event-notification (physical device webhook)
        $this->assertStringContainsString(
            "apiFetch('/doors/simulate-event'",
            $jsContent,
            "Simulator must use safe /doors/simulate-event endpoint"
        );
    }

    /**
     * Verify API routes exist for major dashboard actions
     */
    public function test_api_endpoints_exist()
    {
        // Check Laravel-registered routes instead of raw source text
        $requiredUris = [
            'api/v1/doors/simulate-event',
            'api/v1/admin/doors',
            'api/v1/admin/access-logs/sync-hardware',
            'api/v1/admin/doors/check-all',
            'api/v1/user-management/users',
        ];

        $routes = \Route::getRoutes();
        $registeredUris = [];

        foreach ($routes as $route) {
            // Collect all registered URIs (without leading /)
            $uri = ltrim($route->uri, '/');
            if ($uri && $uri !== '/') {
                $registeredUris[] = $uri;
            }
        }

        foreach ($requiredUris as $requiredUri) {
            $this->assertContains(
                $requiredUri,
                $registeredUris,
                "API route '{$requiredUri}' must be registered in routes"
            );
        }
    }
}

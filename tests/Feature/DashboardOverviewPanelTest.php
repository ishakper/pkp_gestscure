<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * DashboardOverviewPanelTest
 *
 * The Aktivitas Terakhir section (Dashboard Overview tab) used to duplicate the date-range
 * filters from the dedicated Log Akses tab (logStartDate/logEndDate vs logStartDateTab/logEndDateTab).
 * Since both fire the same syncLogFilters() + loadAccessLogs() logic, removing the Overview
 * duplicates is safe — JS already falls back to the Tab-suffixed pair via optional chaining.
 *
 * This test locks in two fixes:
 * 1. Duplicate date filters removed from Overview
 * 2. + Tambah Gedung/Pintu button wiring verified (openModal() is now defined upstream)
 */
class DashboardOverviewPanelTest extends TestCase
{
    /**
     * Test: Dashboard Overview does NOT have duplicate date filters.
     */
    public function test_overview_does_not_duplicate_date_filters()
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        
        // Verify Overview section (AKTIVITAS TERAKHIR) does NOT have logStartDate/logEndDate inputs
        preg_match('/AKTIVITAS TERAKHIR.*?overviewLogsTableBody/is', $blade, $matches);
        $this->assertNotEmpty($matches, 'Could not locate Overview section in dashboard.blade.php');
        
        $overviewSection = $matches[0];
        $this->assertStringNotContainsString('id="logStartDate"', $overviewSection,
            'Overview should NOT have logStartDate input — use logStartDateTab from Log Akses tab');
        $this->assertStringNotContainsString('id="logEndDate"', $overviewSection,
            'Overview should NOT have logEndDate input — use logEndDateTab from Log Akses tab');
    }

    /**
     * Test: Log Akses tab DOES have dedicated date filters.
     */
    public function test_log_akses_tab_has_dedicated_date_filters()
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        
        // Extract Log Akses tab section (from id="logsTab" through the table and filter controls)
        preg_match('/id="logsTab".*?<\/section>/is', $blade, $matches);
        $this->assertNotEmpty($matches, 'Could not locate Log Akses tab section');
        
        $logAksesSection = $matches[0];
        $this->assertStringContainsString('id="logStartDateTab"', $logAksesSection,
            'Log Akses tab MUST have logStartDateTab');
        $this->assertStringContainsString('id="logEndDateTab"', $logAksesSection,
            'Log Akses tab MUST have logEndDateTab');
    }

    /**
     * Test: JS uses null-safe fallback for date filters.
     * If logStartDate/logEndDate don't exist, JS safely falls back to Tab-suffixed pair.
     */
    public function test_js_date_filter_fallback_is_null_safe()
    {
        $script = file_get_contents(public_path('js/dashboard.js'));
        
        // Check for the fallback pattern using optional chaining (?.)
        $this->assertStringContainsString("document.getElementById('logStartDate')?.value", $script,
            'JS must use ?. operator for safe null access on logStartDate');
        $this->assertStringContainsString("document.getElementById('logEndDate')?.value", $script,
            'JS must use ?. operator for safe null access on logEndDate');
        
        // Verify fallback to Tab-suffixed IDs
        $this->assertStringContainsString("document.getElementById('logStartDateTab')", $script,
            'JS must reference logStartDateTab as fallback');
        $this->assertStringContainsString("document.getElementById('logEndDateTab')", $script,
            'JS must reference logEndDateTab as fallback');
    }

    /**
     * Test: "Tambah Gedung/Pintu" button is wired correctly.
     * The button should call openFacilityModal() which is now defined upstream (fix-perangkat-pintu-actions).
     */
    public function test_tambah_gedung_pintu_button_is_wired()
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));
        
        // Verify button exists with correct onclick
        $this->assertStringContainsString('openFacilityModal', $blade,
            'Dashboard must have "Tambah Gedung/Pintu" button calling openFacilityModal()');
        
        // Verify openFacilityModal is defined
        $this->assertStringContainsString('function openFacilityModal', $script,
            'openFacilityModal() must be defined in dashboard.js');
    }

    /**
     * Test: Facility modal supports both building and door creation.
     */
    public function test_facility_modal_supports_building_and_door_forms()
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));
        
        // Verify modal exists
        $this->assertStringContainsString('facilityModal', $blade,
            'facilityModal must exist in blade template');
        
        // Verify both forms exist
        $this->assertStringContainsString('buildingConfigForm', $blade,
            'Facility modal must have buildingConfigForm for creating buildings');
        $this->assertStringContainsString('doorConfigForm', $blade,
            'Facility modal must have doorConfigForm for creating doors');
        
        // Verify form submit handlers
        $this->assertStringContainsString('submitBuildingConfig', $script,
            'JS must have submitBuildingConfig() to POST to /admin/buildings');
        $this->assertStringContainsString('submitDoorConfig', $script,
            'JS must have submitDoorConfig() to POST to /admin/doors');
    }

    /**
     * Test: New doors render into both grids after creation.
     */
    public function test_new_doors_render_into_both_grids()
    {
        $blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $script = file_get_contents(public_path('js/dashboard.js'));
        
        // Verify both grid IDs exist
        $this->assertStringContainsString('id="doorsGrid"', $blade,
            'Template must have doorsGrid for rendering doors in Status Perangkat');
        $this->assertStringContainsString('id="overviewDoorsGrid"', $blade,
            'Template must have overviewDoorsGrid for rendering doors in Overview');
        
        // Verify loadDoors() updates both
        $this->assertStringContainsString('doorsGrid', $script,
            'loadDoors() must render into doorsGrid');
        $this->assertStringContainsString('overviewDoorsGrid', $script,
            'loadDoors() must also render into overviewDoorsGrid');
    }

    /**
     * Test: syncLogFilters() syncs values between Overview and Tab pairs.
     */
    public function test_sync_log_filters_maintains_bidirectional_sync()
    {
        $script = file_get_contents(public_path('js/dashboard.js'));
        
        // Verify sync mapping
        $this->assertStringContainsString("'logStartDate': 'logStartDateTab'", $script,
            'syncLogFilters() must map logStartDate ↔ logStartDateTab');
        $this->assertStringContainsString("'logEndDate': 'logEndDateTab'", $script,
            'syncLogFilters() must map logEndDate ↔ logEndDateTab');
        
        // Verify sync is bidirectional
        $this->assertStringContainsString("'logStartDateTab': 'logStartDate'", $script,
            'syncLogFilters() must be bidirectional for logStartDateTab');
        $this->assertStringContainsString("'logEndDateTab': 'logEndDate'", $script,
            'syncLogFilters() must be bidirectional for logEndDateTab');
    }
}
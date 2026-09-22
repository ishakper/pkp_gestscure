<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contract for the Dashboard/Overview tab:
 * 1. The date-range filters on "Aktivitas Terakhir" were removed (duplicated the ones that
 *    already work on the dedicated Log Akses tab). Guard against them silently coming back.
 * 2. "+ Tambah Gedung / Pintu" in "Status Perangkat" opens the facility modal, which lets an
 *    admin register a new building AND a new door. It was dead because openModal() was
 *    undefined; that is fixed upstream (fix-perangkat-pintu-actions). This locks the wiring.
 */
class DashboardOverviewPanelTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function overviewTabMarkup(): string
    {
        $this->assertSame(
            1,
            preg_match('/<section id="overviewTab" class="tab-content active">(.*?)<!-- SECTION 3:/s', $this->blade, $m),
            'overviewTab section (up to SECTION 3) not found in dashboard.blade.php'
        );

        return $m[1];
    }

    public function test_date_range_filters_are_not_duplicated_on_the_overview_panel(): void
    {
        $overview = $this->overviewTabMarkup();

        $this->assertStringNotContainsString('id="logStartDate"', $overview);
        $this->assertStringNotContainsString('id="logEndDate"', $overview);

        // The dedicated Log Akses tab keeps its own working date filters.
        $this->assertStringContainsString('id="logStartDateTab"', $this->blade);
        $this->assertStringContainsString('id="logEndDateTab"', $this->blade);
    }

    public function test_overview_log_search_controls_still_work_without_the_date_inputs(): void
    {
        $overview = $this->overviewTabMarkup();

        foreach (['logDoorFilter', 'logStatusFilter', 'logAttendanceStateFilter', 'logUserSearch'] as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $overview, "Overview panel should keep #{$id}");
        }
    }

    public function test_removed_date_inputs_are_referenced_only_through_null_safe_js(): void
    {
        // If dashboard.js ever reads logStartDate/logEndDate without a null-safe accessor,
        // it will throw once these inputs no longer exist on the Overview panel.
        $this->assertMatchesRegularExpression(
            "/document\\.getElementById\\('logStartDate'\\)\\?\\.\\s*value/",
            $this->script
        );
        $this->assertMatchesRegularExpression(
            "/document\\.getElementById\\('logEndDate'\\)\\?\\.\\s*value/",
            $this->script
        );
        $this->assertStringNotContainsString("document.getElementById('logStartDate').value", $this->script);
        $this->assertStringNotContainsString("document.getElementById('logEndDate').value", $this->script);
    }

    public function test_add_building_or_door_button_opens_the_facility_modal(): void
    {
        $overview = $this->overviewTabMarkup();

        $this->assertStringContainsString('onclick="openFacilityModal()"', $overview);
        $this->assertMatchesRegularExpression('/@if\(in_array\(\'device\.manage\',\s*\$permissions[^)]*\)\)\s*<button[^>]*onclick="openFacilityModal\(\)"/', $overview);
    }

    public function test_facility_modal_has_both_the_new_building_and_new_door_forms(): void
    {
        $this->assertMatchesRegularExpression('/id="facilityModal"/', $this->blade);
        $this->assertStringContainsString('id="buildingConfigForm"', $this->blade);
        $this->assertStringContainsString('onsubmit="submitBuildingConfig(event)"', $this->blade);
        $this->assertStringContainsString('id="doorConfigForm"', $this->blade);
        $this->assertStringContainsString('onsubmit="submitDoorConfig(event)"', $this->blade);

        foreach (['facilityDoorId', 'facilityDoorName', 'facilityDoorBuilding', 'facilityDoorIp', 'facilityDoorModel'] as $field) {
            $this->assertStringContainsString('id="' . $field . '"', $this->blade);
        }
    }

    public function test_modal_and_form_handlers_are_defined_and_openModal_is_no_longer_missing(): void
    {
        foreach (['openModal', 'closeModal', 'openFacilityModal', 'submitBuildingConfig', 'submitDoorConfig', 'loadDoors', 'renderDoorCards'] as $fn) {
            $this->assertMatchesRegularExpression(
                '/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(|window\.' . preg_quote($fn, '/') . '\s*=/',
                $this->script,
                "{$fn}() must be defined for the Tambah Gedung/Pintu flow to work"
            );
        }
    }

    public function test_new_doors_render_into_both_the_dashboard_and_perangkat_pintu_grids(): void
    {
        $start = strpos($this->script, 'function renderDoorCards(');
        $this->assertNotFalse($start);
        $body = substr($this->script, $start, 400);

        $this->assertStringContainsString("getElementById('doorsGrid')", $body);
        $this->assertStringContainsString("getElementById('overviewDoorsGrid')", $body);
    }
}

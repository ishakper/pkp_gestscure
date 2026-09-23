<?php

namespace Tests\Feature;

use Tests\TestCase;

class EmployeePaginationScrollContractTest extends TestCase
{
    private string $script;
    private string $view;

    protected function setUp(): void
    {
        parent::setUp();
        $this->script = file_get_contents(public_path('js/dashboard.js'));
        $this->view = file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    public function test_pagination_is_ajax_only_and_preserves_viewport(): void
    {
        $start = strpos($this->script, 'async function loadEmployees(');
        $end = strpos($this->script, 'function renderEmployeesTable(', $start);
        $contract = substr($this->script, $start, $end - $start);

        $this->assertStringContainsString('event.preventDefault();', $contract);
        $this->assertStringContainsString('event.stopPropagation();', $contract);
        $this->assertStringContainsString('const previousScrollY = window.scrollY;', $contract);
        $this->assertStringContainsString('requestAnimationFrame(() => {', $contract);
        $this->assertStringContainsString('window.scrollTo(0, previousScrollY);', $contract);
        $this->assertStringContainsString('window.history.replaceState', $contract);
        $this->assertStringNotContainsString('location.href =', $contract);
        $this->assertStringNotContainsString('window.scrollTo(0, 0)', $contract);
    }

    public function test_disabled_and_rapid_navigation_cannot_dispatch_or_render_stale_data(): void
    {
        $this->assertStringContainsString('control.disabled', $this->script);
        $this->assertStringContainsString("getAttribute('aria-disabled') === 'true'", $this->script);
        $this->assertStringContainsString('employeeRequestController.abort();', $this->script);
        $this->assertStringContainsString('requestSequence !== employeeRequestSequence', $this->script);
        $this->assertStringContainsString('signal: employeeRequestController.signal', $this->script);
    }

    public function test_filters_search_accessibility_and_layout_stability_are_preserved(): void
    {
        $this->assertStringContainsString("params.set('search', searchVal)", $this->script);
        $this->assertStringContainsString("params.set('door_id', doorFilter)", $this->script);
        $this->assertStringContainsString('aria-current="page"', $this->script);
        $this->assertStringContainsString('aria-disabled=', $this->script);
        $this->assertStringContainsString('employee-table-loading', $this->script);
        $this->assertStringContainsString('min-height: 680px', $this->view);
        $this->assertStringContainsString('transition: opacity 180ms ease', $this->view);
        $this->assertStringContainsString('position: sticky', $this->view);
    }
}

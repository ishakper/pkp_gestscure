<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 1. Task pagination reused class="employee-pagination", so renderEmployeePagination()'s
 *    querySelectorAll('.employee-pagination') overwrote #taskPagination with employee-flavored
 *    buttons (wired to loadEmployees(), not loadTasks()), clobbering the Task list's own pager.
 * 2. #floatingLogo (position:fixed, outside <aside class="sidebar">) had no opaque background,
 *    so scrolled page content showed through/behind it once the sidebar was closed.
 */
class PenggunaPaginationAndSidebarTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    public function test_task_pagination_no_longer_shares_a_class_with_employee_pagination(): void
    {
        $this->assertStringContainsString('id="taskPagination"', $this->blade);
        $this->assertMatchesRegularExpression('/class="task-pagination"\s+id="taskPagination"/', $this->blade);
        $this->assertStringNotContainsString('class="employee-pagination" id="taskPagination"', $this->blade);

        // Both real employee pagination containers keep their original class.
        $this->assertSame(2, substr_count($this->blade, 'class="employee-pagination"'));
    }

    public function test_both_employee_tables_get_loading_and_error_states(): void
    {
        $start = strpos($this->script, 'async function loadEmployees(');
        $end = strpos($this->script, 'function renderEmployeesTable(');
        $body = substr($this->script, $start, $end - $start);

        $this->assertStringContainsString("getElementById('fullEmployeesTableBody')", $body);
        $this->assertStringContainsString('if (fullTbody) fullTbody.innerHTML', $body);
    }

    public function test_disabled_pagination_buttons_are_visually_disabled(): void
    {
        $this->assertMatchesRegularExpression('/\.btn-secondary(:disabled|\[disabled\])[^{]*\{[^}]*pointer-events:\s*none/s', $this->blade);
    }

    public function test_floating_logo_has_an_opaque_background(): void
    {
        $start = strpos($this->blade, '.floating-logo {');
        $end = strpos($this->blade, '}', $start);
        $rule = substr($this->blade, $start, $end - $start);

        $this->assertStringContainsString('background: var(--sidebar-bg)', $rule);
    }
}

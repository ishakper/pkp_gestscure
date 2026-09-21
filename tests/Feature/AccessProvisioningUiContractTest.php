<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contract for the "Provisi Hak Akses, Kredensial & Registri E-Money" tab.
 *
 * The buttons on this tab were dead because the JS they call was never defined
 * (openModal) and because the first sub-tab was hidden by default. These checks
 * fail fast if a handler, a modal id, or the default sub-tab disappears again.
 */
class AccessProvisioningUiContractTest extends TestCase
{
    private string $blade;
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blade = file_get_contents(resource_path('views/dashboard.blade.php'));
        $this->script = file_get_contents(public_path('js/dashboard.js'));
    }

    private function accessTabMarkup(): string
    {
        $this->assertSame(
            1,
            preg_match('/<section class="tab-content" id="accessTab">(.*?)<\/section>/s', $this->blade, $m),
            'accessTab section not found in dashboard.blade.php'
        );

        return $m[1];
    }

    /** @return array<int, string> function names invoked from inline handler attributes or generated onclick strings */
    private function calledFunctions(string $source): array
    {
        preg_match_all('/\bon(?:click|change|input|submit|keyup)="([^"]+)"/', $source, $handlers);
        $names = [];
        foreach ($handlers[1] as $code) {
            preg_match_all('/(?<![.\w$])([A-Za-z_]\w*)\(/', $code, $calls);
            foreach ($calls[1] as $name) {
                $names[$name] = true;
            }
        }

        // Language/DOM built-ins that are not defined in dashboard.js.
        return array_values(array_diff(array_keys($names), ['if', 'confirm', 'alert', 'return']));
    }

    private function isDefined(string $name): bool
    {
        return (bool) preg_match('/\bfunction\s+' . preg_quote($name, '/') . '\s*\(|window\.' . preg_quote($name, '/') . '\s*=/', $this->script);
    }

    public function test_every_handler_used_by_the_access_tab_is_defined(): void
    {
        $missing = array_values(array_filter(
            $this->calledFunctions($this->accessTabMarkup()),
            fn (string $name) => !$this->isDefined($name)
        ));

        $this->assertSame([], $missing, 'Access tab calls undefined JS functions: ' . implode(', ', $missing));
    }

    public function test_every_handler_generated_by_the_access_loaders_is_defined(): void
    {
        $start = strpos($this->script, 'async function loadAccessRequests');
        $end = strpos($this->script, 'async function populateAccessEmployees');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $missing = array_values(array_filter(
            $this->calledFunctions(substr($this->script, $start, $end - $start)),
            fn (string $name) => !$this->isDefined($name)
        ));

        $this->assertSame([], $missing, 'Access loaders generate calls to undefined JS functions: ' . implode(', ', $missing));
    }

    public function test_modal_helpers_exist_and_every_modal_id_they_open_is_in_the_page(): void
    {
        $this->assertTrue($this->isDefined('openModal'), 'openModal() must be defined');
        $this->assertTrue($this->isDefined('closeModal'), 'closeModal() must be defined');

        preg_match_all("/(?:openModal|closeModal)\('([A-Za-z0-9_]+)'\)/", $this->script, $ids);
        $missing = array_values(array_filter(
            array_unique($ids[1]),
            fn (string $id) => !str_contains($this->blade, 'id="' . $id . '"')
        ));

        $this->assertSame([], $missing, 'JS opens modals that do not exist in the page: ' . implode(', ', $missing));
    }

    public function test_first_sub_tab_is_visible_on_load(): void
    {
        $this->assertMatchesRegularExpression(
            '/id="accessSubRequests"[^>]*style="display:\s*block;?"/',
            $this->blade,
            'The default sub-tab must be visible before any pill is clicked.'
        );
    }

    public function test_sub_navigation_pills_are_styled(): void
    {
        $this->assertStringContainsString('.ats-subnav .subnav-btn', $this->blade);
        $this->assertStringContainsString('.ats-subnav .subnav-btn.active', $this->blade);
    }

    public function test_refresh_button_reports_the_real_result(): void
    {
        $markup = $this->accessTabMarkup();

        $this->assertStringContainsString('onclick="refreshAccessData(this)"', $markup);
        $this->assertStringNotContainsString("loadAccessData(); showToast(", $markup);
        $this->assertTrue($this->isDefined('refreshAccessData'));
    }
}

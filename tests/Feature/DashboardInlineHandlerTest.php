<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Inline handler reachability contract.
 *
 * public/js/dashboard.js wraps its whole body in the
 * `if (window.__secureGateInitialized) { ... } else { ... }` guard. Inside that
 * block a plain `function foo()` declaration is still hoisted to window
 * (Annex B), but an `async function foo()` declaration is block-scoped, so an
 * inline `onclick="foo()"` throws "ReferenceError: foo is not defined".
 *
 * Checking that the name merely appears in the file is not enough (that is how
 * openFacilityModal / toggleDoorStatus / loadAccessLogs regressed), so this test
 * resolves every inline handler to a globally reachable function.
 */
class DashboardInlineHandlerTest extends TestCase
{
    private const NON_HANDLER_CALLS = [
        'if', 'return', 'event', 'this', 'confirm', 'alert', 'parseInt', 'String', 'Number',
        'encodeURIComponent', 'preventDefault', 'stopPropagation', 'setTimeout', 'getElementById',
        'querySelector', 'closest', 'remove', 'toggle', 'add', 'focus', 'click', 'reset', 'blur',
        'select', 'trim', 'includes',
    ];

    private function bladeSource(): string
    {
        return file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    private function jsSource(): string
    {
        return file_get_contents(public_path('js/dashboard.js'));
    }

    /** @return array<string, true> function names called from inline on* attributes */
    private function inlineHandlerNames(): array
    {
        preg_match_all(
            '/\bon(?:click|change|submit|input|keyup|keydown|blur|focus)="([^"]*)"/',
            $this->bladeSource() . "\n" . $this->jsSource(),
            $attributes
        );

        $names = [];
        foreach ($attributes[1] as $body) {
            // Drop method calls such as event.preventDefault() / this.closest(...).
            preg_match_all('/(?<![.\w$])([A-Za-z_$][\w$]*)\s*\(/', $body, $calls);
            foreach ($calls[1] as $name) {
                if (!in_array($name, self::NON_HANDLER_CALLS, true)) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
    }

    /** @return array<string, true> names assigned onto window */
    private function windowExports(): array
    {
        $js = $this->jsSource();
        preg_match_all('/window\.([A-Za-z_$][\w$]*)\s*=/', $js, $direct);
        $exports = array_fill_keys($direct[1], true);

        preg_match_all('/Object\.assign\(window,\s*\{([^}]*)\}\)/', $js, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('/([A-Za-z_$][\w$]*)\s*,/', $block . ',', $names);
            $exports += array_fill_keys($names[1], true);
        }

        return $exports;
    }

    public function test_every_inline_handler_resolves_to_a_global_function(): void
    {
        $js = $this->jsSource();
        $exports = $this->windowExports();
        $unreachable = [];

        foreach (array_keys($this->inlineHandlerNames()) as $name) {
            $quoted = preg_quote($name, '/');
            $isAsync = (bool) preg_match('/^\s*async\s+function\s+' . $quoted . '\s*\(/m', $js);
            $isPlain = (bool) preg_match('/^\s*function\s+' . $quoted . '\s*\(/m', $js);

            if (isset($exports[$name]) || ($isPlain && !$isAsync)) {
                continue;
            }

            $unreachable[] = $isAsync ? "{$name} (async, not exported to window)" : "{$name} (not defined)";
        }

        $this->assertSame([], $unreachable, 'Inline handlers that would throw ReferenceError: ' . implode(', ', $unreachable));
    }

    public function test_reported_dashboard_handlers_are_exported(): void
    {
        $exports = $this->windowExports();

        foreach (['openFacilityModal', 'toggleDoorStatus', 'loadAccessLogs', 'loadEmployees', 'loadDashboardBuildings', 'submitDoorConfig'] as $name) {
            $this->assertArrayHasKey($name, $exports, "{$name} must be reachable from inline Blade handlers");
        }
    }

    public function test_window_exports_point_at_defined_functions(): void
    {
        $js = $this->jsSource();

        foreach (array_keys($this->windowExports()) as $name) {
            if (str_starts_with($name, '__')) {
                continue; // guard flags, not functions
            }
            $this->assertMatchesRegularExpression(
                '/(?:function\s+' . preg_quote($name, '/') . '\s*\(|(?:const|let|var)\s+' . preg_quote($name, '/') . '\s*=|window\.' . preg_quote($name, '/') . '\s*=\s*(?:async\s*)?(?:function|\())/',
                $js,
                "window.{$name} is exported but never defined"
            );
        }
    }

    public function test_async_handlers_are_exported_once_inside_the_init_guard(): void
    {
        $js = $this->jsSource();
        $guardEnd = '} // end of window.__secureGateInitialized guard';

        $this->assertSame(1, substr_count($js, 'Object.assign(window, {'), 'Export the async handlers in exactly one block');
        $this->assertSame(1, substr_count($js, $guardEnd));
        $this->assertLessThan(strpos($js, $guardEnd), strpos($js, 'Object.assign(window, {'));
        // Outside the guard the async handlers are out of scope, so anything after it
        // that names them throws ReferenceError at load.
        $this->assertSame('', trim(substr($js, strpos($js, $guardEnd) + strlen($guardEnd))));
    }

    public function test_remote_unlock_listener_is_not_gated_on_the_init_flag(): void
    {
        // The flag is set at the top of the file, so re-checking it inside a later
        // 'load' callback always returned early and left Remote Unlock dead.
        $this->assertDoesNotMatchRegularExpression(
            "/addEventListener\\('load',\\s*\\(\\)\\s*=>\\s*\\{\\s*if \\(window\\.__secureGateInitialized\\) return;/",
            $this->jsSource()
        );
        $this->assertStringContainsString("event.target.closest('[data-action=\"remote-unlock\"]')", $this->jsSource());
    }

    public function test_dashboard_script_is_cache_busted(): void
    {
        $this->assertMatchesRegularExpression(
            '#<script src="/js/dashboard\.js\?v=\{\{[^}]*filemtime\(public_path\(\'js/dashboard\.js\'\)\)#',
            $this->bladeSource(),
            'dashboard.js must carry a version query so browsers drop stale copies after a deploy'
        );
    }
}

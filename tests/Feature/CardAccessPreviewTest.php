<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card Access guardrails. The UI renders in two modes: 'preview' (fictional fixtures,
 * local/testing only) and 'api' (read-only binding per Backend Contract Lock v1.1).
 * It only renders for credential viewers and the frontend module only ever issues
 * GET requests — no mutation, Hikvision or NFC call is possible from it.
 */
class CardAccessPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_listed_under_access_navigation_for_credential_viewers(): void
    {
        $response = $this->actingAs($this->admin('super_admin'))->get('/');

        $response->assertOk()
            ->assertSeeInOrder(['Hak Akses', 'Card Access', 'Rekap Kehadiran'], false)
            ->assertSee('id="cardAccessRoot"', false)
            ->assertSee('/js/card-access.js', false)
            ->assertSee('/css/card-access.css', false)
            ->assertSee('cardAccessMode: "preview"', false);
    }

    public function test_preview_is_hidden_without_credential_view_permission(): void
    {
        $response = $this->actingAs($this->admin('supervisor'))->get('/');

        $response->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertDontSee('/js/card-access.js', false)
            ->assertSee('cardAccessMode: "off"', false);
    }

    public function test_preview_is_never_rendered_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->actingAs($this->admin('super_admin'))->get('/');

        $response->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertDontSee('/js/card-access.js', false)
            ->assertSee('cardAccessMode: "off"', false);
    }

    public function test_fixtures_cannot_be_forced_on_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['services.card_access.ui' => 'preview']);

        $this->actingAs($this->admin('super_admin'))->get('/')
            ->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertSee('cardAccessMode: "off"', false);
    }

    public function test_read_only_api_mode_can_be_enabled_explicitly(): void
    {
        $this->app['env'] = 'production';
        config(['services.card_access.ui' => 'api']);

        $this->actingAs($this->admin('super_admin'))->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Hak Akses', 'Card Access', 'Rekap Kehadiran'], false)
            ->assertSee('/js/card-access.js', false)
            ->assertSee('cardAccessMode: "api"', false);

        $this->actingAs($this->admin('supervisor'))->get('/')
            ->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertSee('cardAccessMode: "off"', false);
    }

    public function test_module_has_no_direct_backend_device_or_destructive_calls(): void
    {
        $script = file_get_contents(public_path('js/card-access.js'));

        // All data goes through an adapter; anything outside Contract Lock v1.1 rejects.
        $this->assertStringContainsString("this.code = 'CONTRACT_PENDING';", $script);
        $this->assertStringContainsString("mode === 'api' && typeof global.apiFetch === 'function' ? createApiAdapter(global.apiFetch)", $script);
        foreach ([
            "get('/card-access/overview')",
            "get('/card-access/activity'",
            "get('/card-access/cards'",
            "get('/card-access/employees/' + encodeURIComponent(id))",
            "get('/card-access/employees/' + encodeURIComponent(id) + '/audit')",
            "get('/admin/buildings')",
        ] as $endpoint) {
            $this->assertStringContainsString($endpoint, $script, $endpoint);
        }

        // Read-only: no request method other than the default GET, no direct network/NFC access.
        foreach (['method:', "'POST'", "'PUT'", "'PATCH'", "'DELETE'", 'fetch(', 'apiFetch(', 'XMLHttpRequest', '.scan(', 'card_number'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, $forbidden);
        }

        $switchTab = file_get_contents(public_path('js/dashboard.js'));
        $this->assertStringContainsString("if (tabId === 'cardAccessTab' && window.CardAccess) window.CardAccess.load('cardAccessRoot');", $switchTab);
    }

    private function admin(string $role): Admin
    {
        return Admin::create([
            'name' => ucfirst($role),
            'email' => $role.'-card-access@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Card Access & NFC Provisioning is a Phase 1/1.5 design preview. These tests pin the
 * guardrails: it only renders outside production, only for credential viewers, and the
 * frontend module never talks to the backend, Hikvision or an NFC reader by itself.
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
            ->assertSee('cardAccessPreview: true', false);
    }

    public function test_preview_is_hidden_without_credential_view_permission(): void
    {
        $response = $this->actingAs($this->admin('supervisor'))->get('/');

        $response->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertDontSee('/js/card-access.js', false)
            ->assertSee('cardAccessPreview: false', false);
    }

    public function test_preview_is_never_rendered_in_production(): void
    {
        $this->app['env'] = 'production';

        $response = $this->actingAs($this->admin('super_admin'))->get('/');

        $response->assertOk()
            ->assertDontSee('id="cardAccessRoot"', false)
            ->assertDontSee('/js/card-access.js', false)
            ->assertSee('cardAccessPreview: false', false);
    }

    public function test_module_has_no_direct_backend_device_or_destructive_calls(): void
    {
        $script = file_get_contents(public_path('js/card-access.js'));

        // All data goes through an adapter; the default one rejects until the contract lock.
        $this->assertStringContainsString("this.code = 'CONTRACT_PENDING';", $script);
        $this->assertStringContainsString('cfg.cardAccessPreview ? createPreviewAdapter() : ContractPendingAdapter', $script);
        foreach (['fetch(', 'apiFetch(', 'XMLHttpRequest', '.scan(', "'DELETE'", 'card_number'] as $forbidden) {
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

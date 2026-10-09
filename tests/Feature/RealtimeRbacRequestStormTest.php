<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RealtimeRbacRequestStormTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Super Admin dashboard view renders configuration without leaks.
     */
    public function test_super_admin_dashboard_has_full_permissions(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Super Admin Test',
            'email' => 'sa.test@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $response = $this->actingAs($superAdmin)->get('/');
        $response->assertStatus(200);

        // Verify script contains capability pre-check logic
        $script = file_get_contents(public_path('js/dashboard.js'));
        $this->assertStringContainsString('forbiddenCapabilities.add(capability);', $script);
        $this->assertStringContainsString('scheduleMetricCardsUpdate();', $script);
        $this->assertStringContainsString("source.addEventListener('reload'", $script);
        $this->assertStringContainsString('isNormalRotation', $script);
    }

    /**
     * Test Building Admin is blocked from unauthorized endpoints with 403.
     */
    public function test_building_admin_cannot_access_audit_logs(): void
    {
        $buildingAdmin = Admin::create([
            'name' => 'Building Admin Test',
            'email' => 'ba.test@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A (Kantor Utama)',
        ]);

        // Building admin calling activity logs directly gets 403 Forbidden
        Sanctum::actingAs($buildingAdmin);
        $response = $this->getJson('/api/v1/admin/activity-logs');
        $response->assertStatus(403);
    }

    /**
     * Test that normal SSE reload event is treated with zero failure increments in JS.
     */
    public function test_dashboard_js_treats_sse_reload_as_normal_rotation(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertMatchesRegularExpression(
            '/function scheduleSseReconnect\s*\(\s*isNormalRotation\s*=\s*false\s*\)\s*\{[\s\S]*?if\s*\(\s*isNormalRotation\s*\)\s*\{[\s\S]*?realtime\.failures\s*=\s*0;/',
            $script
        );

        $this->assertStringContainsString("source.addEventListener('reload', () => {", $script);
        $this->assertStringContainsString("scheduleSseReconnect(true);", $script);
    }

    /**
     * Test 429 response handling sets rateLimitCooldownUntil in dashboard.js.
     */
    public function test_dashboard_js_handles_429_with_cooldown(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringContainsString('rateLimitCooldownUntil = Date.now() + (cooldownSec * 1000);', $script);
        $this->assertStringContainsString('isBackground && Date.now() < rateLimitCooldownUntil', $script);
    }
}

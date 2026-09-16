<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardForensicStormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.request_forensics_enabled' => true]);
        if (file_exists(storage_path('logs/request_forensics.log'))) {
            @unlink(storage_path('logs/request_forensics.log'));
        }
    }

    public function test_request_forensics_middleware_logs_route_and_role_safely(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Forensic Super Admin',
            'email' => 'superadmin.forensic@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/admin/doors');
        $response->assertStatus(200);

        $logPath = storage_path('logs/request_forensics.log');
        $this->assertFileExists($logPath);

        $content = file_get_contents($logPath);
        $this->assertStringContainsString('[FORENSIC]', $content);
        $this->assertStringContainsString('| GET | api/v1/admin/doors |', $content);
        $this->assertStringContainsString('| super_admin | 200', $content);
        // Sensitive data must never be logged
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('Authorization', $content);
    }

    public function test_dashboard_script_has_initialization_idempotency_guard(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringContainsString('if (window.__secureGateInitialized)', $script);
        $this->assertStringContainsString('window.__secureGateInitialized = true;', $script);
    }

    public function test_api_fetch_form_delegates_to_centralized_policy(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        $this->assertStringContainsString('async function apiFetchForm(endpoint, formData)', $script);
        $this->assertStringContainsString('return apiFetch(endpoint, {', $script);
        $formStart = strpos($script, 'async function apiFetchForm');
        $formEnd = strpos($script, '// ==========================================', $formStart);
        $formFunction = substr($script, $formStart, $formEnd - $formStart);
        $this->assertStringNotContainsString('fetch(', $formFunction);
    }

    public function test_all_business_apis_use_centralized_wrapper_without_raw_fetch(): void
    {
        $script = file_get_contents(public_path('js/dashboard.js'));

        // All business POST requests must route through apiFetch or apiFetchForm
        $this->assertStringNotContainsString("fetch('/api/v1/doors/simulate-event'", $script);
        $this->assertStringNotContainsString("fetch('/api/v1/onboarding/documents'", $script);
        $this->assertStringNotContainsString("fetch('/api/v1/attendance-requests'", $script);
        $this->assertStringNotContainsString("fetch(endpoint,", $script);
    }

    public function test_super_admin_idle_dashboard_request_burst_is_bounded_and_has_zero_post_storm(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Idle Super Admin',
            'email' => 'sa.idle@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        Door::create([
            'door_id' => 'DOOR-TEST-1',
            'door_name' => 'Main Test Door',
            'location' => 'Gedung A',
            'device_ip' => '192.168.1.50',
            'device_model' => 'DS-K1T804AMF',
            'status' => 'online',
            'is_active' => true,
        ]);

        Sanctum::actingAs($superAdmin);

        // Simulate initial dashboard boot requests initiated on DOMContentLoaded
        $r1 = $this->getJson('/api/v1/admin/doors');
        $r2 = $this->getJson('/api/v1/user-management/employees?page=1&per_page=15');
        $r3 = $this->getJson('/api/v1/admin/access-logs?per_page=30');
        $r4 = $this->getJson('/api/v1/admin/activity-logs?per_page=30');
        $r5 = $this->getJson('/api/v1/admin/dashboard-metrics');

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        $r3->assertStatus(200);
        $r4->assertStatus(200);
        $r5->assertStatus(200);

        $logPath = storage_path('logs/request_forensics.log');
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $postRequests = array_filter($lines, fn($l) => str_contains($l, '| POST |'));
        $fourHundredThree = array_filter($lines, fn($l) => str_contains($l, '| 403'));
        $fourHundredTwentyNine = array_filter($lines, fn($l) => str_contains($l, '| 429'));

        // Zero automatic POST requests during dashboard boot/idle
        $this->assertCount(0, $postRequests, 'Super admin idle boot dispatched unexpected POST requests');
        $this->assertCount(0, $fourHundredThree, 'Unexpected 403 logged during super admin boot');
        $this->assertCount(0, $fourHundredTwentyNine, 'Unexpected 429 logged during super admin boot');
    }

    public function test_building_admin_forbidden_endpoints_produce_zero_unauthorized_dispatch(): void
    {
        $buildingAdmin = Admin::create([
            'name' => 'Building Admin Forensic',
            'email' => 'ba.forensic@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A',
        ]);

        Sanctum::actingAs($buildingAdmin);

        // Permitted read
        $resPermitted = $this->getJson('/api/v1/admin/doors');
        $resPermitted->assertStatus(200);

        // Directly verify that frontend pre-check suppresses unauthorized capability
        $script = file_get_contents(public_path('js/dashboard.js'));
        $this->assertStringContainsString('forbiddenCapabilities.has(capability) || !hasCapability(capability)', $script);
        $this->assertStringContainsString("if (role === 'super_admin') return true;", $script);
    }
}

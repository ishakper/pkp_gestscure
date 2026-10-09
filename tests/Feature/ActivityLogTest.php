<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_is_global_for_super_admin_and_forbidden_for_other_roles(): void
    {
        $superAdmin = $this->admin('super_admin');
        $otherAdmin = $this->admin('infra_admin');
        ActivityLog::create(['admin_id' => $superAdmin->id, 'action' => 'global.one', 'description' => 'Building A', 'timestamp' => now()]);
        ActivityLog::create(['admin_id' => $otherAdmin->id, 'action' => 'global.two', 'description' => 'Building B', 'timestamp' => now()]);

        $this->actingAs($superAdmin)->getJson('/api/v1/admin/activity-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['action' => 'global.one'])
            ->assertJsonFragment(['action' => 'global.two']);

        foreach (['building_admin', 'infra_admin', 'developer', 'devops', 'security_engineer', 'management', 'employee'] as $role) {
            $this->actingAs($this->admin($role))->getJson('/api/v1/admin/activity-logs')->assertForbidden();
        }
    }

    public function test_audit_response_redacts_sensitive_context_without_changing_stored_record(): void
    {
        $admin = $this->admin('super_admin');
        $description = 'updated profile password=Phase16Secret Authorization: Bearer phase16-token {"password_hash":"hash-value","token":"token-value","secret":"secret-value","card_no":"99887766","rfid":"RFID-42","fingerprint":"print-data","biometric_template":"BASE64SECRET"} context=kept';
        $log = ActivityLog::create(['admin_id' => $admin->id, 'action' => 'profile.updated', 'description' => $description, 'timestamp' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/activity-logs')->assertOk();
        foreach (['Phase16Secret', 'phase16-token', 'hash-value', 'token-value', 'secret-value', '99887766', 'RFID-42', 'print-data', 'BASE64SECRET'] as $secret) {
            $response->assertDontSee($secret, false);
        }
        $response->assertSee('context=kept', false);
        $this->assertSame($description, $log->fresh()->description);
    }

    public function test_audit_log_has_no_direct_id_route(): void
    {
        $admin = $this->admin('super_admin');
        $log = ActivityLog::create(['admin_id' => $admin->id, 'action' => 'route.test', 'description' => 'Route absence check', 'timestamp' => now()]);

        $this->actingAs($admin)->getJson("/api/v1/admin/activity-logs/{$log->id}")->assertNotFound();
    }

    private function admin(string $role): Admin
    {
        return Admin::create(['name' => $role, 'email' => $role.uniqid().'@example.test', 'password' => bcrypt('password'), 'role' => $role]);
    }
}

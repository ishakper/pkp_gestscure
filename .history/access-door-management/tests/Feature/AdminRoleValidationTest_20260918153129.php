<?php
namespace Tests\Feature;

use App\Models\Admin;
use App\Services\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_admin_role_is_rejected_by_application_validation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Admin::create(['name' => 'x', 'email' => 'x@test.local', 'password' => 'x', 'role' => 'invalid_role']);
    }

    /** @test Phase 23: All roles tested by PortalAccess must exist in Admin::ROLES */
    public function test_portal_access_only_references_canonical_roles(): void
    {
        $canonical = Admin::ROLES;

        // Build PortalAccess coverage by testing each canonical role
        $portalAccess = app(PortalAccess::class);
        foreach ($canonical as $role) {
            $admin = Admin::factory()->create(['role' => $role]);
            $portal = $portalAccess->portalFor($admin);
            $this->assertContains($portal, [
                PortalAccess::ADMIN_PORTAL,
                PortalAccess::MANAGEMENT_PORTAL,
                PortalAccess::EMPLOYEE_PORTAL,
            ], "Role '{$role}' did not map to a valid portal.");
        }

        // Stale roles must NOT be in Admin::ROLES
        $staleRoles = ['project_manager', 'auditor'];
        foreach ($staleRoles as $stale) {
            $this->assertNotContains($stale, $canonical, "Stale role '{$stale}' should not be in Admin::ROLES.");
        }
    }
}



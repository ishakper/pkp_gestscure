<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SwaggerSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Unauthenticated guest must be redirected or rejected when accessing Swagger UI.
     */
    public function test_unauthenticated_guest_cannot_access_swagger_ui(): void
    {
        $response = $this->get('/api/documentation');

        $response->assertStatus(302);
        $response->assertRedirect('/login');
    }

    /**
     * Unauthenticated guest must receive 401 when requesting raw OpenAPI spec.
     */
    public function test_unauthenticated_guest_cannot_access_openapi_spec_json(): void
    {
        $response = $this->getJson('/docs');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * Unauthenticated guest cannot access Swagger assets.
     */
    public function test_unauthenticated_guest_cannot_access_swagger_assets(): void
    {
        $response = $this->get('/docs/asset/swagger-ui.css');

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Unauthenticated.',
        ]);
    }

    /**
     * Unauthorized internal role (e.g. employee, intern) is forbidden.
     */
    public function test_unauthorized_internal_user_is_forbidden_from_swagger(): void
    {
        $employee = Admin::create([
            'name' => 'Employee User',
            'email' => 'employee@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'employee',
        ]);

        $this->actingAs($employee, 'web');

        $responseUi = $this->get('/api/documentation');
        $responseUi->assertStatus(403);

        $responseDocs = $this->getJson('/docs');
        $responseDocs->assertStatus(403);

        $responseAsset = $this->get('/docs/asset/swagger-ui.css');
        $responseAsset->assertStatus(403);
    }

    /**
     * Authorized administrator (super_admin) can access Swagger UI and docs.
     */
    public function test_super_admin_can_access_swagger_ui_and_spec(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'super_admin',
        ]);

        $this->actingAs($superAdmin, 'web');

        $responseUi = $this->get('/api/documentation');
        $responseUi->assertStatus(200);

        $responseDocs = $this->get('/docs');
        $responseDocs->assertStatus(200);
        $responseDocs->assertJsonFragment([
            'title' => 'PKP SecureGate API Documentation',
        ]);

        $responseAsset = $this->get('/docs/asset/swagger-ui.css');
        $responseAsset->assertStatus(200);
    }

    /**
     * Authorized administrator (infra_admin) can access Swagger UI and docs.
     */
    public function test_infra_admin_can_access_swagger_ui_and_spec(): void
    {
        $infraAdmin = Admin::create([
            'name' => 'Infra Admin',
            'email' => 'infraadmin@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'infra_admin',
        ]);

        $this->actingAs($infraAdmin, 'web');

        $responseUi = $this->get('/api/documentation');
        $responseUi->assertStatus(200);

        $responseDocs = $this->get('/docs');
        $responseDocs->assertStatus(200);
    }

    /**
     * Authorized administrator (security_engineer) can access Swagger UI and docs.
     */
    public function test_security_engineer_can_access_swagger_ui_and_spec(): void
    {
        $secEngineer = Admin::create([
            'name' => 'Security Engineer',
            'email' => 'seceng@example.test',
            'password' => bcrypt('secret123'),
            'role' => 'security_engineer',
        ]);

        $this->actingAs($secEngineer, 'web');

        $responseUi = $this->get('/api/documentation');
        $responseUi->assertStatus(200);

        $responseDocs = $this->get('/docs');
        $responseDocs->assertStatus(200);
    }
}

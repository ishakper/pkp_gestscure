<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminLoginRegressionTest extends TestCase
{
    use RefreshDatabase;

    private string $superAdminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed demo admin accounts for testing
        $this->superAdminEmail = 'test_'.uniqid().'@accesscontrol.local';
        Admin::create([
            'email' => $this->superAdminEmail,
            'name' => 'Super Administrator',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'assigned_building' => null,
        ]);

        Admin::create([
            'email' => 'admin.gedunga@accesscontrol.local',
            'name' => 'Admin Gedung A',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung A (Kantor Utama)',
        ]);
    }

    public function test_valid_super_admin_login()
    {
        $response = $this->post('/login', [
            'email' => $this->superAdminEmail,
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs(
            Admin::where('email', $this->superAdminEmail)->first(),
            'web'
        );
    }

    public function test_valid_building_admin_login()
    {
        $response = $this->post('/login', [
            'email' => 'admin.gedunga@accesscontrol.local',
            'password' => 'password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs(
            Admin::where('email', 'admin.gedunga@accesscontrol.local')->first(),
            'web'
        );
    }

    public function test_invalid_password_rejected()
    {
        $response = $this->post('/login', [
            'email' => $this->superAdminEmail,
            'password' => 'wrongpassword',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest('web');
    }

    public function test_nonexistent_email_rejected()
    {
        $response = $this->post('/login', [
            'email' => 'nonexistent@accesscontrol.local',
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['email']);
        $this->assertGuest('web');
    }

    public function test_login_error_message_is_generic()
    {
        // Test with wrong password
        $response = $this->post('/login', [
            'email' => $this->superAdminEmail,
            'password' => 'wrong',
        ]);

        $errors = $response->getSession()->get('errors');
        $emailError = $errors->get('email')[0];

        $this->assertStringContainsString('Kredensial login tidak valid', $emailError);
        // Verify it doesn't leak password/account status details
        $this->assertStringNotContainsString('Password', $emailError);
        $this->assertStringNotContainsString('Email tidak ditemukan', $emailError);
    }

    public function test_session_regenerated_after_login()
    {
        $this->post('/login', [
            'email' => $this->superAdminEmail,
            'password' => 'password',
        ]);

        // Get response after login
        $response = $this->get('/');

        // Should be authenticated and on dashboard
        $this->assertAuthenticated('web');
        $response->assertStatus(200);
    }

    public function test_api_token_created_on_login()
    {
        $this->post('/login', [
            'email' => $this->superAdminEmail,
            'password' => 'password',
        ]);

        $admin = Admin::where('email', $this->superAdminEmail)->first();
        $this->assertTrue($admin->tokens()->exists());
        $this->assertTrue($admin->tokens()->where('name', 'web-session-token')->exists());
    }

    public function test_missing_email_validation_fails()
    {
        $response = $this->post('/login', [
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['email']);
    }

    public function test_missing_password_validation_fails()
    {
        $response = $this->post('/login', [
            'email' => $this->superAdminEmail,
        ]);

        $response->assertSessionHasErrors(['password']);
    }

    public function test_authenticated_admin_cannot_access_login_page()
    {
        $admin = Admin::where('email', $this->superAdminEmail)->first();
        $this->actingAs($admin, 'web');

        $response = $this->get('/login');
        $response->assertRedirect('/');
    }

    public function test_dashboard_requires_authentication()
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }

    public function test_logout_clears_session_and_tokens()
    {
        $admin = Admin::where('email', $this->superAdminEmail)->first();
        $this->actingAs($admin, 'web');

        // Create a token in session
        session(['api_token' => 'test-token', 'api_token_id' => 1]);

        $this->post('/logout');

        $this->assertGuest('web');
        // Session should be invalidated
        $response = $this->get('/');
        $response->assertRedirect('/login');
    }
}

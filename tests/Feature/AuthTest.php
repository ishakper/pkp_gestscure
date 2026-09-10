<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_valid_credentials(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@accesscontrol.local',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'message',
                'data' => [
                    'token',
                    'token_type',
                    'admin' => ['id', 'name', 'email', 'role'],
                ],
            ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@accesscontrol.local',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'status' => 'error',
                'code' => 401,
            ]);
    }

    public function test_web_logout_revokes_web_session_tokens(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect('/');

        $tokenId = session('api_token_id');
        $this->assertNotNull(session('api_token'));
        $this->assertNotNull($tokenId);
        $this->assertSame(1, $admin->tokens()->where('name', 'web-session-token')->count());

        $otherToken = $admin->createToken('web-session-token');

        $this->post('/logout')->assertRedirect('/login');

        $this->assertNull(PersonalAccessToken::find($tokenId));
        $this->assertNotNull(PersonalAccessToken::find($otherToken->accessToken->getKey()));
        $this->assertGuest();
    }

    public function test_repeated_dashboard_requests_reuse_session_token(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'repeat@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password']);
        $token = session('api_token');
        $tokenId = session('api_token_id');

        $this->get('/')->assertOk();
        $this->get('/')->assertOk();

        $this->assertSame($token, session('api_token'));
        $this->assertSame($tokenId, session('api_token_id'));
        $this->assertSame(1, $admin->tokens()->where('name', 'web-session-token')->count());
    }

    public function test_dashboard_does_not_create_token_for_inconsistent_session(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'inconsistent@example.test',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->actingAs($admin)->get('/')->assertOk();

        $this->assertSame(0, $admin->tokens()->count());
    }
}

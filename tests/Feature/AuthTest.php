<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}

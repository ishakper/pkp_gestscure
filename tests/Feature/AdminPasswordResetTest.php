<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_reset_target_user_password_and_returns_temp_password(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $targetUser = Admin::create([
            'name' => 'Building Admin Target',
            'email' => 'buildingadmin@example.com',
            'password' => Hash::make('oldpassword'),
            'role' => 'building_admin',
            'must_change_password' => false,
        ]);

        // Create token for target user
        $targetToken = $targetUser->createToken('test-token')->plainTextToken;
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $targetUser->id]);

        $response = $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/accounts/{$targetUser->id}/password");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'account_id' => $targetUser->id,
                    'email' => 'buildingadmin@example.com',
                    'must_change_password' => true,
                ],
            ]);

        $tempPassword = $response->json('data.temporary_password');
        $this->assertNotEmpty($tempPassword);

        // Target user must have must_change_password = true and new hashed password
        $targetUser->refresh();
        $this->assertTrue($targetUser->must_change_password);
        $this->assertTrue(Hash::check($tempPassword, $targetUser->password));

        // Target user tokens must be deleted
        $this->assertEquals(0, $targetUser->tokens()->count());

        // Audit log created without storing temporary password
        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $superAdmin->id,
            'action' => 'admin_password_reset',
        ]);

        $auditLog = ActivityLog::where('action', 'admin_password_reset')->first();
        $this->assertStringNotContainsString($tempPassword, $auditLog->description);
    }

    public function test_non_admin_cannot_reset_password(): void
    {
        $regularUser = Admin::create([
            'name' => 'Regular Employee',
            'email' => 'employee@example.com',
            'password' => Hash::make('password'),
            'role' => 'employee',
        ]);

        $targetUser = Admin::create([
            'name' => 'Target Admin',
            'email' => 'target@example.com',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
        ]);

        $response = $this->actingAs($regularUser, 'sanctum')
            ->patchJson("/api/v1/accounts/{$targetUser->id}/password");

        $response->assertStatus(403);
    }

    public function test_login_with_temp_password_indicates_must_change_password(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $targetUser = Admin::create([
            'name' => 'Target Account',
            'email' => 'target2@example.com',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
        ]);

        // Admin resets password
        $resetResponse = $this->actingAs($superAdmin, 'sanctum')
            ->patchJson("/api/v1/accounts/{$targetUser->id}/password");

        $tempPassword = $resetResponse->json('data.temporary_password');

        // Target user logs in with temporary password
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'target2@example.com',
            'password' => $tempPassword,
        ]);

        $loginResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'admin' => [
                        'email' => 'target2@example.com',
                        'must_change_password' => true,
                    ],
                ],
            ]);
    }

    public function test_user_must_change_password_can_update_password_and_clears_flag(): void
    {
        $user = Admin::create([
            'name' => 'Forced User',
            'email' => 'forced@example.com',
            'password' => Hash::make('TempPass123!'),
            'role' => 'building_admin',
            'must_change_password' => true,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/auth/change-password', [
                'current_password' => 'TempPass123!',
                'new_password' => 'BrandNewPassword123!',
                'new_password_confirmation' => 'BrandNewPassword123!',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'message' => 'Password Anda berhasil diperbarui.',
            ]);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('BrandNewPassword123!', $user->password));

        $this->assertDatabaseHas('activity_logs', [
            'admin_id' => $user->id,
            'action' => 'password_changed',
        ]);
    }
}

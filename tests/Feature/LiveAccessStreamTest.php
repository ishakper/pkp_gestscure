<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveAccessStreamTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_requires_authentication(): void
    {
        $this->get('/live-stream')->assertRedirect('/login');
    }

    public function test_stream_finishes_one_heartbeat_iteration_during_tests(): void
    {
        $admin = Admin::create([
            'name' => 'Stream Admin',
            'email' => 'stream@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $response = $this->actingAs($admin)->get('/live-stream');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertStringContainsString(': heartbeat', $response->streamedContent());
    }
}

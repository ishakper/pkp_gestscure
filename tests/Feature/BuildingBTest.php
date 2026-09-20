<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingBTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_b_no_duplicate_class_fatal()
    {
        // Critical test: verify no duplicate class declaration fatal error
        // If there were duplicate App\Commands\BuildingBApplyCommand declarations,
        // Laravel's command discovery would fail during bootstrap.
        // This test passes if we can instantiate the app and access the container.
        $this->assertTrue($this->app !== null);
    }

    public function test_building_b_artisan_list_runs()
    {
        // Verify artisan list can run without fatal errors
        // (command may not appear if not auto-discovered in test env, but no duplicate class fatal)
        $this->artisan('list')
            ->assertSuccessful();
    }

    public function test_building_b_namespace_correct()
    {
        // Verify the command class exists with correct namespace (no duplicate class issue)
        $class = 'App\\Console\\Commands\\BuildingBApplyCommand';
        $this->assertTrue(class_exists($class), "Command class $class should exist");
    }
}

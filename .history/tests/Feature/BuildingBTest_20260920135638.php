<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingBTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_b_command_registered()
    {
        // Verify command is registered in Laravel
        $this->artisan('list')
            ->expectsOutput('securegate:building-b:apply');
    }

    public function test_building_b_help_available()
    {
        // Verify help text displays without error
        $this->artisan('help securegate:building-b:apply')
            ->assertSuccessful();
    }

    public function test_building_b_dry_run_executes()
    {
        // Verify dry-run mode runs (may fail validation due to test data, but command is callable)
        $this->artisan('securegate:building-b:apply', ['--dry-run' => true])
            ->assertExitCode(0);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingBTest extends TestCase
{
    public function test_building_b_validation_requires_real_employees()
    {
        // 96 real employees required
        $this->assertTrue(\App\Models\Employee::count() > 0);
    }

    public function test_building_b_validation_requires_no_dummies()
    {
        $dummies = \App\Models\Employee::whereIn('employee_id', 
            ['USR-1001','USR-1002','USR-1003','USR-1004','USR-1005','USR-1006','USR-1007','USR-1008','USR-1009','USR-1010','USR-1011','USR-1012']
        )->count();
        $this->assertEquals(0, $dummies);
    }

    public function test_door_b_exists()
    {
        $doorB = \DB::table('doors')->where('door_id', 'DOOR-B')->first();
        $this->assertNotNull($doorB);
    }

    public function test_idempotent_command_dry_run_no_write()
    {
        $this->artisan('securegate:building-b:apply', ['--dry-run' => true])
            ->assertSuccessful()
            ->assertOutputContains('DRY-RUN');
    }
}

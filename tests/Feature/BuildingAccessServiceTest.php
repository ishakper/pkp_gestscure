<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Employee;
use App\Services\BuildingAccessService;
use Tests\TestCase;

class BuildingAccessServiceTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected BuildingAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BuildingAccessService();
    }

    /**
     * Test building access metrics invariant: ELIGIBLE = ASSIGNED + MISSING.
     */
    public function test_building_access_invariant(): void
    {
        $metrics = $this->service->getBuildingAccessMetrics(
            Building::firstOrCreate(
                ['code' => 'BUILDING_B'],
                ['name' => 'Building B', 'is_active' => true]
            )
        );

        $this->assertEquals(
            $metrics['eligible_count'],
            $metrics['assigned_count'] + $metrics['missing_count'],
            'Invariant violated: ELIGIBLE != ASSIGNED + MISSING'
        );
        $this->assertTrue($metrics['invariant_valid']);
    }

    /**
     * Test that metrics are non-negative.
     */
    public function test_building_access_metrics_non_negative(): void
    {
        $metrics = $this->service->getBuildingAccessMetrics(
            Building::firstOrCreate(
                ['code' => 'BUILDING_B'],
                ['name' => 'Building B', 'is_active' => true]
            )
        );

        $this->assertGreaterThanOrEqual(0, $metrics['eligible_count']);
        $this->assertGreaterThanOrEqual(0, $metrics['assigned_count']);
        $this->assertGreaterThanOrEqual(0, $metrics['missing_count']);
    }

    /**
     * Test all buildings metrics maintain invariant.
     */
    public function test_all_buildings_metrics_valid(): void
    {
        Building::firstOrCreate(['code' => 'BUILDING_B'], ['name' => 'Building B', 'is_active' => true]);

        $all_metrics = $this->service->getAllBuildingsAccessMetrics();

        foreach ($all_metrics as $metrics) {
            $this->assertTrue($metrics['invariant_valid']);
        }
    }

    public function test_eligibility_includes_active_excludes_inactive_and_dummy(): void
    {
        Employee::create(['employee_id' => 'USR-2001', 'nik' => 'NIK-2001', 'name' => 'Active', 'department' => 'IT', 'employment_status' => 'ACTIVE']);
        Employee::create(['employee_id' => 'USR-2002', 'nik' => 'NIK-2002', 'name' => 'Inactive', 'department' => 'IT', 'employment_status' => 'INACTIVE']);
        Employee::create(['employee_id' => 'USR-1001', 'nik' => 'NIK-1001', 'name' => 'Dummy', 'department' => 'IT', 'employment_status' => 'ACTIVE']);

        $method = new \ReflectionMethod($this->service, 'getEligibleEmployees');
        $method->setAccessible(true);
        $eligible = $method->invoke($this->service);

        $this->assertSame(['USR-2001'], $eligible->pluck('employee_id')->values()->all());
    }

}

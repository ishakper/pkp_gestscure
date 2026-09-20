<?php

namespace Tests\Feature;

use App\Models\Building;
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
}

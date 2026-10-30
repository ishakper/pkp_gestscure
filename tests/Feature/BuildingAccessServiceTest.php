<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Door;
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

    public function test_can_admin_access_door_returns_false_when_door_not_found(): void
    {
        $admin = (object) ['assigned_building' => 'Building B'];
        $this->assertFalse($this->service->canAdminAccessDoor($admin, 999999));
    }

    public function test_can_admin_access_door_returns_false_when_admin_has_no_building(): void
    {
        $building = Building::firstOrCreate(['code' => 'BLD-X'], ['name' => 'Building X', 'is_active' => true]);
        $door = Door::create([
            'door_id' => 'DOOR-X-'.uniqid(),
            'door_name' => 'Door X',
            'location' => 'Building X',
            'device_ip' => '10.0.99.1',
            'building_id' => $building->id,
        ]);

        $adminWithoutBuilding = (object) ['assigned_building' => null];
        $this->assertFalse($this->service->canAdminAccessDoor($adminWithoutBuilding, $door->id));

        $adminWithEmptyString = (object) ['assigned_building' => ''];
        $this->assertFalse($this->service->canAdminAccessDoor($adminWithEmptyString, $door->id));
    }

    public function test_can_admin_access_door_returns_true_for_exact_match(): void
    {
        $building = Building::firstOrCreate(['code' => 'BLD-A'], ['name' => 'Gedung A', 'is_active' => true]);
        $door = Door::create([
            'door_id' => 'DOOR-A-'.uniqid(),
            'door_name' => 'Door Main A',
            'location' => 'Gedung A',
            'device_ip' => '10.0.99.2',
            'building_id' => $building->id,
        ]);

        $admin = (object) ['assigned_building' => 'Gedung A'];
        $this->assertTrue($this->service->canAdminAccessDoor($admin, $door->id));
    }

    public function test_can_admin_access_door_returns_false_for_different_building(): void
    {
        $building = Building::firstOrCreate(['code' => 'BLD-A'], ['name' => 'Gedung A', 'is_active' => true]);
        $door = Door::create([
            'door_id' => 'DOOR-A2-'.uniqid(),
            'door_name' => 'Door Main A2',
            'location' => 'Gedung A',
            'device_ip' => '10.0.99.3',
            'building_id' => $building->id,
        ]);

        $admin = (object) ['assigned_building' => 'Gedung B'];
        $this->assertFalse($this->service->canAdminAccessDoor($admin, $door->id));
    }

    public function test_can_admin_access_door_rejects_substring_building_names(): void
    {
        $buildingExt = Building::firstOrCreate(['code' => 'BLD-A-EXT'], ['name' => 'Gedung A Extension', 'is_active' => true]);
        $doorExt = Door::create([
            'door_id' => 'DOOR-EXT-'.uniqid(),
            'door_name' => 'Door Extension',
            'location' => 'Gedung A Extension',
            'device_ip' => '10.0.99.4',
            'building_id' => $buildingExt->id,
        ]);

        // Admin assigned to "Gedung A" must NOT access "Gedung A Extension"
        $adminA = (object) ['assigned_building' => 'Gedung A'];
        $this->assertFalse($this->service->canAdminAccessDoor($adminA, $doorExt->id));

        $buildingA = Building::firstOrCreate(['code' => 'BLD-A-MAIN'], ['name' => 'Gedung A', 'is_active' => true]);
        $doorA = Door::create([
            'door_id' => 'DOOR-A-MAIN-'.uniqid(),
            'door_name' => 'Door A Main',
            'location' => 'Gedung A',
            'device_ip' => '10.0.99.5',
            'building_id' => $buildingA->id,
        ]);

        // Admin assigned to "Gedung A Extension" must NOT access "Gedung A"
        $adminExt = (object) ['assigned_building' => 'Gedung A Extension'];
        $this->assertFalse($this->service->canAdminAccessDoor($adminExt, $doorA->id));
    }
}

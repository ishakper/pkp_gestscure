<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * BuildingAccessService computes building access metrics.
 *
 * Invariant: ELIGIBLE = ASSIGNED + MISSING
 *
 * Where:
 * - ELIGIBLE: Employees who should have access to building
 * - ASSIGNED: Employees with confirmed door assignment to a building door
 * - MISSING: Employees eligible but without assignment
 */
class BuildingAccessService
{
    /**
     * Get access metrics for a building.
     *
     * READ-ONLY: No database modifications.
     */
    public function getBuildingAccessMetrics(Building $building): array
    {
        // Eligible employees: all non-dummy employees in system
        $eligible = $this->getEligibleEmployees();
        $eligible_count = $eligible->count();

        // Doors in this building
        $building_doors = Door::where('building_id', $building->id)->pluck('id');

        // Assigned: employees with at least one door assignment in this building
        $assigned = DoorAssignment::whereIn('door_id', $building_doors)
            ->distinct('employee_id')
            ->count('employee_id');

        // Missing: Eligible - Assigned
        $missing = $eligible_count - $assigned;

        return [
            'building_id' => $building->id,
            'building_name' => $building->name,
            'eligible_count' => $eligible_count,
            'assigned_count' => $assigned,
            'missing_count' => max(0, $missing), // Ensure non-negative
            'invariant_valid' => ($eligible_count === ($assigned + max(0, $missing))),
        ];
    }

    /**
     * Get all buildings access metrics.
     */
    public function getAllBuildingsAccessMetrics(): array
    {
        $buildings = Building::where('is_active', true)->get();
        $results = [];

        foreach ($buildings as $building) {
            $results[$building->id] = $this->getBuildingAccessMetrics($building);
        }

        return $results;
    }

    /**
     * Get eligible employees for access (non-dummy).
     *
     * Dummy employees are those with person_no in USR-1001 to USR-1012 range.
     */
    protected function getEligibleEmployees(): Collection
    {
        $dummyRange = range(1001, 1012);
        $dummyPersonNos = array_map(fn($n) => "USR-{$n}", $dummyRange);

        return Employee::whereNotIn('person_no', $dummyPersonNos)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Get count of dummy employees (for cleanup tracking).
     */
    public function getDummyEmployeeCount(): int
    {
        $dummyRange = range(1001, 1012);
        $dummyPersonNos = array_map(fn($n) => "USR-{$n}", $dummyRange);

        return Employee::whereIn('person_no', $dummyPersonNos)->count();
    }
}

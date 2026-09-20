<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Employee;
use App\Models\DoorAssignment;
use App\Models\CredentialRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ProductionNormalizationService handles safe Real96 normalization and Building B entitlement.
 *
 * READ-ONLY audit phase followed by optional write phase with explicit authorization.
 */
class ProductionNormalizationService
{
    /**
     * Known dummy employee person_no range: USR-1001 through USR-1012.
     */
    private const DUMMY_PERSON_NOS = [
        'USR-1001', 'USR-1002', 'USR-1003', 'USR-1004', 'USR-1005', 'USR-1006',
        'USR-1007', 'USR-1008', 'USR-1009', 'USR-1010', 'USR-1011', 'USR-1012',
    ];

    /**
     * Audit Real96 normalization WITHOUT writing.
     *
     * READ-ONLY.
     */
    public function auditReal96Normalization(): array
    {
        $all_employees = Employee::count();
        $dummy_employees = Employee::whereIn('person_no', self::DUMMY_PERSON_NOS)->get();
        $dummy_count = $dummy_employees->count();
        $real_count = $all_employees - $dummy_count;

        // Audit FK references from dummy employees
        $dummy_ids = $dummy_employees->pluck('id')->toArray();

        $audit = [
            'total_employees' => $all_employees,
            'real_employees' => $real_count,
            'dummy_employees' => $dummy_count,
            'dummy_person_nos' => self::DUMMY_PERSON_NOS,
            'dummy_ids_found' => $dummy_ids,
            'fk_references' => [
                'door_assignments' => DoorAssignment::whereIn('employee_id', $dummy_ids)->count(),
                'access_logs' => DB::table('access_logs')->whereIn('employee_id', $dummy_ids)->count(),
                'biometric_statuses' => DB::table('biometric_statuses')->whereIn('employee_id', $dummy_ids)->count(),
                'credential_records' => CredentialRecord::whereIn('employee_id', $dummy_ids)->count(),
                'attendance_evidences' => DB::table('attendance_evidences')->whereIn('employee_id', $dummy_ids)->count(),
            ],
            'target_state' => [
                'total' => 96,
                'real' => 96,
                'dummy' => 0,
            ],
            'invariant_met' => ($real_count === 96 && $dummy_count === 0),
        ];

        Log::info('Real96 normalization audit', $audit);

        return $audit;
    }

    /**
     * WRITE: Delete dummy employees and their temporary references.
     *
     * AUTHORIZATION REQUIRED. No direct production execution.
     */
    public function normalizeReal96(bool $authorized = false): array
    {
        if (!$authorized) {
            return ['status' => 'NOT_AUTHORIZED', 'message' => 'Requires explicit authorization'];
        }

        $dummy_employees = Employee::whereIn('person_no', self::DUMMY_PERSON_NOS)->get();
        $dummy_ids = $dummy_employees->pluck('id')->toArray();

        $results = [
            'authorized' => true,
            'dummy_employees_deleted' => 0,
            'door_assignments_deleted' => 0,
            'credential_records_cleaned' => 0,
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            // Delete dummy door assignments
            $results['door_assignments_deleted'] = DoorAssignment::whereIn('employee_id', $dummy_ids)->delete();

            // Clean dummy credential records (set FK to null)
            $results['credential_records_cleaned'] = CredentialRecord::whereIn('employee_id', $dummy_ids)->update([
                'employee_id' => null,
            ]);

            // Delete dummy employees themselves
            $results['dummy_employees_deleted'] = Employee::whereIn('id', $dummy_ids)->delete();

            DB::commit();

            Log::warning('Real96 normalization executed', $results);
        } catch (\Throwable $e) {
            DB::rollBack();
            $results['errors'][] = $e->getMessage();
            Log::error('Real96 normalization failed', $results);
        }

        return $results;
    }

    /**
     * Audit Building B entitlement state.
     *
     * READ-ONLY.
     */
    public function auditBuildingBEntitlement(): array
    {
        $buildingB = Building::where('code', 'BUILDING_B')->first();
        if (!$buildingB) {
            return ['error' => 'BUILDING_B not found'];
        }

        $service = new BuildingAccessService();
        $metrics = $service->getBuildingAccessMetrics($buildingB);

        return [
            'building_id' => $buildingB->id,
            'building_name' => $buildingB->name,
            'eligible_count' => $metrics['eligible_count'],
            'assigned_count' => $metrics['assigned_count'],
            'missing_count' => $metrics['missing_count'],
            'target_eligible' => 96,
            'target_assigned' => 96,
            'target_missing' => 0,
            'invariant_valid' => $metrics['invariant_valid'],
            'fully_assigned' => ($metrics['assigned_count'] === 96 && $metrics['missing_count'] === 0),
        ];
    }

    /**
     * WRITE: Assign all eligible employees to all Building B doors.
     *
     * AUTHORIZATION REQUIRED.
     */
    public function assignAllEligibleToBuilding B(bool $authorized = false): array
    {
        if (!$authorized) {
            return ['status' => 'NOT_AUTHORIZED'];
        }

        $buildingB = Building::where('code', 'BUILDING_B')->first();
        if (!$buildingB) {
            return ['error' => 'BUILDING_B not found'];
        }

        // Get all real employees
        $eligible_employees = Employee::whereNotIn('person_no', self::DUMMY_PERSON_NOS)
            ->where('is_active', true)
            ->get();

        // Get all doors in Building B
        $building_b_doors = $buildingB->doors()->pluck('id')->toArray();

        $created = 0;
        $existing = 0;
        $errors = [];

        try {
            DB::beginTransaction();

            foreach ($eligible_employees as $employee) {
                foreach ($building_b_doors as $door_id) {
                    $assignment = DoorAssignment::firstOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'door_id' => $door_id,
                        ],
                        [
                            'sync_status' => 'pending',
                        ]
                    );

                    if ($assignment->wasRecentlyCreated) {
                        $created++;
                    } else {
                        $existing++;
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $errors[] = $e->getMessage();
        }

        return [
            'authorized' => true,
            'created_assignments' => $created,
            'existing_assignments' => $existing,
            'errors' => $errors,
            'expected_total' => count($eligible_employees) * count($building_b_doors),
        ];
    }
}

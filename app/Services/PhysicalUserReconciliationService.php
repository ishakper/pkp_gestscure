<?php

namespace App\Services;

use App\Models\Door;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PhysicalUserReconciliationService
{
    public function __construct(
        protected HikvisionIsapiService $isapiService
    ) {}

    /**
     * Reconcile physical Hikvision device users with SecureGate Employee directory.
     *
     * @param Door $door
     * @param bool $apply
     * @param int $limit
     * @return array
     */
    public function reconcile(Door $door, bool $apply = false, int $limit = 200): array
    {
        // 1. Fetch physical users from device
        $userResult = $this->isapiService->fetchUsers($door, $limit);

        if (!($userResult['status'] ?? false)) {
            return [
                'success' => false,
                'error' => $userResult['error'] ?? 'Gagal mengambil user inventory dari perangkat.',
                'stats' => [],
                'records' => [],
            ];
        }

        $deviceUsers = $userResult['users'] ?? [];

        // 2. Fetch physical cards from device to cross-reference card metadata
        // Card data contains ONLY safe booleans and safe card types. No card numbers.
        $cardResult = $this->isapiService->fetchCards($door, $limit);
        $cardMap = [];
        if ($cardResult['status'] ?? false) {
            foreach ($cardResult['cards'] ?? [] as $c) {
                $empNo = trim((string) ($c['employee_no'] ?? ''));
                if ($empNo !== '') {
                    $cardMap[$empNo] = [
                        'card_registered' => true,
                        'card_type' => $c['card_type'] ?? 'normalCard',
                    ];
                }
            }
        }

        // 3. Current SecureGate employees
        $existingEmployees = Employee::all()->keyBy('employee_id');

        $reconciled = [];
        $matched = 0;
        $created = 0;
        $pending = 0;
        $dummyOnlyCount = 0;
        $cardRegisteredCount = 0;
        $noCardCount = 0;
        $conflictCount = 0;
        $unknownCount = 0;

        $seenEmpNos = [];
        $duplicateEmpNos = 0;

        // Categorize physical device users
        foreach ($deviceUsers as $u) {
            $empNo = trim((string) ($u['employee_no'] ?? ''));
            $name = trim((string) ($u['name'] ?? ''));
            $status = (string) ($u['status'] ?? 'true');
            $isActive = in_array(strtolower($status), ['true', '1', 'enable', 'enabled', ''], true);
            
            if ($empNo === '') {
                $unknownCount++;
                continue;
            }

            if (isset($seenEmpNos[$empNo])) {
                $duplicateEmpNos++;
            }
            $seenEmpNos[$empNo] = true;

            $hasCard = isset($cardMap[$empNo]);
            if ($hasCard) {
                $cardRegisteredCount++;
            } else {
                $noCardCount++;
            }

            // Check if matches existing employee
            $existing = $existingEmployees->get($empNo);

            if ($existing) {
                $matched++;
                $reconciled[] = [
                    'employee_id' => $empNo,
                    'name' => $existing->name ?: $name,
                    'device_name' => $name,
                    'action' => 'MATCHED_EXISTING',
                    'card_registered' => $hasCard,
                    'status' => $existing->status,
                ];
            } else {
                // New physical user needing SecureGate identity
                $action = $apply ? 'CREATED' : 'PENDING_CREATION';
                if ($apply) {
                    Employee::create([
                        'employee_id' => $empNo,
                        'name' => $name ?: "Physical User {$empNo}",
                        'nik' => 'ID-' . str_pad($empNo, 6, '0', STR_PAD_LEFT),
                        'department' => 'NEEDS_BUSINESS_DATA',
                        'role' => 'staff',
                        'status' => $isActive ? 'active' : 'inactive',
                        'employment_status' => 'NEEDS_BUSINESS_DATA',
                        'join_date' => now()->toDateString(),
                    ]);
                    $created++;
                } else {
                    $pending++;
                }

                $reconciled[] = [
                    'employee_id' => $empNo,
                    'name' => $name ?: "Physical User {$empNo}",
                    'device_name' => $name,
                    'action' => $action,
                    'card_registered' => $hasCard,
                    'status' => $isActive ? 'active' : 'inactive',
                ];
            }
        }

        // Check dummy employees not present on physical device
        $deviceEmpNos = array_column($deviceUsers, 'employee_no');
        foreach ($existingEmployees as $empId => $emp) {
            if (!in_array($empId, $deviceEmpNos, true)) {
                $dummyOnlyCount++;
                $reconciled[] = [
                    'employee_id' => $empId,
                    'name' => $emp->name,
                    'device_name' => null,
                    'action' => 'DUMMY_ONLY',
                    'card_registered' => false,
                    'status' => $emp->status,
                ];
            }
        }

        return [
            'success' => true,
            'apply' => $apply,
            'door_id' => $door->door_id,
            'door_name' => $door->name,
            'stats' => [
                'device_users' => count($deviceUsers),
                'unique_device_employee_no' => count($seenEmpNos),
                'matched_existing' => $matched,
                'created' => $created,
                'pending_creation' => $pending,
                'conflict' => $conflictCount,
                'unknown' => $unknownCount,
                'dummy_only' => $dummyOnlyCount,
                'card_registered' => $cardRegisteredCount,
                'no_card' => $noCardCount,
                'duplicate_employee_no' => $duplicateEmpNos,
            ],
            'records' => $reconciled,
        ];
    }
}

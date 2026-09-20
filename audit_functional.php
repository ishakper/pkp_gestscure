<?php
/**
 * PHASE 1 AUDIT: Complete Functional Status Matrix
 * Run: php audit_functional.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\AccessLog;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\BiometricStatus;

echo "\n=== PKP SECUREGATE FUNCTIONAL AUDIT — 2026-09-20 ===\n\n";

// PHASE 1: Data Inventory
echo "[ PHASE 1: DATA INVENTORY ]\n";
echo "Employees (active): " . Employee::count() . "\n";
echo "Employees (deleted): " . Employee::onlyTrashed()->count() . "\n";
echo "Doors: " . Door::count() . "\n";
echo "Door Assignments: " . DoorAssignment::count() . "\n";
echo "Access Logs: " . AccessLog::count() . "\n";
echo "Activity Logs: " . ActivityLog::count() . "\n";
echo "Buildings: " . Building::count() . "\n";
echo "Biometric Statuses: " . BiometricStatus::count() . "\n";

// PHASE 2: Dashboard Metrics Validation
echo "\n[ PHASE 2: DASHBOARD METRICS ]\n";
$activeEmployees = Employee::count();
$cardUsers = BiometricStatus::where('card_enrolled', true)->count();
$noCardUsers = $activeEmployees - $cardUsers;
$doorsOnline = Door::where('connection_status', 'online')->count();
$doorsOffline = Door::count() - $doorsOnline;
$accessGranted = AccessLog::where('access_status', 'Granted')->count();
$accessDenied = AccessLog::where('access_status', 'Denied')->count();

echo "Active Employees: $activeEmployees\n";
echo "  - Card Enrolled: $cardUsers\n";
echo "  - No Card: $noCardUsers\n";
echo "Doors Online: $doorsOnline / " . Door::count() . "\n";
echo "Access Logs: Granted=$accessGranted, Denied=$accessDenied\n";

// PHASE 3: Door Details
echo "\n[ PHASE 3: DOOR DEVICES ]\n";
foreach (Door::all() as $door) {
    $assignedCount = $door->doorAssignments()->count();
    echo "  {$door->door_id}: {$door->door_name} @ {$door->device_ip} (status={$door->connection_status}, assigned=$assignedCount)\n";
}

// PHASE 4: Employee Sample
echo "\n[ PHASE 4: EMPLOYEE SAMPLE ]\n";
foreach (Employee::limit(5)->get() as $emp) {
    $bio = $emp->biometricStatus;
    $fp_status = $bio && $bio->fingerprint_enrolled ? 'YES' : 'NO';
    $card_status = $bio && $bio->card_enrolled ? 'YES' : 'NO';
    $doors = $emp->doorAssignments()->count();
    echo "  {$emp->employee_id} ({$emp->nik}) — FP=$fp_status, Card=$card_status, Doors=$doors\n";
}

// PHASE 5: Door Assignment Sync Status
echo "\n[ PHASE 5: SYNC STATUS CHECK ]\n";
$syncedCount = DoorAssignment::where('sync_status', 'synced')->count();
$pendingCount = DoorAssignment::where('sync_status', 'pending')->count();
$failedCount = DoorAssignment::where('sync_status', 'failed')->count();
echo "Synced: $syncedCount\n";
echo "Pending: $pendingCount\n";
echo "Failed: $failedCount\n";

if ($failedCount > 0) {
    echo "\nFailed Assignments (sample):\n";
    foreach (DoorAssignment::where('sync_status', 'failed')->limit(3)->get() as $fail) {
        echo "  - {$fail->employee_id} → {$fail->door_id} (attempts={$fail->sync_attempts})\n";
    }
}

// PHASE 6: Access Event Classification
echo "\n[ PHASE 6: ACCESS EVENT CLASSIFICATION ]\n";
$eventTypes = DB::table('access_logs')
    ->select('access_status', 'verify_method', DB::raw('count(*) as count'))
    ->groupBy('access_status', 'verify_method')
    ->get();
echo "By Status & Method:\n";
foreach ($eventTypes as $evt) {
    echo "  {$evt->access_status} via {$evt->verify_method}: {$evt->count}\n";
}

// PHASE 7: Unmapped Identities
echo "\n[ PHASE 7: IDENTITY RESOLUTION CHECK ]\n";
$unmappedCount = AccessLog::whereNull('employee_id')->count();
$mappedCount = AccessLog::whereNotNull('employee_id')->count();
echo "Mapped Access Events: $mappedCount\n";
echo "Unmapped Events (unknown identity): $unmappedCount\n";

if ($unmappedCount > 0 && $unmappedCount <= 10) {
    echo "\nUnmapped Events (sample):\n";
    foreach (AccessLog::whereNull('employee_id')->limit(5)->get() as $log) {
        echo "  - {$log->timestamp}: {$log->nik} → {$log->door_id} ({$log->access_status}, {$log->verify_method})\n";
    }
}

// PHASE 8: Missing Relationships
echo "\n[ PHASE 8: DATA INTEGRITY CHECK ]\n";
$orphanAssignments = DoorAssignment::whereNotNull('employee_id')
    ->whereNotExists(function ($q) {
        $q->selectRaw(1)
            ->from('employees')
            ->whereRaw('employees.id = door_assignments.employee_id');
    })->count();
echo "Orphan Door Assignments: $orphanAssignments\n";

$orphanLogs = AccessLog::whereNotNull('employee_id')
    ->whereNotExists(function ($q) {
        $q->selectRaw(1)
            ->from('employees')
            ->whereRaw('employees.id = access_logs.employee_id');
    })->count();
echo "Orphan Access Logs: $orphanLogs\n";

echo "\n=== END AUDIT ===\n\n";

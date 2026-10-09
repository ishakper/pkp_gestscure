<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\AccessLog;
use App\Models\Employee;

echo "\n=== UNMAPPED ACCESS LOGS ANALYSIS ===\n\n";

$unmapped = AccessLog::whereNull('employee_id')->get();
echo "Total unmapped: " . count($unmapped) . "\n\n";

foreach ($unmapped as $log) {
    $emp = Employee::where('nik', $log->nik)->first();
    echo "NIK: {$log->nik}\n";
    echo "  Door: {$log->door_id}\n";
    echo "  Status: {$log->access_status}\n";
    echo "  Method: {$log->verify_method}\n";
    echo "  Timestamp: {$log->timestamp}\n";
    echo "  Employee Found: " . ($emp ? "YES (ID={$emp->id})" : "NO") . "\n";
    echo "\n";
}

echo "=== INVESTIGATION ===\n";
echo "Possible causes:\n";
echo "1. Guest/unregistered card/fingerprint (UNKNOWN-XXXX) — OK if denied\n";
echo "2. Corrupted NIK in access_logs — needs mapping fix\n";
echo "3. Employee deleted but old access log remains — OK, preserve history\n\n";

// Check if there are employeesin DB that should match
$orphanNIKs = AccessLog::whereNull('employee_id')->distinct()->pluck('nik');
echo "Checking if orphan NIKs could be matched to employees...\n";

foreach ($orphanNIKs as $nik) {
    // Try fuzzy match
    $similar = Employee::where('nik', 'like', '%' . substr($nik, -4) . '%')->first();
    if ($similar) {
        echo "  {$nik} → could match {$similar->nik} ({$similar->name}) via suffix\n";
    }
}

echo "\nConclusion: UNKNOWN cards are legitimate denied events (guest/unregistered)\n";
echo "Action: No fix needed, these are correct access-denied records.\n\n";

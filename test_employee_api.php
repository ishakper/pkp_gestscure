<?php
/**
 * TEST: Employee API endpoint response
 */
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Employee;
use App\Models\Admin;
use Laravel\Sanctum\Sanctum;

echo "\n=== EMPLOYEE API TEST ===\n\n";

// Create or get admin for auth
$admin = Admin::first() ?? Admin::create([
    'name' => 'Test Admin',
    'email' => 'test.admin@test.local',
    'password' => bcrypt('password'),
    'role' => 'super_admin',
]);

// Fake auth as admin
Sanctum::actingAs($admin);

// Direct API call test (simulating request)
$request = app(\Illuminate\Http\Request::class);
$request->setUserResolver(fn() => $admin);

$controller = app(\App\Http\Controllers\Api\V1\EmployeeController::class);
$response = $controller->index($request);

echo "Response Status: " . $response->getStatusCode() . "\n\n";
echo "Response Content:\n";
$content = json_decode($response->getContent(), true);
echo json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "Analysis:\n";
echo "- Status field: " . ($content['status'] ?? 'MISSING') . "\n";
echo "- Pagination: " . json_encode($content['pagination'] ?? 'MISSING') . "\n";
echo "- Data count: " . count($content['data'] ?? []) . "\n";
echo "- First employee: " . json_encode($content['data'][0] ?? 'NONE') . "\n\n";

if (count($content['data'] ?? []) === 0) {
    echo "⚠️  WARNING: API returns empty data array!\n";
    echo "Investigating...\n\n";
    
    echo "Database Check:\n";
    echo "Total employees in DB: " . Employee::count() . "\n";
    $emp = Employee::first();
    if ($emp) {
        echo "Sample employee: {$emp->name} (ID={$emp->id}, NIK={$emp->nik})\n";
        echo "Has biometricStatus? " . ($emp->biometricStatus ? "YES" : "NO") . "\n";
        echo "Has building? " . ($emp->building ? "YES (ID={$emp->building->id})" : "NO") . "\n";
    }
} else {
    echo "✅ API returns employees correctly!\n";
}

echo "\n";

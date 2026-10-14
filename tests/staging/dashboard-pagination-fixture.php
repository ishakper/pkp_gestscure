<?php
$root = is_file('/var/www/html/vendor/autoload.php') ? '/var/www/html' : dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Building;
use App\Models\Employee;

$connection = config('database.default');
$database = config('database.connections.pgsql.database');
if ($connection !== 'pgsql' || $database !== 'securegate_staging') {
    throw new RuntimeException('Refusing non-disposable staging database');
}
$run = preg_replace('/[^a-z0-9]/i', '', 'dash' . date('YmdHis') . bin2hex(random_bytes(2)));
$a = Building::create(['code' => strtoupper($run . 'A'), 'name' => 'Dashboard A ' . $run, 'is_active' => true]);
$b = Building::create(['code' => strtoupper($run . 'B'), 'name' => 'Dashboard B ' . $run, 'is_active' => true]);
foreach (range(1, 121) as $i) { Employee::create(['employee_id' => strtoupper($run . '-A-' . $i), 'nik' => strtoupper($run . '-N-A-' . $i), 'name' => 'Dashboard A ' . $i, 'department' => 'Fixture', 'role' => 'Staff', 'building_id' => $a->id, 'employment_status' => 'ACTIVE']); }
foreach (range(1, 37) as $i) { Employee::create(['employee_id' => strtoupper($run . '-B-' . $i), 'nik' => strtoupper($run . '-N-B-' . $i), 'name' => 'Dashboard B ' . $i, 'department' => 'Fixture', 'role' => 'Staff', 'building_id' => $b->id, 'employment_status' => 'INACTIVE']); }
$aTotal = Employee::where('building_id', $a->id)->count();
$bTotal = Employee::where('building_id', $b->id)->count();
if ($aTotal !== 121 || $bTotal !== 37) { throw new RuntimeException('Fixture count mismatch'); }
echo json_encode(['run' => $run, 'database' => $database, 'building_a' => $a->id, 'building_b' => $b->id, 'a_total' => $aTotal, 'b_total' => $bTotal], JSON_THROW_ON_ERROR);


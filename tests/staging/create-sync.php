<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'securegate_staging') throw new RuntimeException('wrong disposable database');
$run = preg_replace('/[^a-z0-9-]/i', '-', $argv[4] ?? date('YmdHis'));
if (($argv[1] ?? '') === 'new') {
    $building = App\Models\Building::create(['code' => 'B-' . $run, 'name' => 'Batch1 ' . $run]);
    $door = App\Models\Door::create(['door_id' => 'D-' . $run, 'door_name' => 'Batch1 Door ' . $run, 'location' => $building->name, 'building_id' => $building->id, 'device_ip' => '192.0.2.10', 'connection_status' => 'online']);
    $employee = App\Models\Employee::create(['employee_id' => 'B1-' . $run, 'nik' => 'B1N-' . $run, 'name' => 'Batch1 ' . $run, 'department' => 'QA', 'role' => 'Staff', 'employment_status' => 'ACTIVE']);
    $credential = App\Models\CredentialRecord::create(['credential_number' => 'B1C-' . $run, 'employee_id' => $employee->id, 'credential_type' => 'CARD', 'card_number' => 'B1CARD-' . $run, 'masked_identifier' => '****' . substr($run, -4), 'status' => 'ACTIVE']);
    App\Models\DoorAssignment::create(['employee_id' => $employee->id, 'door_id' => $door->id, 'sync_status' => 'pending']);
} else {
    $employee = App\Models\Employee::findOrFail((int) ($argv[1] ?? 19));
    $door = App\Models\Door::findOrFail((int) ($argv[2] ?? 1));
    $credential = App\Models\CredentialRecord::where('employee_id', $employee->id)->where('status','ACTIVE')->firstOrFail();
    if (!App\Models\DoorAssignment::where('employee_id',$employee->id)->where('door_id',$door->id)->exists()) throw new RuntimeException('missing assignment');
}
$sync = App\Models\CredentialDeviceSync::create(['door_id'=>$door->id,'credential_record_id'=>$credential->id,'employee_id'=>$employee->id,'operation'=>'ADD','status'=>'QUEUED','attempt_count'=>0,'idempotency_key'=>'batch1-runtime-'.bin2hex(random_bytes(6))]);
App\Jobs\ProcessCredentialDeviceSyncJob::dispatch($sync->id)->onQueue($argv[3] ?? 'batch1-test');
echo json_encode(['id'=>$sync->id,'employee_id'=>$employee->id,'door_id'=>$door->id,'credential_id'=>$credential->id,'queue'=>$argv[3] ?? 'batch1-test'], JSON_THROW_ON_ERROR), PHP_EOL;


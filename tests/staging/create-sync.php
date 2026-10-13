<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') !== 'securegate_staging') { throw new RuntimeException('wrong disposable database'); }
$employee = App\Models\Employee::findOrFail((int) ($argv[1] ?? 19));
$door = App\Models\Door::findOrFail((int) ($argv[2] ?? 1));
$credential = App\Models\CredentialRecord::where('employee_id', $employee->id)->where('status','ACTIVE')->firstOrFail();
if (!App\Models\DoorAssignment::where('employee_id',$employee->id)->where('door_id',$door->id)->exists()) throw new RuntimeException('missing assignment');
$sync = App\Models\CredentialDeviceSync::create(['door_id'=>$door->id,'credential_record_id'=>$credential->id,'employee_id'=>$employee->id,'operation'=>'ADD','status'=>'QUEUED','attempt_count'=>0,'idempotency_key'=>'batch1-runtime-'.bin2hex(random_bytes(6))]);
App\Jobs\ProcessCredentialDeviceSyncJob::dispatch($sync->id);
echo json_encode(['id'=>$sync->id,'employee_id'=>$employee->id,'door_id'=>$door->id,'credential_id'=>$credential->id], JSON_THROW_ON_ERROR), PHP_EOL;


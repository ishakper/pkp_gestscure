<?php
namespace App\Jobs;
use App\Models\ActivityLog;
use App\Models\CredentialDeviceSync;
use App\Services\HikvisionIsapiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
use Illuminate\Support\Str;
class ProcessCredentialDeviceSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public array $backoff = [5, 30, 120];
    public bool $networkStarted = false;
    private ?string $leaseToken = null;
    private const LEASE_SECONDS = 120;
    public function __construct(public int $syncId) {}
    public function handle(HikvisionIsapiService $device): void
    {
        $queued = CredentialDeviceSync::find($this->syncId);
        if (!$queued) return;
        $this->leaseToken = (string) Str::uuid();
        $lock = Cache::lock("device-sync:target:{$queued->employee_id}:{$queued->door_id}", self::LEASE_SECONDS);
        if (!$lock->get()) throw new \RuntimeException('Target device sync is already processing');
        try {
            $sync = DB::transaction(function (): ?CredentialDeviceSync {
                $row = CredentialDeviceSync::with(['door', 'employee'])->lockForUpdate()->find($this->syncId);
                if (!$row || $row->status !== 'QUEUED') return null;
                $unresolved = CredentialDeviceSync::query()
                    ->where('employee_id', $row->employee_id)
                    ->where('door_id', $row->door_id)
                    ->where('status', 'UNKNOWN')
                    ->where('id', '!=', $row->id)
                    ->exists();
                if ($unresolved) {
                    $row->update([
                        'status' => 'CANCELLED',
                        'error_summary' => 'Blocked by unresolved device outcome for the same employee and door; reconcile before creating a new intent.',
                        'completed_at' => now(),
                    ]);
                    return null;
                }
                $row->update([
                    'status' => 'PROCESSING',
                    'attempt_count' => $row->attempt_count + 1,
                    'last_attempt_at' => now(),
                    'lease_token' => $this->leaseToken,
                    'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                    'network_started_at' => null,
                ]);
                return $row->fresh(['door', 'employee', 'credentialRecord']);
            });
            if (!$sync) return;

            $result = $this->process($sync, $device);
            if ($result['status'] && $sync->operation === 'ADD' && !\App\Models\DoorAssignment::where('employee_id', $sync->employee_id)->where('door_id', $sync->door_id)->exists()) {
                $result = ['status' => false, 'error' => 'STALE_AFTER_DEVICE_WRITE: adapter accepted ADD after application assignment was removed; physical state is unknown'];
            }
            if ($result['status']) {
                $this->finish($sync, 'SUCCESS', null);
                $this->audit($sync, 'device_sync_succeeded', $result['message']);
            } else {
                $unknown = $this->networkStarted && $this->isAmbiguousDeviceFailure((string) $result['error']);
                $status = $unknown ? 'UNKNOWN' : 'FAILED';
                $this->finish($sync, $status, $result['error']);
                $this->audit($sync, $unknown ? 'device_sync_unknown' : 'device_sync_failed', $unknown ? 'Device response is unresolved; reconciliation required before retry.' : $result['error']);
            }
        } finally { $lock->release(); }
    }
    private function process(CredentialDeviceSync $sync, HikvisionIsapiService $device): array
    {
        if ($sync->operation === 'REVOKE') return ['status' => false, 'error' => 'DEVICE_REVOKE_UNSUPPORTED: adapter has no target-door revoke operation'];
        if ($sync->operation !== 'ADD') return ['status' => false, 'error' => 'Unsupported device sync operation'];
        if (!\App\Models\DoorAssignment::where('employee_id', $sync->employee_id)->where('door_id', $sync->door_id)->exists()) return ['status' => false, 'error' => 'Stale ADD ignored: desired access assignment no longer exists'];
        if (!$sync->door || !$sync->employee) return ['status' => false, 'error' => 'Device sync target no longer exists'];
        $this->networkStarted = true;
        $sync->update(['network_started_at' => now()]);
        $result = $device->provisionEmployeeAccess($sync->door, $sync->employee, [
            'before_mutation' => function (string $step) use ($sync): void {
                $owned = CredentialDeviceSync::query()
                    ->whereKey($sync->id)
                    ->where('status', 'PROCESSING')
                    ->where('lease_token', $this->leaseToken)
                    ->where('lease_expires_at', '>', now())
                    ->exists();
                if (!$owned) {
                    throw new \RuntimeException("Device sync lease lost before {$step} mutation");
                }
            },
        ]);
        return $result['status'] ? ['status' => true, 'message' => 'Target-door provisioning accepted by adapter; physical verification not performed'] : ['status' => false, 'error' => (string) ($result['error'] ?? 'Device adapter failed')];
    }
    private function isAmbiguousDeviceFailure(string $error): bool
    {
        return str_contains(strtolower($error), 'timeout') || str_contains(strtolower($error), 'connection error') || str_contains(strtolower($error), 'may have been accepted');
    }

    private function audit(CredentialDeviceSync $sync, string $action, string $description): void
    { ActivityLog::create(['admin_id' => null, 'action' => $action, 'subject_type' => 'CredentialDeviceSync', 'subject_id' => $sync->id, 'description' => $description, 'timestamp' => now()]); }
    public function failed(Throwable $exception): void
    {
        $sync = CredentialDeviceSync::find($this->syncId); if (!$sync) return;
        $unknown = $this->networkStarted;
        $sync->newQuery()->whereKey($sync->id)->where('lease_token', $this->leaseToken)->update([
            'status' => $unknown ? 'UNKNOWN' : 'FAILED',
            'error_summary' => ($unknown ? 'UNKNOWN: network call may have been accepted; reconciliation required. ' : 'Worker failed before device request. ') . $exception->getMessage(),
            'lease_token' => null,
            'lease_expires_at' => null,
            'completed_at' => now(),
        ]);
        $this->audit($sync, $unknown ? 'device_sync_unknown' : 'device_sync_worker_failed', $unknown ? 'Worker failed after device request started; physical outcome is unresolved.' : 'Worker failed before device request.');
    }

    private function finish(CredentialDeviceSync $sync, string $status, ?string $error): void
    {
        $sync->newQuery()->whereKey($sync->id)->where('lease_token', $this->leaseToken)->update([
            'status' => $status,
            'completed_at' => now(),
            'error_summary' => $error,
            'lease_token' => null,
            'lease_expires_at' => null,
        ]);
    }
}

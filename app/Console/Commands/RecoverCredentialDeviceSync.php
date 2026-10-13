<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\CredentialDeviceSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverCredentialDeviceSync extends Command
{
    protected $signature = 'device-sync:recover {--before= : ISO-8601 cutoff for an explicit recovery window}';
    protected $description = 'Classify orphaned device sync leases without replaying device mutations';

    public function handle(): int
    {
        $cutoff = $this->option('before') ? now()->parse($this->option('before')) : now();
        $count = 0;
        CredentialDeviceSync::query()
            ->where('status', 'PROCESSING')
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $cutoff)
            ->orderBy('id')
            ->eachById(function (CredentialDeviceSync $sync) use (&$count) {
                $updated = DB::table('credential_device_syncs')
                    ->where('id', $sync->id)
                    ->where('status', 'PROCESSING')
                    ->where('lease_token', $sync->lease_token)
                    ->where('lease_expires_at', '<', now())
                    ->update([
                        'status' => 'UNKNOWN',
                        'error_summary' => 'UNKNOWN: worker lease expired; reconciliation required before any retry.',
                        'completed_at' => now(),
                        'lease_token' => null,
                        'lease_expires_at' => null,
                    ]);
                if ($updated) {
                    ActivityLog::create([
                        'admin_id' => null,
                        'action' => 'device_sync_recovered_unknown',
                        'subject_type' => 'CredentialDeviceSync',
                        'subject_id' => $sync->id,
                        'description' => 'Expired worker lease classified as UNKNOWN; no device mutation replayed.',
                        'timestamp' => now(),
                    ]);
                    $count++;
                }
            });
        $this->info("Recovered {$count} orphaned sync job(s).");
        return self::SUCCESS;
    }
}

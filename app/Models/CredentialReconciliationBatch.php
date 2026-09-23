<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialReconciliationBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'source_filename',
        'source_sha256',
        'mode', // dry-run or apply
        'started_at',
        'completed_at',
        'operator',
        'source_rows',
        'exact_matches',
        'source_only_valid',
        'application_only',
        'nameless_source',
        'duplicate_ids',
        'case_conflicts',
        'credential_conflicts',
        'card_confirmed',
        'fingerprint_expected',
        'fingerprint_verified',
        'unknown',
        'review',
        'created_employees',
        'updated_employees',
        'staged_candidates',
        'unchanged_employees',
        'skipped_employees',
        'door_assignments_created',
        'physical_device_requests',
        'status', // success, partial, failed
        'error_message',
        'rollback_data',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'rollback_data' => 'array',
    ];

    public function audits()
    {
        return $this->hasMany(CredentialReconciliationAudit::class, 'batch_id', 'batch_id');
    }
}

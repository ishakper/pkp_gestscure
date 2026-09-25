<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialReconciliationAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'employee_id',
        'source_person_number',
        'source_display_name',
        'source_card_status',
        'source_card_count',
        'source_card_type',
        'action', // created, updated, unchanged, skipped, review
        'before_credential_method',
        'before_credential_status',
        'after_credential_method',
        'after_credential_status',
        'conflict_reason',
        'notes',
    ];

    public function batch()
    {
        return $this->belongsTo(CredentialReconciliationBatch::class, 'batch_id', 'batch_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}

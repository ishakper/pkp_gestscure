<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialDeviceSync extends Model
{
    use HasFactory;

    protected $fillable = [
        'door_id',
        'credential_record_id',
        'employee_id',
        'operation',
        'status',
        'attempt_count',
        'last_attempt_at',
        'error_summary',
        'completed_at',
        'idempotency_key',
    ];

    protected $casts = [
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function door() { return $this->belongsTo(Door::class); }
    public function credentialRecord() { return $this->belongsTo(CredentialRecord::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
}

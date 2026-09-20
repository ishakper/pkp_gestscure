<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CredentialRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'credential_number',
        'employee_id',
        'internship_id',
        'credential_type',
        'card_number',
        'card_number_hash',
        'masked_identifier',
        'external_reference',
        'biometric_status',
        'status',
        'issued_at',
        'activated_at',
        'expires_at',
        'revoked_at',
        'revocation_reason',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // Hide unmasked card_number and card_number_hash from JSON serialization by default for security
    protected $hidden = [
        'card_number',
        'card_number_hash',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }

    public function deviceSyncs()
    {
        return $this->hasMany(CredentialDeviceSync::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_number',
        'employee_id',
        'internship_id',
        'access_profile_id',
        'specific_doors',
        'building_name',
        'business_reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'valid_from',
        'valid_until',
        'notes',
    ];

    protected $casts = [
        'specific_doors' => 'array',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'approved_at' => 'datetime',
    ];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function internship() { return $this->belongsTo(Internship::class); }
    public function accessProfile() { return $this->belongsTo(AccessProfile::class); }
    public function requestedByAdmin() { return $this->belongsTo(Admin::class, 'requested_by'); }
    public function approvedByAdmin() { return $this->belongsTo(Admin::class, 'approved_by'); }
}

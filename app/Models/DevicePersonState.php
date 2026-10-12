<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DevicePersonState extends Model
{
    protected $fillable = [
        'door_id', 'device_employee_no', 'device_name', 'device_enabled', 'card_count', 'card_hashes', 'card_masks',
        'fingerprint_count', 'face_count', 'present_on_device', 'employee_id', 'candidate_employee_id', 'match_basis',
        'confidence', 'status', 'device_link_status', 'identity_status', 'card_status', 'fingerprint_status', 'reasons', 'last_run_id', 'last_seen_at', 'last_verified_at',
    ];

    // Card hashes are only used server-side for matching.
    protected $hidden = ['card_hashes'];

    protected $casts = [
        'card_hashes' => 'array',
        'card_masks' => 'array',
        'reasons' => 'array',
        'present_on_device' => 'boolean',
        'card_count' => 'integer',
        'fingerprint_count' => 'integer',
        'face_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    public function door()
    {
        return $this->belongsTo(Door::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function candidate()
    {
        return $this->belongsTo(Employee::class, 'candidate_employee_id');
    }
}

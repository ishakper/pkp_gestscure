<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceReconciliationDoorResult extends Model
{
    protected $fillable = ['run_id', 'door_id', 'reachable', 'error', 'total_users', 'total_cards', 'total_fingerprints', 'counts', 'verified_at'];

    protected $casts = [
        'reachable' => 'boolean',
        'counts' => 'array',
        'verified_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(DeviceReconciliationRun::class, 'run_id');
    }

    public function door()
    {
        return $this->belongsTo(Door::class);
    }
}

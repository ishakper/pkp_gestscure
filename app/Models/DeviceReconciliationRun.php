<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceReconciliationRun extends Model
{
    protected $fillable = ['mode', 'triggered_by', 'status', 'door_ids', 'summary', 'started_at', 'finished_at'];

    protected $casts = [
        'door_ids' => 'array',
        'summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function doorResults()
    {
        return $this->hasMany(DeviceReconciliationDoorResult::class, 'run_id');
    }

    public function triggeredBy()
    {
        return $this->belongsTo(Admin::class, 'triggered_by');
    }
}

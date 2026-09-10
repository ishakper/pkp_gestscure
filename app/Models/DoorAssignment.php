<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DoorAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'door_id',
        'sync_status',
        'sync_attempts',
        'last_sync_error',
        'last_synced_at',
        'user_info_synced_at',
        'card_synced_at',
        'sync_type',
        'last_payload',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'user_info_synced_at' => 'datetime',
        'card_synced_at' => 'datetime',
        'sync_attempts' => 'integer',
        'last_payload' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function door()
    {
        return $this->belongsTo(Door::class);
    }
}

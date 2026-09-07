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
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'sync_attempts' => 'integer',
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

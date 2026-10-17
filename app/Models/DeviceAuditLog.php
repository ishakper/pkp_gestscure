<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeviceAuditLog extends Model
{
    use HasFactory;

    public const PENDING = 'PENDING';
    public const FAILED = 'FAILED';
    public const SUCCESS = 'SUCCESS';
    public const EXHAUSTED = 'EXHAUSTED';

    protected $fillable = [
        'door_assignment_id',
        'payload_hash',
        'payload_data',
        'status',
        'attempts',
        'error_message',
    ];

    protected $casts = [
        'payload_data' => 'array',
        'attempts' => 'integer',
    ];

    public function assignment()
    {
        return $this->belongsTo(DoorAssignment::class, 'door_assignment_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'building_name',
        'allowed_doors',
        'schedule_type',
        'start_time',
        'end_time',
        'is_active',
        'employment_type_restrictions',
    ];

    protected $casts = [
        'allowed_doors' => 'array',
        'employment_type_restrictions' => 'array',
        'is_active' => 'boolean',
    ];

    public function accessRequests()
    {
        return $this->hasMany(AccessRequest::class);
    }
}

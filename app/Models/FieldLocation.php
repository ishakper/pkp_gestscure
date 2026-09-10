<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FieldLocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'project_name',
        'client_name',
        'site_address',
        'latitude',
        'longitude',
        'radius_meters',
        'valid_from',
        'valid_until',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'integer',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function assignments()
    {
        return $this->hasMany(FieldAssignment::class);
    }

    public function evidences()
    {
        return $this->hasMany(FieldAttendanceEvidence::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $today = now()->toDateString();

        if ($this->valid_from && $today < $this->valid_from->toDateString()) {
            return false;
        }

        if ($this->valid_until && $today > $this->valid_until->toDateString()) {
            return false;
        }

        return true;
    }
}

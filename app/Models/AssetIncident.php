<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetIncident extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_number',
        'asset_id',
        'reported_by',
        'employee_id',
        'incident_type',
        'incident_date',
        'location',
        'description',
        'resolution',
        'resolved_at',
        'status',
    ];

    protected $casts = [
        'incident_date' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function reportedByAdmin()
    {
        return $this->belongsTo(Admin::class, 'reported_by');
    }
}

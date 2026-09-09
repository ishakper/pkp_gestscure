<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_number',
        'asset_id',
        'employee_id',
        'internship_id',
        'assigned_by',
        'assigned_at',
        'expected_return_date',
        'actual_return_date',
        'condition_out',
        'condition_in',
        'accessories',
        'handover_notes',
        'return_notes',
        'received_by',
        'status',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'expected_return_date' => 'date',
        'actual_return_date' => 'datetime',
        'accessories' => 'array',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function internship()
    {
        return $this->belongsTo(Internship::class);
    }

    public function assignedByAdmin()
    {
        return $this->belongsTo(Admin::class, 'assigned_by');
    }

    public function receivedByAdmin()
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }
}

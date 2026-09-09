<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AssetMaintenance extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_number',
        'asset_id',
        'maintenance_type',
        'issue_description',
        'vendor',
        'cost',
        'opened_at',
        'completed_at',
        'status',
        'result',
        'notes',
        'performed_by',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'completed_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function performedByAdmin()
    {
        return $this->belongsTo(Admin::class, 'performed_by');
    }
}

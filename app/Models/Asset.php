<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_code',
        'asset_name',
        'category_id',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'purchase_price',
        'vendor',
        'warranty_start',
        'warranty_end',
        'building_name',
        'location',
        'division_id',
        'status',
        'condition',
        'notes',
        'disposed_at',
        'disposal_reason',
        'disposal_method',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_start' => 'date',
        'warranty_end' => 'date',
        'disposed_at' => 'datetime',
        'purchase_price' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    public function division()
    {
        return $this->belongsTo(Division::class);
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function currentAssignment()
    {
        return $this->hasOne(AssetAssignment::class)->where('status', 'ACTIVE')->latestOfMany();
    }

    public function maintenances()
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    public function incidents()
    {
        return $this->hasMany(AssetIncident::class);
    }

    public function getMaskedSerialNumberAttribute(): ?string
    {
        if (!$this->serial_number) {
            return null;
        }
        $len = strlen($this->serial_number);
        if ($len <= 4) {
            return '****' . $this->serial_number;
        }
        return str_repeat('*', $len - 4) . substr($this->serial_number, -4);
    }
}

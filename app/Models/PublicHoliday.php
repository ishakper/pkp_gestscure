<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PublicHoliday — date-level holiday overrides (national, company, or regional).
 * building_id = null means company-wide.
 */
class PublicHoliday extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'holiday_date',
        'name',
        'type',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];
}

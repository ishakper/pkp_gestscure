<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecuregateMetric extends Model
{
    protected $table = 'securegate_metrics';
    protected $primaryKey = 'metric_key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'metric_key',
        'name',
        'labels_json',
        'value',
    ];

    protected $casts = [
        'value' => 'integer',
    ];
}

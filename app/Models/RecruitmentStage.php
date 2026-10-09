<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecruitmentStage extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'sequence',
        'is_active',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'is_active' => 'boolean',
    ];
}

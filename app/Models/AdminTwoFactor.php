<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminTwoFactor extends Model
{
    protected $table = 'admin_two_factor';

    protected $fillable = [
        'admin_id',
        'secret',
        'recovery_codes',
        'confirmed_at',
        'last_used_step',
        'failed_attempts',
        'locked_until',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'recovery_codes' => 'encrypted:array',
        'confirmed_at' => 'datetime',
        'locked_until' => 'datetime',
        'last_used_step' => 'integer',
        'failed_attempts' => 'integer',
    ];

    protected $hidden = ['secret', 'recovery_codes'];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }
}

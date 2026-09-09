<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'assigned_building',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function portal(): string { return app(\App\Services\PortalAccess::class)->portalFor($this); }
    public function hasPermission(string $permission): bool { return app(\App\Services\PortalAccess::class)->can($this, $permission); }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isBuildingAdmin(): bool
    {
        return $this->role === 'building_admin';
    }
}

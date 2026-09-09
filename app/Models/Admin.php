<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Admin extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = ['super_admin','building_admin','hrd','management','supervisor','employee','intern','infra_admin','developer','devops','security_engineer'];

    protected static function booted(): void
    {
        static::saving(function (self $admin): void {
            if (!in_array($admin->role, self::ROLES, true)) {
                throw new \InvalidArgumentException('Invalid admin role.');
            }
        });
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'assigned_building',
        'employee_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function employee() { return $this->belongsTo(\App\Models\Employee::class); }

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

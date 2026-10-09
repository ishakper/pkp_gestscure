<?php

namespace App\Policies;

use App\Models\Admin;
use App\Services\PortalAccess;

/**
 * AttendancePolicy — RBAC gate for all attendance operations.
 */
class AttendancePolicy
{
    protected PortalAccess $portalAccess;

    public function __construct(PortalAccess $portalAccess)
    {
        $this->portalAccess = $portalAccess;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.view');
    }

    public function view(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.view');
    }

    public function create(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.manage');
    }

    public function update(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.manage');
    }

    public function verify(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.verify');
    }

    public function manageCalendar(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.calendar.manage');
    }

    public function manageHoliday(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.calendar.manage');
    }

    /** Self-service: employees viewing their own attendance. */
    public function self(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'attendance.self');
    }
}

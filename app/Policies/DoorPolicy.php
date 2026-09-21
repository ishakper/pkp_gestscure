<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Door;
use App\Services\PortalAccess;

class DoorPolicy
{
    public function __construct(private readonly PortalAccess $portalAccess) {}
    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'device.view');
    }

    public function view(Admin $admin, Door $door): bool
    {
        if (!$this->portalAccess->can($admin, 'device.view')) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === null || $admin->assigned_building === $door->location;
    }

    public function overrideStatus(Admin $admin, Door $door): bool
    {
        if (!$this->portalAccess->can($admin, 'device.manage')) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === null || $admin->assigned_building === $door->location;
    }

    public function open(Admin $admin, Door $door): bool
    {
        if (!$this->portalAccess->can($admin, 'device.manage')) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === null || $admin->assigned_building === $door->location;
    }

    public function physicalControl(Admin $admin, Door $door): bool
    {
        return $this->open($admin, $door);
    }
}

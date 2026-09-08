<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Door;

class DoorPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return true;
    }

    public function view(Admin $admin, Door $door): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === null || $admin->assigned_building === $door->location;
    }

    public function overrideStatus(Admin $admin, Door $door): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === $door->location;
    }

    public function open(Admin $admin, Door $door): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $admin->assigned_building === null || $admin->assigned_building === $door->location;
    }
}

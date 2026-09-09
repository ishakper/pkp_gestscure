<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetIncident;
use App\Models\AssetMaintenance;
use App\Services\PortalAccess;

class AssetPolicy
{
    protected PortalAccess $portalAccess;

    public function __construct(PortalAccess $portalAccess)
    {
        $this->portalAccess = $portalAccess;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'asset.view') ||
               $this->portalAccess->can($admin, 'asset.manage') ||
               $this->portalAccess->can($admin, 'asset.self');
    }

    public function view(Admin $admin, Asset $asset): bool
    {
        if ($admin->isSuperAdmin() || in_array(strtolower((string)$admin->role), ['hrd', 'management', 'infra_admin'], true)) {
            return true;
        }

        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            return !$asset->building_name || $asset->building_name === $admin->assigned_building;
        }

        if (in_array(strtolower((string)$admin->role), ['employee', 'intern'], true)) {
            $empId = $admin->employee_id ?? $admin->id;
            return $asset->assignments()
                ->where('employee_id', $empId)
                ->where('status', 'ACTIVE')
                ->exists();
        }

        return false;
    }

    public function create(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'asset.manage');
    }

    public function update(Admin $admin, Asset $asset): bool
    {
        if (!$this->portalAccess->can($admin, 'asset.manage')) {
            return false;
        }

        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            return !$asset->building_name || $asset->building_name === $admin->assigned_building;
        }

        return true;
    }

    public function assign(Admin $admin, Asset $asset): bool
    {
        if (!$this->portalAccess->can($admin, 'asset.assign')) {
            return false;
        }

        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            return !$asset->building_name || $asset->building_name === $admin->assigned_building;
        }

        return true;
    }

    public function returnAsset(Admin $admin, AssetAssignment $assignment): bool
    {
        if (!$this->portalAccess->can($admin, 'asset.return')) {
            return false;
        }

        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            return !$assignment->asset->building_name || $assignment->asset->building_name === $admin->assigned_building;
        }

        return true;
    }

    public function maintain(Admin $admin, Asset $asset): bool
    {
        if (!$this->portalAccess->can($admin, 'asset.maintenance')) {
            return false;
        }

        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            return !$asset->building_name || $asset->building_name === $admin->assigned_building;
        }

        return true;
    }

    public function reportIncident(Admin $admin, Asset $asset): bool
    {
        if ($this->portalAccess->can($admin, 'asset.incident')) {
            return true;
        }

        if (in_array(strtolower((string)$admin->role), ['employee', 'intern'], true)) {
            $empId = $admin->employee_id ?? $admin->id;
            return $asset->assignments()
                ->where('employee_id', $empId)
                ->where('status', 'ACTIVE')
                ->exists();
        }

        return false;
    }

    public function resolveIncident(Admin $admin, AssetIncident $incident): bool
    {
        return $this->portalAccess->can($admin, 'asset.incident') ||
               $this->portalAccess->can($admin, 'asset.manage');
    }

    public function dispose(Admin $admin, Asset $asset): bool
    {
        return $this->portalAccess->can($admin, 'asset.dispose');
    }
}

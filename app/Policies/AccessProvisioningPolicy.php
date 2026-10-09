<?php

namespace App\Policies;

use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\Admin;
use App\Models\CredentialRecord;
use App\Models\EmoneyCard;
use App\Services\PortalAccess;

class AccessProvisioningPolicy
{
    protected PortalAccess $portalAccess;

    public function __construct(PortalAccess $portalAccess)
    {
        $this->portalAccess = $portalAccess;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'access.view') ||
               $this->portalAccess->can($admin, 'access.request') ||
               $this->portalAccess->can($admin, 'access.self');
    }

    public function viewRequest(Admin $admin, AccessRequest $request): bool
    {
        if ($admin->isSuperAdmin() || in_array(strtolower((string)$admin->role), ['hrd', 'security_manager', 'management'], true)) {
            return true;
        }

        if ($admin->isBuildingAdmin()) {
            return true; // Full canonical building scope enforced by AccessProvisioningService.
        }

        if (in_array(strtolower((string)$admin->role), ['employee', 'intern'], true)) {
            return ($request->employee_id && $request->employee_id === $admin->id) ||
                   ($request->requested_by && $request->requested_by === $admin->id);
        }

        if (strtolower((string)$admin->role) === 'supervisor') {
            return ($request->employee && $request->employee->supervisor_id === $admin->id) ||
                   ($request->requested_by === $admin->id);
        }

        return false;
    }

    public function createRequest(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'access.request') ||
               $this->portalAccess->can($admin, 'access.manage') ||
               $this->portalAccess->can($admin, 'access.self');
    }

    public function approveRequest(Admin $admin, AccessRequest $request): bool
    {
        if (!$this->portalAccess->can($admin, 'access.approve')) {
            return false;
        }

        return true; // Full canonical building scope enforced by AccessProvisioningService.
    }

    public function manageProfiles(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'access.manage');
    }

    public function viewCredentials(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'credential.view') ||
               $this->portalAccess->can($admin, 'credential.self');
    }

    public function manageCredentials(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'credential.manage');
    }

    public function viewDeviceSyncs(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'device.sync.view') ||
               $this->portalAccess->can($admin, 'credential.sync');
    }

    public function syncDevice(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'credential.sync');
    }

    public function viewEmoney(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'emoney.view') ||
               $this->portalAccess->can($admin, 'emoney.self');
    }

    public function manageEmoney(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'emoney.manage');
    }
}

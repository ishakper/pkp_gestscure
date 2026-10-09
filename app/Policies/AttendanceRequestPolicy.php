<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\AttendanceRequest;
use App\Services\PortalAccess;

class AttendanceRequestPolicy
{
    public function __construct(protected PortalAccess $portalAccess) {}

    public function viewAny(Admin $admin): bool
    {
        $role = strtolower((string) $admin->role);
        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        return true;
    }

    public function view(Admin $admin, AttendanceRequest $request): bool
    {
        $role = strtolower((string) $admin->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        if (in_array($role, ['super_admin', 'hrd', 'management'], true)) {
            return true;
        }

        if ($role === 'supervisor') {
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            return (int) $request->employee?->supervisor_id === (int) $supervisorEmpId;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return (int) $request->employee_id === (int) $admin->employee_id;
        }

        return false;
    }

    public function create(Admin $admin): bool
    {
        $role = strtolower((string) $admin->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return !empty($admin->employee_id);
        }

        return in_array($role, ['super_admin', 'hrd', 'management', 'supervisor'], true);
    }

    public function approve(Admin $admin, AttendanceRequest $request): bool
    {
        $role = strtolower((string) $admin->role);

        // Anti self-approval
        if ((int) $admin->employee_id === (int) $request->employee_id) {
            return false;
        }

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        if ($admin->isSuperAdmin() || in_array($role, ['hrd', 'management'], true)) {
            return true;
        }

        if ($role === 'supervisor') {
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            return (int) $request->employee?->supervisor_id === (int) $supervisorEmpId;
        }

        return false;
    }

    public function reject(Admin $admin, AttendanceRequest $request): bool
    {
        return $this->approve($admin, $request);
    }

    public function cancel(Admin $admin, AttendanceRequest $request): bool
    {
        $role = strtolower((string) $admin->role);

        if ($admin->isSuperAdmin() || in_array($role, ['hrd'], true)) {
            return true;
        }

        if ((int) $admin->employee_id === (int) $request->employee_id) {
            return in_array($request->status, [AttendanceRequest::STATUS_DRAFT, AttendanceRequest::STATUS_SUBMITTED], true);
        }

        return false;
    }

    public function downloadAttachment(Admin $admin, AttendanceRequest $request): bool
    {
        return $this->view($admin, $request);
    }
}

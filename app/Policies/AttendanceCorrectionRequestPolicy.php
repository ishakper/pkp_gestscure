<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\AttendanceCorrectionRequest;
use App\Services\PortalAccess;

class AttendanceCorrectionRequestPolicy
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

    public function view(Admin $admin, AttendanceCorrectionRequest $correction): bool
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
            return (int) $correction->employee?->supervisor_id === (int) $supervisorEmpId;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return (int) $correction->employee_id === (int) $admin->employee_id;
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

    public function approve(Admin $admin, AttendanceCorrectionRequest $correction): bool
    {
        $role = strtolower((string) $admin->role);

        // Anti self-approval
        if ((int) $admin->employee_id === (int) $correction->employee_id) {
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
            return (int) $correction->employee?->supervisor_id === (int) $supervisorEmpId;
        }

        return false;
    }

    public function reject(Admin $admin, AttendanceCorrectionRequest $correction): bool
    {
        return $this->approve($admin, $correction);
    }

    public function cancel(Admin $admin, AttendanceCorrectionRequest $correction): bool
    {
        $role = strtolower((string) $admin->role);

        if ($admin->isSuperAdmin() || in_array($role, ['hrd'], true)) {
            return true;
        }

        if ((int) $admin->employee_id === (int) $correction->employee_id) {
            return in_array($correction->status, [AttendanceCorrectionRequest::STATUS_SUBMITTED, AttendanceCorrectionRequest::STATUS_DRAFT], true);
        }

        return false;
    }
}

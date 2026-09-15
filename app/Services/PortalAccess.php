<?php

namespace App\Services;

use App\Models\Admin;

class PortalAccess
{
    public const ADMIN_PORTAL = 'ADMIN_PORTAL';
    public const MANAGEMENT_PORTAL = 'MANAGEMENT_PORTAL';
    public const EMPLOYEE_PORTAL = 'EMPLOYEE_PORTAL';

    private const ADMIN_ROLES = ['super_admin', 'infra_admin', 'developer', 'devops', 'security_engineer'];
    private const MANAGEMENT_ROLES = ['building_admin', 'hrd', 'management', 'supervisor', 'project_manager', 'security', 'auditor'];

    public function portalFor(Admin $admin): string
    {
        $role = strtolower((string) $admin->role);
        if (in_array($role, self::ADMIN_ROLES, true)) return self::ADMIN_PORTAL;
        if (in_array($role, self::MANAGEMENT_ROLES, true)) return self::MANAGEMENT_PORTAL;
        return self::EMPLOYEE_PORTAL;
    }

    public function permissionsFor(Admin $admin): array
    {
        $role = strtolower((string) $admin->role);
        $portal = $this->portalFor($admin);

        if ($portal === self::ADMIN_PORTAL) {
            $perms = ['system.view','system.manage','device.view','device.manage','security.view','security.manage','employee.view','employee.manage','organization.view','organization.manage','audit.view'];
            if ($admin->isSuperAdmin()) {
                $perms[] = 'recruitment.view';
                $perms[] = 'recruitment.manage';
                $perms[] = 'internship.view';
                $perms[] = 'internship.manage';
                $perms[] = 'internship.mentor';
                $perms[] = 'onboarding.view';
                $perms[] = 'onboarding.manage';
                $perms[] = 'contract.view';
                $perms[] = 'contract.manage';
                $perms[] = 'document.view';
                $perms[] = 'document.manage';
                $perms[] = 'document.verify';
                $perms[] = 'document.download';
                $perms[] = 'access.view';
                $perms[] = 'access.request';
                $perms[] = 'access.approve';
                $perms[] = 'access.manage';
                $perms[] = 'credential.view';
                $perms[] = 'credential.manage';
                $perms[] = 'credential.sync';
                $perms[] = 'credential.revoke';
                $perms[] = 'emoney.view';
                $perms[] = 'emoney.manage';
                $perms[] = 'device.sync.view';
                $perms[] = 'asset.view';
                $perms[] = 'asset.manage';
                $perms[] = 'asset.assign';
                $perms[] = 'asset.return';
                $perms[] = 'asset.maintenance';
                $perms[] = 'asset.incident';
                $perms[] = 'asset.dispose';
                $perms[] = 'asset.report';
                $perms[] = 'attendance.view';
                $perms[] = 'attendance.manage';
                $perms[] = 'attendance.verify';
                $perms[] = 'attendance.calendar.manage';
                $perms[] = 'field_attendance.view';
                $perms[] = 'field_attendance.manage';
                $perms[] = 'field_attendance.verify';
                $perms[] = 'field_location.manage';
                $perms[] = 'attendance_request.view';
                $perms[] = 'attendance_request.manage';
                $perms[] = 'attendance_request.approve';
                $perms[] = 'attendance_correction.view';
                $perms[] = 'attendance_correction.manage';
                $perms[] = 'attendance_correction.approve';
                $perms[] = 'overtime.view';
                $perms[] = 'overtime.manage';
                $perms[] = 'overtime.approve';
            }
            return $perms;
        }

        if ($portal === self::MANAGEMENT_PORTAL) {
            $perms = ['employee.view','employee.manage','organization.view','device.view','security.view'];
            if (in_array($role, ['hrd', 'management', 'supervisor', 'security', 'building_admin'], true)) {
                $perms[] = 'access.view';
                $perms[] = 'asset.view';
            }
            if (in_array($role, ['hrd', 'management', 'supervisor'], true)) {
                $perms[] = 'recruitment.view';
                $perms[] = 'internship.view';
                $perms[] = 'onboarding.view';
                $perms[] = 'document.view';
                $perms[] = 'document.download';
                $perms[] = 'access.request';
                $perms[] = 'attendance.view';
                $perms[] = 'field_attendance.view';
                $perms[] = 'attendance_request.view';
                $perms[] = 'attendance_correction.view';
                $perms[] = 'overtime.view';
            }
            if (in_array($role, ['hrd', 'management'], true)) {
                $perms[] = 'contract.view';
                $perms[] = 'emoney.view';
                $perms[] = 'asset.report';
            }
            if (in_array($role, ['hrd', 'security', 'building_admin'], true)) {
                $perms[] = 'access.approve';
                $perms[] = 'device.sync.view';
            }
            if (in_array($role, ['hrd', 'security', 'building_admin'], true)) {
                $perms[] = 'credential.view';
            }
            if ($role === 'building_admin') {
                $perms[] = 'access.manage';
                $perms[] = 'credential.manage';
                $perms[] = 'credential.sync';
            }
            if (in_array($role, ['hrd', 'building_admin'], true)) {
                $perms[] = 'asset.manage';
                $perms[] = 'asset.assign';
                $perms[] = 'asset.return';
                $perms[] = 'asset.maintenance';
                $perms[] = 'asset.incident';
                $perms[] = 'asset.dispose';
            }
            if (in_array($role, ['hrd'], true)) {
                $perms[] = 'recruitment.manage';
                $perms[] = 'internship.manage';
                $perms[] = 'onboarding.manage';
                $perms[] = 'contract.manage';
                $perms[] = 'document.manage';
                $perms[] = 'document.verify';
                $perms[] = 'access.manage';
                $perms[] = 'credential.manage';
                $perms[] = 'credential.sync';
                $perms[] = 'credential.revoke';
                $perms[] = 'emoney.manage';
                $perms[] = 'attendance.manage';
                $perms[] = 'attendance.verify';
                $perms[] = 'attendance.calendar.manage';
                $perms[] = 'field_attendance.manage';
                $perms[] = 'field_attendance.verify';
                $perms[] = 'field_location.manage';
                $perms[] = 'attendance_request.manage';
                $perms[] = 'attendance_request.approve';
                $perms[] = 'attendance_correction.manage';
                $perms[] = 'attendance_correction.approve';
                $perms[] = 'overtime.manage';
                $perms[] = 'overtime.approve';
            }
            if (in_array($role, ['supervisor'], true)) {
                $perms[] = 'internship.mentor';
                $perms[] = 'attendance_request.approve';
                $perms[] = 'attendance_correction.approve';
                $perms[] = 'overtime.approve';
            }
            return $perms;
        }

        if ($portal === self::EMPLOYEE_PORTAL) {
            return [
                'internship.self',
                'onboarding.self',
                'contract.self',
                'document.self',
                'access.self',
                'credential.self',
                'emoney.self',
                'asset.self',
                'attendance.self',
                'field_attendance.self',
                'attendance_request.self',
                'attendance_correction.self',
                'overtime.self',
            ];
        }

        return [];
    }

    public function can(Admin $admin, string $permission): bool { return in_array($permission, $this->permissionsFor($admin), true); }
}

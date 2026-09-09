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
            }
            return $perms;
        }

        if ($portal === self::MANAGEMENT_PORTAL) {
            $perms = ['employee.view','employee.manage','organization.view','device.view','security.view'];
            if (in_array($role, ['hrd', 'management', 'supervisor'], true)) {
                $perms[] = 'recruitment.view';
                $perms[] = 'internship.view';
                $perms[] = 'onboarding.view';
                $perms[] = 'document.view';
                $perms[] = 'document.download';
            }
            if (in_array($role, ['hrd', 'management'], true)) {
                $perms[] = 'contract.view';
            }
            if (in_array($role, ['hrd'], true)) {
                $perms[] = 'recruitment.manage';
                $perms[] = 'internship.manage';
                $perms[] = 'onboarding.manage';
                $perms[] = 'contract.manage';
                $perms[] = 'document.manage';
                $perms[] = 'document.verify';
            }
            if (in_array($role, ['supervisor'], true)) {
                $perms[] = 'internship.mentor';
            }
            return $perms;
        }

        if ($portal === self::EMPLOYEE_PORTAL) {
            return [
                'internship.self',
                'onboarding.self',
                'contract.self',
                'document.self',
            ];
        }

        return [];
    }

    public function can(Admin $admin, string $permission): bool { return in_array($permission, $this->permissionsFor($admin), true); }
}

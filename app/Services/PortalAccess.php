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
        $portal = $this->portalFor($admin);
        if ($portal === self::ADMIN_PORTAL) return ['system.view','system.manage','device.view','device.manage','security.view','security.manage','employee.view','employee.manage','organization.view','organization.manage','audit.view'];
        if ($portal === self::MANAGEMENT_PORTAL) return ['employee.view','employee.manage','organization.view','device.view','security.view'];
        return [];
    }

    public function can(Admin $admin, string $permission): bool { return in_array($permission, $this->permissionsFor($admin), true); }
}

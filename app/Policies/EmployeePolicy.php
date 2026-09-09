<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Door;
use App\Models\Employee;

class EmployeePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return true; // Both super_admin & building_admin can list employees
    }

    public function view(Admin $admin, Employee $employee): bool
    {
        if ($admin->isSuperAdmin() || in_array($admin->role, ['hrd', 'management'], true)) return true;
        if (in_array($admin->role, ['employee', 'intern'], true)) return (int) $admin->employee_id === (int) $employee->id;
        if ($admin->role === 'supervisor') return (int) $employee->supervisor_id === (int) $admin->employee_id;
        if (!$admin->assigned_building) return false;
        return $employee->doors()->where('location', $admin->assigned_building)->exists();
    }
    public function create(Admin $admin): bool
    {
        return true;
    }

    public function update(Admin $admin, Employee $employee): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        if (!$admin->assigned_building) {
            return true;
        }

        // building_admin can only manage employees associated with their building
        return $employee->doors()->where('location', $admin->assigned_building)->exists();
    }

    public function delete(Admin $admin, Employee $employee): bool
    {
        return $admin->isSuperAdmin(); // Only super_admin can delete employees
    }

    public function assignDoor(Admin $admin, Employee $employee, Door $door): bool
    {
        if ($admin->isSuperAdmin()) {
            return true;
        }

        // building_admin can only assign access to doors in their assigned building
        return $admin->assigned_building === $door->location;
    }
}

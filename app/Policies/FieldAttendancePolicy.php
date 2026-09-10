<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\FieldAttendanceEvidence;
use App\Services\PortalAccess;

class FieldAttendancePolicy
{
    public function __construct(protected PortalAccess $portalAccess) {}

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'field_attendance.view')
            || $this->portalAccess->can($admin, 'field_attendance.self');
    }

    public function view(Admin $admin, FieldAttendanceEvidence $evidence): bool
    {
        $role = strtolower((string) $admin->role);

        if (in_array($role, ['super_admin', 'hrd'], true)) {
            return true;
        }

        if ($role === 'management') {
            return true;
        }

        if ($role === 'supervisor') {
            $emp = $evidence->employee;
            if ($emp && (int)$emp->supervisor_id === (int)$admin->employee_id) {
                return true;
            }
            $assignment = $evidence->fieldAssignment;
            if ($assignment && (int)$assignment->supervisor_id === (int)$admin->employee_id) {
                return true;
            }
            return false;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return (int)$evidence->employee_id === (int)$admin->employee_id;
        }

        return false;
    }

    public function submit(Admin $admin, Employee $employee): bool
    {
        $role = strtolower((string) $admin->role);

        if (in_array($role, ['super_admin', 'hrd'], true)) {
            return true;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return (int)$admin->employee_id === (int)$employee->id;
        }

        return false;
    }

    public function manage(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'field_attendance.manage');
    }

    public function manageLocations(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'field_location.manage');
    }

    public function override(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'field_attendance.verify')
            || $this->portalAccess->can($admin, 'field_attendance.manage');
    }

    /**
     * Strict photo privacy check:
     * - Employee/Intern: self only
     * - Supervisor: assigned team only
     * - HRD/Super Admin: authorized management
     * - Management: NO PHOTO (summary only)
     * - Building Admin / Dev / DevOps: NO ACCESS
     */
    public function viewPhoto(Admin $admin, FieldAttendanceEvidence $evidence): bool
    {
        $role = strtolower((string) $admin->role);

        if (in_array($role, ['super_admin', 'hrd'], true)) {
            return true;
        }

        if ($role === 'supervisor') {
            $emp = $evidence->employee;
            if ($emp && (int)$emp->supervisor_id === (int)$admin->employee_id) {
                return true;
            }
            $assignment = $evidence->fieldAssignment;
            if ($assignment && (int)$assignment->supervisor_id === (int)$admin->employee_id) {
                return true;
            }
            return false;
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return (int)$evidence->employee_id === (int)$admin->employee_id;
        }

        return false;
    }
}

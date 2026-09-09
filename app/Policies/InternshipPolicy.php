<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Internship;
use App\Services\PortalAccess;

class InternshipPolicy
{
    protected PortalAccess $portalAccess;

    public function __construct(PortalAccess $portalAccess)
    {
        $this->portalAccess = $portalAccess;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'internship.view');
    }

    public function view(Admin $admin, Internship $internship): bool
    {
        if ($admin->isSuperAdmin() || strtolower((string) $admin->role) === 'hrd') {
            return true;
        }

        // Supervisor / Mentor can only view assigned interns
        if (strtolower((string) $admin->role) === 'supervisor') {
            $empId = $admin->employee_id ?? $admin->id;
            $divId = $admin->employee?->division_id ?? $admin->division_id ?? null;
            return (int) $internship->mentor_id === (int) $empId 
                || (int) $internship->supervisor_id === (int) $empId
                || ($divId && (int) $internship->division_id === (int) $divId);
        }

        // Intern can view their own record
        if (strtolower((string) $admin->role) === 'intern' || strtolower((string) $admin->role) === 'employee') {
            return (int) $internship->employee_id === (int) $admin->id;
        }

        return false;
    }

    public function create(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'internship.manage');
    }

    public function update(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        // Mentor can update assignment/notes of their own intern
        if (strtolower((string) $admin->role) === 'supervisor' && (int) $internship->mentor_id === (int) $admin->id) {
            return true;
        }

        return false;
    }

    public function delete(Admin $admin, Internship $internship): bool
    {
        return $this->portalAccess->can($admin, 'internship.manage');
    }

    public function logActivity(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        return (int) $internship->employee_id === (int) $admin->id;
    }

    public function reviewActivity(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        return strtolower((string) $admin->role) === 'supervisor' 
            && ((int) $internship->mentor_id === (int) $admin->id || (int) $internship->supervisor_id === (int) $admin->id);
    }

    public function submitReport(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        return (int) $internship->employee_id === (int) $admin->id;
    }

    public function reviewReport(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        return strtolower((string) $admin->role) === 'supervisor' 
            && ((int) $internship->mentor_id === (int) $admin->id || (int) $internship->supervisor_id === (int) $admin->id);
    }

    public function evaluate(Admin $admin, Internship $internship): bool
    {
        if ($this->portalAccess->can($admin, 'internship.manage')) {
            return true;
        }

        return strtolower((string) $admin->role) === 'supervisor' 
            && ((int) $internship->mentor_id === (int) $admin->id || (int) $internship->supervisor_id === (int) $admin->id);
    }

    public function complete(Admin $admin, Internship $internship): bool
    {
        return $this->portalAccess->can($admin, 'internship.manage');
    }
}

<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Contract;
use App\Models\EmployeeDocument;
use App\Models\OnboardingCase;
use App\Services\OnboardingService;
use App\Services\PortalAccess;

class OnboardingPolicy
{
    protected PortalAccess $portalAccess;
    protected OnboardingService $service;

    public function __construct(PortalAccess $portalAccess, OnboardingService $service)
    {
        $this->portalAccess = $portalAccess;
        $this->service = $service;
    }

    public function viewAny(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'onboarding.view');
    }

    public function view(Admin $admin, OnboardingCase $case): bool
    {
        if ($admin->isSuperAdmin() || strtolower((string) $admin->role) === 'hrd') {
            return true;
        }

        if (in_array(strtolower((string) $admin->role), ['developer', 'devops', 'infra_admin', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        if (strtolower((string) $admin->role) === 'management') {
            return true;
        }

        if (strtolower((string) $admin->role) === 'supervisor') {
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            return $case->supervisor_id === $supervisorEmpId ||
                   $case->division_id === $admin->division_id ||
                   ($case->employee && $case->employee->division_id === $admin->division_id);
        }

        if (in_array(strtolower((string) $admin->role), ['employee', 'intern'], true)) {
            return ($case->employee_id && $case->employee_id === $admin->employee_id) ||
                   ($case->internship_id && $admin->internship_id && $case->internship_id === $admin->internship_id);
        }

        return false;
    }

    public function manage(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'onboarding.manage');
    }

    public function updateTask(Admin $admin, OnboardingCase $case): bool
    {
        if ($this->manage($admin)) {
            return true;
        }

        if (strtolower((string) $admin->role) === 'supervisor') {
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            return $case->supervisor_id === $supervisorEmpId ||
                   $case->division_id === $admin->division_id;
        }

        return false;
    }

    public function viewContract(Admin $admin, Contract $contract): bool
    {
        if ($this->portalAccess->can($admin, 'contract.view')) {
            return true;
        }

        if (in_array(strtolower((string) $admin->role), ['employee', 'intern'], true)) {
            return ($contract->employee_id && $contract->employee_id === $admin->employee_id) ||
                   ($contract->internship_id && $admin->internship_id && $contract->internship_id === $admin->internship_id);
        }

        return false;
    }

    public function manageContract(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'contract.manage');
    }

    public function viewDocument(Admin $admin, EmployeeDocument $doc): bool
    {
        return $this->service->authorizeDocumentAccess($doc, $admin);
    }

    public function manageDocument(Admin $admin): bool
    {
        return $this->portalAccess->can($admin, 'document.manage');
    }
}

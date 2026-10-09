<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobVacancy;

class RecruitmentPolicy
{
    private const TECH_ROLES = ['developer', 'devops', 'infra_admin', 'security_engineer'];

    public function before(Admin $admin, string $ability): ?bool
    {
        if (in_array($admin->role, self::TECH_ROLES, true)) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    public function viewAny(Admin $admin): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd', 'management', 'supervisor'], true);
    }

    public function manageVacancies(Admin $admin): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd'], true);
    }

    public function manageCandidates(Admin $admin): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd'], true);
    }

    public function viewApplication(Admin $admin, JobApplication $application): bool
    {
        if (in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) {
            return true;
        }

        if ($admin->role === 'supervisor') {
            // Supervisor can view if the vacancy is in supervisor's division or if assigned as interviewer
            $emp = $admin->employee;
            if ($emp && $application->vacancy?->division_id === $emp->division_id) {
                return true;
            }

            return $application->interviews()->where('interviewer_id', $admin->id)->exists();
        }

        return false;
    }

    public function manageApplication(Admin $admin, JobApplication $application): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd'], true);
    }

    public function submitInterviewFeedback(Admin $admin, Interview $interview): bool
    {
        if (in_array($admin->role, ['super_admin', 'hrd'], true)) {
            return true;
        }

        return $admin->role === 'supervisor' && $interview->interviewer_id === $admin->id;
    }

    public function manageOffers(Admin $admin): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd', 'management'], true);
    }

    public function convertCandidate(Admin $admin): bool
    {
        return in_array($admin->role, ['super_admin', 'hrd'], true);
    }
}

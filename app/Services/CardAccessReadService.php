<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CredentialRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class CardAccessReadService
{
    public function overview(Admin $actor): array
    {
        $employees = $this->scopedEmployees($actor)->with(['credentials', 'doorAssignments'])->get();
        $states = $employees->map(fn (Employee $employee) => $this->summary($employee));

        return [
            'kpis' => [
                'total_cards' => $states->whereNotNull('credential')->count(),
                'active_cards' => $states->where('lifecycle_state', 'ACTIVE_SYNCED')->count(),
                'pending_sync' => $states->whereIn('sync_status', ['PENDING', 'SYNCING', 'PARTIAL'])->count(),
                'sync_failed' => $states->where('sync_status', 'FAILED')->count(),
                'needs_verification' => $states->where('lifecycle_state', 'NEEDS_VERIFICATION')->count(),
                'disabled_cards' => $states->whereIn('lifecycle_state', ['DISABLED', 'REVOKED'])->count(),
            ],
            'sync_health' => ['items' => collect(['HEALTHY', 'WARNING', 'OFFLINE', 'SYNC_FAILED', 'NEEDS_VERIFICATION'])->map(fn ($key) => [
                'key' => $key,
                'count' => $states->filter(fn ($state) => $this->healthKey($state) === $key)->count(),
            ])->values()->all()],
            'action_required' => $states->filter(fn ($state) => $this->needsAttention($state))->count(),
            'as_of' => now()->toIso8601String(),
        ];
    }

    public function activity(Admin $actor, int $limit): array
    {
        $query = ActivityLog::query()->with('admin:id,name')->latest('timestamp');
        $this->scopeActivity($query, $actor);

        return $query->limit(max(1, min($limit, 50)))->get()->map(fn (ActivityLog $log) => [
            'at' => $log->timestamp?->toIso8601String(),
            'title' => $this->activityTitle($log->action),
            'by' => $log->admin?->name ?? 'System',
            'type' => strtoupper($log->action),
            'employee' => $this->activityEmployee($log),
            'operator' => $log->admin ? ['id' => $log->admin->id, 'name' => $log->admin->name] : null,
            'target' => ['type' => 'CARD', 'label' => $this->maskedFromDescription($log->description)],
            'result' => str_contains(strtolower($log->action), 'failed') ? 'FAILED' : 'SUCCESS',
        ])->all();
    }

    public function paginateCards(Admin $actor, array $filters): LengthAwarePaginator
    {
        $employees = $this->scopedEmployees($actor)->with(['building', 'position', 'credentials', 'doorAssignments.door'])->get();
        $items = $employees->map(fn (Employee $employee) => $this->summary($employee))->filter(function (array $item) use ($filters) {
            if (!empty($filters['lifecycle_state']) && $item['lifecycle_state'] !== $filters['lifecycle_state']) return false;
            if (!empty($filters['sync_status']) && $item['sync_status'] !== $filters['sync_status']) return false;
            if (!empty($filters['verification']) && $item['verification'] !== $filters['verification']) return false;
            if ((string) ($filters['attention'] ?? '') === '1' && !$item['attention']) return false;
            return true;
        });
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') $items = $items->filter(fn (array $item) => str_contains(strtolower($item['name']), strtolower($search)) || str_contains(strtolower($item['employee_id']), strtolower($search)) || ($item['credential']['masked_identifier'] ?? '') === '••••' . substr($search, -4));
        $sort = in_array($filters['sort'] ?? '', ['name', 'employee_id', 'lifecycle_state', 'sync_status'], true) ? $filters['sort'] : 'name';
        $items = (($filters['direction'] ?? 'asc') === 'desc' ? $items->sortByDesc($sort) : $items->sortBy($sort))->values();
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);
        return new LengthAwarePaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, ['path' => request()->url(), 'query' => request()->query()]);
    }
    public function detail(Admin $actor, int $id): array
    {
        $employee = $this->scopedEmployees($actor)->with(['building', 'position', 'credentials', 'doorAssignments.door'])->findOrFail($id);
        $summary = $this->summary($employee);
        $credential = $summary['credential'];

        return [
            'employee' => [
                'id' => $employee->id, 'name' => $employee->name, 'employee_code' => $employee->employee_id,
                'employment_status' => $employee->employment_status, 'building' => $employee->building ? ['id' => $employee->building->id, 'name' => $employee->building->name] : null,
                'department' => $employee->department, 'position' => $employee->position?->name,
            ],
            'credential' => $credential,
            'access' => [
                'buildings' => $employee->doorAssignments->pluck('door.building.name')->filter()->unique()->values()->all(),
                'doors' => $employee->doorAssignments->map(fn ($assignment) => ['id' => $assignment->door?->id, 'door_id' => $assignment->door?->door_id, 'name' => $assignment->door?->name, 'sync_status' => strtoupper((string) $assignment->sync_status)])->values()->all(),
                'profile' => null, 'valid_from' => null, 'valid_until' => null,
            ],
            'device' => $this->deviceSummary($employee),
            'lifecycle_state' => $summary['lifecycle_state'],
            'verification' => $summary['verification'],
            'allowed_actions' => ['VIEW'],
        ];
    }

    public function audit(Admin $actor, int $id): array
    {
        $this->scopedEmployees($actor)->findOrFail($id);
        return ActivityLog::query()->with('admin:id,name')->where('subject_type', Employee::class)->where('subject_id', $id)->latest('timestamp')->get()->map(fn (ActivityLog $log) => [
            'at' => $log->timestamp?->toIso8601String(), 'title' => $this->activityTitle($log->action), 'by' => $log->admin?->name ?? 'System',
            'type' => strtoupper($log->action), 'operator' => $log->admin ? ['id' => $log->admin->id, 'name' => $log->admin->name] : null, 'target' => ['type' => 'CARD', 'label' => $this->maskedFromDescription($log->description)], 'result' => 'SUCCESS', 'metadata' => [],
        ])->all();
    }

    private function scopedEmployees(Admin $actor): Builder
    {
        $query = Employee::query();
        if ($actor->isBuildingAdmin()) {
            $query->where(function (Builder $scope) use ($actor) {
                if ($actor->employee?->building_id) $scope->where('building_id', $actor->employee->building_id);
                if ($actor->assigned_building) $scope->orWhereHas('building', fn (Builder $building) => $building->where('name', $actor->assigned_building));
            });
        }
        return $query;
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->where(function (Builder $scope) use ($search) {
                $scope->where('name', 'ilike', "%{$search}%")->orWhere('employee_id', 'ilike', "%{$search}%");
                if (preg_match('/^\d{4}$/', $search)) $scope->orWhereHas('credentials', fn (Builder $credential) => $credential->where('masked_identifier', 'like', "%{$search}"));
            });
        }
        if (!empty($filters['building_id'])) $query->where('building_id', (int) $filters['building_id']);
    }

    private function summary(Employee $employee): array
    {
        $credential = $employee->credentials->where('credential_type', 'CARD')->where('status', 'ACTIVE')->sortByDesc('id')->first();
        $legacy = !$credential && filled($employee->card_no);
        $hasCredential = (bool) ($credential || $legacy);
        $assignments = $employee->doorAssignments;
        $states = \App\Models\DevicePersonState::query()->where('employee_id', $employee->id)->get();
        $failed = $assignments->contains('sync_status', 'failed');
        $pending = $assignments->contains(fn ($assignment) => $assignment->sync_status !== 'synced');
        $conflict = in_array($employee->credential_status, ['conflict', 'needs_verification'], true) || $states->contains(fn ($state) => in_array($state->status, ['CONFLICT', 'REVIEW', 'IDENTITY_CONFLICT', 'CARD_MISMATCH'], true));
        $deviceMissing = $hasCredential && $assignments->isNotEmpty() && $states->isNotEmpty() && $states->where('present_on_device', true)->isEmpty();
        $disabled = strtoupper((string) $employee->employment_status) !== 'ACTIVE';
        $revoked = !$credential && $employee->credentials->where('credential_type', 'CARD')->where('status', 'REVOKED')->isNotEmpty();
        $lifecycle = $disabled ? 'DISABLED' : ($revoked ? 'REVOKED' : (!$hasCredential ? 'CARD_NOT_REGISTERED' : ($conflict || $assignments->isEmpty() ? 'NEEDS_VERIFICATION' : (!$pending && !$deviceMissing ? 'ACTIVE_SYNCED' : 'ACTIVE_NOT_SYNCED'))));
        $sync = $failed ? 'FAILED' : ($assignments->isEmpty() ? 'UNKNOWN' : ($pending ? 'PARTIAL' : ($deviceMissing ? 'UNKNOWN' : 'SYNCED')));
        return ['id' => $employee->id, 'employee_id' => $employee->employee_id, 'name' => $employee->name, 'building' => $employee->building?->name, 'employment_status' => $employee->employment_status, 'credential' => $hasCredential ? ['masked_identifier' => $credential?->masked_identifier ?: $this->mask((string) $employee->card_no), 'credential_type' => 'CARD', 'status' => $credential?->status ?: 'ACTIVE', 'origin' => $credential ? 'CREDENTIAL_RECORD' : 'LEGACY_EMPLOYEE_CARD', 'registered_at' => ($credential?->activated_at ?: $credential?->issued_at)?->toIso8601String(), 'registered_by' => null] : null, 'lifecycle_state' => $lifecycle, 'sync_status' => $sync, 'verification' => $conflict ? 'NEEDS_VERIFICATION' : ($lifecycle === 'ACTIVE_SYNCED' ? 'VERIFIED' : 'NOT_VERIFIED'), 'attention' => $this->needsAttention(['lifecycle_state' => $lifecycle, 'sync_status' => $sync])];
    }
    private function deviceSummary(Employee $employee): array { return ['person_match' => 'UNKNOWN', 'credential_match' => 'UNKNOWN', 'access_match' => 'UNKNOWN', 'device_card_masked' => null, 'device_access' => [], 'device_person' => null, 'sync_status' => 'UNKNOWN', 'device_status' => 'UNKNOWN', 'last_sync_at' => $employee->doorAssignments->max('last_synced_at')?->toIso8601String(), 'last_verified_at' => null, 'error' => null]; }
    private function needsAttention(array $state): bool { return $state['lifecycle_state'] === 'NEEDS_VERIFICATION' || in_array($state['sync_status'], ['FAILED', 'PARTIAL', 'UNKNOWN'], true); }
    private function healthKey(array $state): string { return $state['lifecycle_state'] === 'NEEDS_VERIFICATION' ? 'NEEDS_VERIFICATION' : ($state['sync_status'] === 'FAILED' ? 'SYNC_FAILED' : ($state['sync_status'] === 'UNKNOWN' ? 'OFFLINE' : ($state['sync_status'] === 'SYNCED' ? 'HEALTHY' : 'WARNING'))); }
    private function mask(string $value): string { return '••••' . substr(strtoupper(trim($value)), -4); }
    private function maskedFromDescription(?string $description): ?string { return null; }
    private function activityTitle(string $action): string { return ucwords(str_replace(['_', '-'], ' ', $action)); }
    private function activityEmployee(ActivityLog $log): ?array { return null; }
    private function scopeActivity(Builder $query, Admin $actor): void { if (!$actor->isBuildingAdmin()) return; $employeeIds = $this->scopedEmployees($actor)->pluck('id'); $query->where(function (Builder $scope) use ($employeeIds) { $scope->where('subject_type', Employee::class)->whereIn('subject_id', $employeeIds)->orWhereNull('subject_id'); }); }
}

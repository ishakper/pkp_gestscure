<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CredentialRecord;
use App\Models\DevicePersonLink;
use App\Models\DevicePersonState;
use App\Models\DeviceReconciliationRun;
use App\Models\Door;
use App\Models\Employee;
use App\Policies\AccessProvisioningPolicy;
use App\Services\CardSecurityService;
use App\Services\DeviceCredentialReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reconciliation Center (Credential Center). Read-only towards devices and employees.
 *
 * Device data is not building-scoped yet, so every endpoint is limited to super_admin on
 * top of the existing credential permissions: no role gains access it did not have.
 */
class DeviceReconciliationController extends Controller
{
    public function __construct(
        private DeviceCredentialReconciliationService $service,
        private AccessProvisioningPolicy $policy,
    ) {
    }

    private function denied(Request $request, string $ability): ?JsonResponse
    {
        $actor = $request->user();
        $allowed = $actor && $actor->isSuperAdmin() && match ($ability) {
            'view' => $this->policy->viewCredentials($actor),
            'run' => $this->policy->syncDevice($actor),
        };

        return $allowed ? null : response()->json(['message' => 'Hanya super admin yang dapat membuka rekonsiliasi perangkat.'], 403);
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request, 'view')) {
            return $denied;
        }
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'synced', 'partial', 'app_only', 'device_only', 'conflict', 'review'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json(['success' => true, 'data' => $this->service->overview($filters)]);
    }

    public function run(Request $request): JsonResponse
    {
        if ($denied = $this->denied($request, 'run')) {
            return $denied;
        }
        $data = $request->validate([
            'door_id' => ['nullable', 'integer', 'exists:doors,id'],
        ]);

        $busy = DeviceReconciliationRun::query()
            ->where('status', 'running')
            ->where('started_at', '>=', now()->subMinutes(5))
            ->exists();
        if ($busy) {
            return response()->json(['message' => 'Pembacaan perangkat masih berjalan. Coba lagi sebentar.'], 409);
        }

        $run = $this->service->run($request->user(), isset($data['door_id']) ? [(int) $data['door_id']] : []);

        return response()->json([
            'success' => true,
            'message' => 'Data perangkat dibaca ulang (read-only). Tidak ada data yang ditulis ke perangkat.',
            'data' => ['run' => $run->only(['id', 'status', 'summary', 'started_at', 'finished_at'])],
        ]);
    }

    public function employee(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denied($request, 'view')) {
            return $denied;
        }
        $employee = Employee::with(['biometricStatus', 'doors', 'deviceStates.door'])->findOrFail($id);
        $cards = app(CardSecurityService::class);

        $states = $employee->deviceStates->map(fn (DevicePersonState $s) => [
            'id' => $s->id,
            'door_id' => $s->door_id,
            'door_code' => $s->door?->door_id,
            'device_employee_no' => $s->device_employee_no,
            'device_name' => $s->device_name,
            'present_on_device' => $s->present_on_device,
            'card_count' => $s->card_count,
            'card_masks' => $s->card_masks,
            'fingerprint_count' => $s->fingerprint_count,
            'face_count' => $s->face_count,
            'card_status' => $s->card_status,
            'fingerprint_status' => $s->fingerprint_status,
            'match_basis' => $s->match_basis,
            'confidence' => $s->confidence,
            'reasons' => $s->reasons,
            'last_verified_at' => $s->last_verified_at?->toIso8601String(),
        ] + DeviceCredentialReconciliationService::stateStatuses($s))->values();

        return response()->json(['success' => true, 'data' => [
            'app' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'nik' => $employee->nik,
                'name' => $employee->name,
                'person_number' => $employee->source_person_number ?: $employee->hikvision_employee_no,
                'employment_status' => $employee->employment_status,
                'credential_method' => $employee->credential_method,
                'credential_status' => $employee->credential_status,
                'card_masks' => array_values(array_filter(array_merge(
                    trim((string) $employee->card_no) !== '' ? [$cards->maskCardNumber((string) $employee->card_no)] : [],
                    CredentialRecord::query()->where('employee_id', $employee->id)->whereNotNull('masked_identifier')->pluck('masked_identifier')->all()
                ))),
                'assigned_doors' => $employee->doors->map(fn ($d) => ['id' => $d->id, 'door_id' => $d->door_id, 'sync_status' => $d->pivot->sync_status])->values(),
            ],
            'verification' => $this->service->employeeVerification($employee),
            'device_states' => $states,
        ]]);
    }

    public function devicePerson(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denied($request, 'view')) {
            return $denied;
        }
        $state = DevicePersonState::with(['door', 'employee:id,employee_id,name,nik', 'candidate:id,employee_id,name,nik'])->findOrFail($id);
        $link = DevicePersonLink::with('decider:id,name')->where('door_id', $state->door_id)->where('device_employee_no', $state->device_employee_no)->first();
        $conflictIds = (array) data_get($state->reasons, 'detail.conflicting_employee_ids', []);

        return response()->json(['success' => true, 'data' => [
            'state' => $state->only(['id', 'door_id', 'device_employee_no', 'device_name', 'device_enabled', 'card_count', 'card_masks', 'fingerprint_count', 'face_count', 'present_on_device', 'card_status', 'fingerprint_status', 'match_basis', 'confidence', 'reasons']) + DeviceCredentialReconciliationService::stateStatuses($state) + [
                'door_code' => $state->door?->door_id,
                'last_verified_at' => $state->last_verified_at?->toIso8601String(),
            ],
            'employee' => $state->employee,
            'candidate' => $state->candidate,
            // Every employee an identifier points to, so the admin can decide manually.
            'conflicting_employees' => Employee::query()->whereIn('id', $conflictIds)->orderBy('id')
                ->get(['id', 'employee_id', 'name', 'nik', 'source_person_number', 'hikvision_employee_no', 'employment_status']),
            'decision' => $link ? $link->only(['decision', 'employee_id', 'note', 'updated_at']) + ['decided_by' => $link->decider?->name] : null,
        ]]);
    }

    /**
     * Human decision for one device person. Writes only device_person_links (and an
     * activity log); never creates or edits an employee and never writes to the device.
     */
    public function decide(Request $request, int $id): JsonResponse
    {
        if ($denied = $this->denied($request, 'run')) {
            return $denied;
        }
        $state = DevicePersonState::with('door')->findOrFail($id);
        $data = $request->validate([
            'decision' => ['required', Rule::in([DevicePersonLink::LINKED, DevicePersonLink::REVIEW, DevicePersonLink::IGNORED])],
            'employee_id' => ['nullable', 'required_if:decision,' . DevicePersonLink::LINKED, 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $employeeId = $data['decision'] === DevicePersonLink::LINKED ? (int) $data['employee_id'] : null;

        if ($employeeId !== null) {
            // One device person per employee per device: linking a second one would
            // create a duplicate identity on that device.
            $taken = DevicePersonState::query()
                ->where('door_id', $state->door_id)
                ->where('employee_id', $employeeId)
                ->where('present_on_device', true)
                ->where('id', '!=', $state->id)
                ->first();
            if ($taken) {
                return response()->json([
                    'message' => "Pengguna ini sudah terhubung ke person {$taken->device_employee_no} di perangkat yang sama. Periksa Detail Perbandingan.",
                ], 422);
            }
        }

        $link = DevicePersonLink::updateOrCreate(
            ['door_id' => $state->door_id, 'device_employee_no' => $state->device_employee_no],
            ['employee_id' => $employeeId, 'decision' => $data['decision'], 'decided_by' => $request->user()->id, 'note' => $data['note'] ?? null]
        );
        $state = $this->service->reevaluate($state);

        ActivityLog::create([
            'admin_id' => $request->user()->id,
            'action' => 'device_person_' . strtolower($data['decision']),
            'subject_type' => 'DevicePersonState',
            'subject_id' => $state->id,
            'description' => sprintf(
                'Keputusan rekonsiliasi %s untuk person %s di %s%s. Tidak ada perubahan pada data karyawan atau perangkat.',
                $data['decision'],
                $state->device_employee_no,
                $state->door?->door_id ?? ('door #' . $state->door_id),
                $employeeId ? " → employee #{$employeeId}" : ''
            ),
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => match ($data['decision']) {
                DevicePersonLink::LINKED => 'Data perangkat dihubungkan ke pengguna.',
                DevicePersonLink::REVIEW => 'Ditandai perlu verifikasi.',
                default => 'Data perangkat diabaikan.',
            },
            'data' => ['link' => $link->only(['decision', 'employee_id', 'note']), 'state' => $state->only(['id', 'status', 'device_link_status', 'identity_status', 'employee_id', 'card_status', 'fingerprint_status', 'match_basis', 'confidence', 'reasons'])],
        ]);
    }
}

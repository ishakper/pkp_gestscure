<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\CredentialDeviceSync;
use App\Models\CredentialRecord;
use App\Models\EmoneyCard;
use App\Policies\AccessProvisioningPolicy;
use App\Services\AccessProvisioningService;
use App\Services\PortalAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessProvisioningController extends Controller
{
    protected AccessProvisioningService $service;
    protected AccessProvisioningPolicy $policy;
    protected PortalAccess $portalAccess;

    public function __construct(
        AccessProvisioningService $service,
        AccessProvisioningPolicy $policy,
        PortalAccess $portalAccess
    ) {
        $this->service = $service;
        $this->policy = $policy;
        $this->portalAccess = $portalAccess;
    }

    /**
     * Dashboard KPI Metrics
     */
    public function metrics(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view access provisioning metrics.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->getMetrics($actor),
        ]);
    }

    // =============================================================
    // ACCESS PROFILES
    // =============================================================

    public function profiles(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view access profiles.'], 403);
        }

        $profiles = $this->service->getProfiles($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $profiles,
        ]);
    }

    public function storeProfile(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageProfiles($actor)) {
            return response()->json(['message' => 'Unauthorized to create access profiles.'], 403);
        }

        $validated = $request->validate([
            'code' => 'required|string|max:32|unique:access_profiles,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string',
            'building_name' => 'nullable|string|max:100',
            'allowed_doors' => 'required|array|min:1',
            'allowed_doors.*' => 'required',
            'schedule_type' => 'nullable|string|in:ALL_DAY,BUSINESS_HOURS,CUSTOM_WINDOW',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'employment_type_restrictions' => 'nullable|array',
        ]);

        $profile = $this->service->createProfile($validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Profil akses berhasil dibuat.',
            'data' => $profile,
        ], 201);
    }

    public function showProfile(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view access profile.'], 403);
        }

        $profile = AccessProfile::with('accessRequests')->findOrFail($id);
        $this->service->assertProfileAccess($profile, $actor);

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    public function updateProfile(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageProfiles($actor)) {
            return response()->json(['message' => 'Unauthorized to update access profile.'], 403);
        }

        $profile = AccessProfile::findOrFail($id);
        $this->service->assertProfileAccess($profile, $actor);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'building_name' => 'nullable|string|max:100',
            'allowed_doors' => 'sometimes|array|min:1',
            'allowed_doors.*' => 'required_with:allowed_doors',
            'schedule_type' => 'nullable|string|in:ALL_DAY,BUSINESS_HOURS,CUSTOM_WINDOW',
            'start_time' => 'nullable|string',
            'end_time' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'employment_type_restrictions' => 'nullable|array',
        ]);

        $updated = $this->service->updateProfile($profile, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Profil akses berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    // =============================================================
    // ACCESS REQUESTS
    // =============================================================

    public function requests(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view access requests.'], 403);
        }

        $requests = $this->service->getRequests($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->createRequest($actor)) {
            return response()->json(['message' => 'Unauthorized to submit access request.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'access_profile_id' => 'nullable|exists:access_profiles,id',
            'specific_doors' => 'nullable|array',
            'building_name' => 'nullable|string|max:100',
            'business_reason' => 'required|string|min:5',
            'valid_from' => 'nullable|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'notes' => 'nullable|string',
        ]);

        // Self-service enforcement: regular employees/interns can only request for themselves
        if (in_array(strtolower((string)$actor->role), ['employee', 'intern'], true)) {
            if (!empty($validated['employee_id']) && (int)$validated['employee_id'] !== (int)$actor->id) {
                return response()->json(['message' => 'Anda hanya berwenang mengajukan hak akses untuk diri sendiri.'], 403);
            }
            if (empty($validated['employee_id']) && empty($validated['internship_id'])) {
                $validated['employee_id'] = $actor->id;
            }
        }

        $accessRequest = $this->service->createRequest($validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan hak akses berhasil dikirim.',
            'data' => $accessRequest,
        ], 201);
    }

    public function showRequest(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        $accessRequest = AccessRequest::with(['employee', 'internship', 'accessProfile', 'requestedByAdmin', 'approvedByAdmin'])->findOrFail($id);

        if (!$this->policy->viewRequest($actor, $accessRequest)) {
            return response()->json(['message' => 'Unauthorized to view this access request.'], 403);
        }
        $this->service->assertRequestAccess($accessRequest, $actor);

        return response()->json([
            'success' => true,
            'data' => $accessRequest,
        ]);
    }

    public function approveRequest(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        $accessRequest = AccessRequest::findOrFail($id);

        if (!$this->policy->approveRequest($actor, $accessRequest)) {
            return response()->json(['message' => 'Unauthorized to approve this access request.'], 403);
        }

        try {
            $notes = $request->input('notes');
            $approved = $this->service->approveRequest($accessRequest, $actor, $notes);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan hak akses berhasil disetujui dan antrean sinkronisasi perangkat telah dijadwalkan.',
                'data' => $approved,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    public function rejectRequest(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        $accessRequest = AccessRequest::findOrFail($id);

        if (!$this->policy->approveRequest($actor, $accessRequest)) {
            return response()->json(['message' => 'Unauthorized to process this access request.'], 403);
        }

        $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        try {
            $rejected = $this->service->rejectRequest($accessRequest, $request->reason, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Pengajuan hak akses ditolak.',
                'data' => $rejected,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    // =============================================================
    // CREDENTIAL CENTER
    // =============================================================

    public function credentials(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewCredentials($actor)) {
            return response()->json(['message' => 'Unauthorized to view credential registry.'], 403);
        }

        $credentials = $this->service->getCredentials($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $credentials,
        ]);
    }

    public function storeCredential(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCredentials($actor)) {
            return response()->json(['message' => 'Unauthorized to issue credentials.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'credential_type' => 'required|string|in:CARD,FINGERPRINT_STATUS,FACE_STATUS,PIN_STATUS,QR,MOBILE,OTHER',
            'card_number' => 'nullable|string|max:64',
            'external_reference' => 'nullable|string|max:100',
            'biometric_status' => 'nullable|string|in:NOT_ENROLLED,PENDING,ENROLLED,SYNC_PENDING,SYNCED,FAILED,REVOKED',
            'notes' => 'nullable|string',
        ]);

        try {
            $credential = $this->service->issueCredential($validated, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Kredensial berhasil diterbitkan.',
                'data' => $credential,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validasi kredensial gagal.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function showCredential(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        $credential = CredentialRecord::with(['employee', 'internship', 'deviceSyncs.door'])->findOrFail($id);

        if (!$this->policy->viewCredentials($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }
        $this->service->assertCredentialAccess($credential, $actor);

        if (in_array(strtolower((string)$actor->role), ['employee', 'intern'], true) && $credential->employee_id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized to view this credential.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $credential,
        ]);
    }

    public function revokeCredential(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageCredentials($actor)) {
            return response()->json(['message' => 'Unauthorized to revoke credentials.'], 403);
        }

        $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $credential = CredentialRecord::with('employee')->findOrFail($id);
        $this->service->assertCredentialAccess($credential, $actor);
        $revoked = $this->service->revokeCredential($credential, $request->reason, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Kredensial berhasil dicabut dan jadwal pencabutan perangkat telah dibuat.',
            'data' => $revoked,
        ]);
    }

    // =============================================================
    // DEVICE SYNC QUEUE
    // =============================================================

    public function deviceSyncs(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewDeviceSyncs($actor)) {
            return response()->json(['message' => 'Unauthorized to view device sync queue.'], 403);
        }

        $syncs = $this->service->getDeviceSyncs($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $syncs,
        ]);
    }

    public function retryDeviceSync(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->syncDevice($actor)) {
            return response()->json(['message' => 'Unauthorized to retry device sync.'], 403);
        }

        $sync = CredentialDeviceSync::with('door')->findOrFail($id);
        $retried = $this->service->retryDeviceSync($sync, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Sinkronisasi perangkat dijadwalkan ulang.',
            'data' => $retried,
        ]);
    }

    // =============================================================
    // E-MONEY REGISTRY (STRICTLY ADMIN ONLY)
    // =============================================================

    public function emoneyCards(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized to view E-Money registry.'], 403);
        }

        // Strictly forbid technical roles without permission from viewing private e-money registry
        if (in_array(strtolower((string)$actor->role), ['developer', 'devops', 'infra_admin'], true) && !$actor->isSuperAdmin()) {
            return response()->json(['message' => 'Peran teknis tidak memiliki izin melihat registri kartu E-Money karyawan.'], 403);
        }

        $cards = $this->service->getEmoneyCards($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $cards,
        ]);
    }

    public function storeEmoneyCard(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized to register E-Money cards.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'provider' => 'required|string|in:MANDIRI_EMONEY,BCA_FLAZZ,BNI_TAPCASH,BRI_BRIZZI,JAKCARD,OTHER',
            'card_number' => 'required|string|min:8|max:32',
            'issued_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:issued_at',
            'notes' => 'nullable|string',
        ]);

        try {
            $card = $this->service->registerEmoneyCard($validated, $actor);

            return response()->json([
                'success' => true,
                'message' => 'Kartu E-Money berhasil didaftarkan.',
                'data' => $card,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Pendaftaran kartu e-money gagal.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function showEmoneyCard(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $card = EmoneyCard::with(['employee', 'internship', 'assignedByAdmin'])->findOrFail($id);

        // Employee self-service check
        if (in_array(strtolower((string)$actor->role), ['employee', 'intern'], true) && $card->employee_id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }

    public function updateEmoneyStatus(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->manageEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized to update E-Money status.'], 403);
        }

        $request->validate([
            'status' => 'required|string|in:AVAILABLE,ASSIGNED,ACTIVE,SUSPENDED,LOST,RETURNED,EXPIRED,REVOKED',
            'notes' => 'nullable|string',
        ]);

        $card = EmoneyCard::findOrFail($id);
        $updated = $this->service->updateEmoneyStatus($card, $request->status, $request->notes, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Status kartu E-Money berhasil diperbarui.',
            'data' => $updated,
        ]);
    }
}

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
use OpenApi\Attributes as OA;

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

    #[OA\Get(
        path: '/access/metrics',
        summary: 'Metrik Provisioning Hak Akses',
        description: 'Mendapatkan statistik ringkas profil akses, pengajuan, kredensial, dan status sinkronisasi.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Metrik berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'success' => true,
                        'data' => [
                            'total_profiles' => 5,
                            'total_requests' => 12,
                            'pending_requests' => 2,
                            'total_credentials' => 45,
                            'pending_device_syncs' => 0
                        ]
                    ]
                )
            )
        ]
    )]
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

    #[OA\Get(
        path: '/access/profiles',
        summary: 'Daftar Profil Hak Akses',
        description: 'Mendapatkan daftar profil hak akses berpintu dan terstruktur.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar profil hak akses berhasil diambil')
        ]
    )]
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

    #[OA\Post(
        path: '/access/profiles',
        summary: 'Buat Profil Hak Akses Baru',
        description: '⚠️ Belum diaktifkan — device write masih dinonaktifkan sampai otorisasi eksplisit (Phase 9 PLANNED status). Membuat definisi profil hak akses baru.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'PROFILE-SERVER-ADMIN'),
                    new OA\Property(property: 'name', type: 'string', example: 'Akses Khusus Tim Server'),
                    new OA\Property(property: 'allowed_doors', type: 'array', items: new OA\Items(type: 'string'), example: ['DOOR-001']),
                    new OA\Property(property: 'schedule_type', type: 'string', example: 'ALL_DAY')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Profil akses berhasil dibuat'),
            new OA\Response(response: 403, description: 'Forbidden')
        ]
    )]
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

    #[OA\Get(
        path: '/access/profiles/{id}',
        summary: 'Detail Profil Hak Akses',
        description: 'Mendapatkan detail profil hak akses spesifik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Profil Akses', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail profil akses ditemukan')
        ]
    )]
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

    #[OA\Put(
        path: '/access/profiles/{id}',
        summary: 'Perbarui Profil Hak Akses',
        description: '⚠️ Belum diaktifkan — device write masih dinonaktifkan sampai otorisasi eksplisit (Phase 9 PLANNED status). Memperbarui konfigurasi profil hak akses.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Profil Akses', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Akses Tim Server Lt 1 & 2')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profil akses berhasil diperbarui')
        ]
    )]
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

    #[OA\Get(
        path: '/access/requests',
        summary: 'Daftar Pengajuan Hak Akses',
        description: 'Mendapatkan daftar pengajuan hak akses pintu karyawan / magang.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar pengajuan berhasil diambil')
        ]
    )]
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

    #[OA\Post(
        path: '/access/requests',
        summary: 'Pengajuan Hak Akses Baru',
        description: 'Mengajukan hak akses pintu baru untuk karyawan atau anak magang.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['business_reason'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 1),
                    new OA\Property(property: 'access_profile_id', type: 'integer', example: 1),
                    new OA\Property(property: 'business_reason', type: 'string', example: 'Perlu akses ke server room untuk maintenance mingguan')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Pengajuan hak akses berhasil dikirim')
        ]
    )]
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

    #[OA\Get(
        path: '/access/requests/{id}',
        summary: 'Detail Pengajuan Hak Akses',
        description: 'Mendapatkan detail pengajuan hak akses spesifik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail pengajuan ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/access/requests/{id}/approve',
        summary: 'Persetujuan Pengajuan Hak Akses',
        description: '⚠️ Belum diaktifkan — device write masih dinonaktifkan sampai otorisasi eksplisit (Phase 9 PLANNED status). Menyetujui pengajuan hak akses.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan berhasil disetujui')
        ]
    )]
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

    #[OA\Post(
        path: '/access/requests/{id}/reject',
        summary: 'Penolakan Pengajuan Hak Akses',
        description: 'Menolak pengajuan hak akses dengan alasan penolakan.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Tidak memiliki otorisasi keamanan tinggi')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan hak akses ditolak')
        ]
    )]
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

    #[OA\Get(
        path: '/access/credentials',
        summary: 'Daftar Kredensial Registri',
        description: 'Mendapatkan registri kredensial kartu / biometrik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar kredensial berhasil diambil')
        ]
    )]
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

    #[OA\Post(
        path: '/access/credentials',
        summary: 'Penerbitan Kredensial Baru',
        description: 'Menerbitkan kredensial baru (kartu/biometrik) untuk karyawan/magang.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['credential_type'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 1),
                    new OA\Property(property: 'credential_type', type: 'string', example: 'CARD'),
                    new OA\Property(property: 'card_number', type: 'string', example: 'CARD-99081')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Kredensial berhasil diterbitkan')
        ]
    )]
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

    #[OA\Get(
        path: '/access/credentials/{id}',
        summary: 'Detail Kredensial Registri',
        description: 'Mendapatkan detail catatan kredensial.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kredensial', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Catatan kredensial ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/access/credentials/{id}/revoke',
        summary: 'Pencabutan Kredensial',
        description: 'Mencabut kredensial aktif.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kredensial', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Kartu hilang / karyawan resign')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Kredensial berhasil dicabut')
        ]
    )]
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

    #[OA\Get(
        path: '/access/device-syncs',
        summary: 'Antrean Sinkronisasi Perangkat',
        description: 'Mendapatkan daftar antrean pekerjaan sinkronisasi perangkat.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar antrean sinkronisasi perangkat berhasil diambil')
        ]
    )]
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

    #[OA\Post(
        path: '/access/device-syncs/{id}/retry',
        summary: 'Coba Ulang Sinkronisasi Perangkat',
        description: 'Menjalankan ulang pekerjaan sinkronisasi perangkat yang sempat gagal.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pekerjaan Sync', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Sinkronisasi perangkat berhasil dijalankan ulang')
        ]
    )]
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

    #[OA\Get(
        path: '/access/emoney',
        summary: 'Registri Kartu E-Money Admin',
        description: 'Mendapatkan daftar kartu E-Money yang terdaftar (Khusus Admin/HRD).',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar kartu E-Money berhasil diambil')
        ]
    )]
    public function emoneyCards(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized to view E-Money registry.'], 403);
        }

        if (in_array(strtolower((string)$actor->role), ['developer', 'devops', 'infra_admin'], true) && !$actor->isSuperAdmin()) {
            return response()->json(['message' => 'Peran teknis tidak memiliki izin melihat registri kartu E-Money karyawan.'], 403);
        }

        $cards = $this->service->getEmoneyCards($request->all(), $actor);

        return response()->json([
            'success' => true,
            'data' => $cards,
        ]);
    }

    #[OA\Post(
        path: '/access/emoney',
        summary: 'Registrasi Kartu E-Money',
        description: 'Mendaftarkan kartu E-Money baru.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['provider', 'card_number'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 1),
                    new OA\Property(property: 'provider', type: 'string', example: 'MANDIRI_EMONEY'),
                    new OA\Property(property: 'card_number', type: 'string', example: '6032981012345678')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Kartu E-Money berhasil didaftarkan')
        ]
    )]
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

    #[OA\Get(
        path: '/access/emoney/{id}',
        summary: 'Detail Kartu E-Money',
        description: 'Mendapatkan detail kartu E-Money spesifik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kartu E-Money', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail kartu E-Money ditemukan')
        ]
    )]
    public function showEmoneyCard(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewEmoney($actor)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $card = EmoneyCard::with(['employee', 'internship', 'assignedByAdmin'])->findOrFail($id);

        if (in_array(strtolower((string)$actor->role), ['employee', 'intern'], true) && $card->employee_id !== $actor->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $card,
        ]);
    }

    #[OA\Post(
        path: '/access/emoney/{id}/status',
        summary: 'Perbarui Status Kartu E-Money',
        description: 'Memperbarui status operasional kartu E-Money (AVAILABLE | ASSIGNED | SUSPENDED | LOST | REVOKED).',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kartu E-Money', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'ACTIVE'),
                    new OA\Property(property: 'notes', type: 'string', example: 'Diaktifkan untuk operasional kantor')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Status kartu E-Money berhasil diperbarui')
        ]
    )]
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

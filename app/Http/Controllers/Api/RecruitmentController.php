<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Policies\RecruitmentPolicy;
use App\Services\RecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

class RecruitmentController extends Controller
{
    protected RecruitmentService $recruitmentService;
    protected RecruitmentPolicy $policy;

    public function __construct(RecruitmentService $recruitmentService, RecruitmentPolicy $policy)
    {
        $this->recruitmentService = $recruitmentService;
        $this->policy = $policy;
    }

    private function getAuthAdmin()
    {
        return Auth::guard('sanctum')->user() ?? Auth::user();
    }

    #[OA\Get(
        path: '/recruitment/metrics',
        summary: 'Metrik Rekrutmen & ATS',
        description: 'Mendapatkan statistik ringkas pelamar, lowongan aktif, dan konversi rekrutmen.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Metrik rekrutmen berhasil diambil')
        ]
    )]
    public function metrics(): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->recruitmentService->getMetrics(),
        ]);
    }

    #[OA\Get(
        path: '/recruitment/vacancies',
        summary: 'Daftar Lowongan Kerja',
        description: 'Mendapatkan daftar lowongan pekerjaan terbuka.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar lowongan kerja berhasil diambil')
        ]
    )]
    public function vacancies(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        $query = JobVacancy::with(['division', 'position', 'building', 'creator'])
            ->withCount('applications');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('division_id')) {
            $query->where('division_id', $request->query('division_id'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('vacancy_code', 'like', "%{$search}%");
            });
        }

        $vacancies = $query->latest()->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $vacancies,
        ]);
    }

    #[OA\Post(
        path: '/recruitment/vacancies',
        summary: 'Tambah Lowongan Kerja',
        description: 'Membuat posisi lowongan pekerjaan baru.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'employment_type', 'experience_level', 'quota', 'description'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Frontend Developer'),
                    new OA\Property(property: 'employment_type', type: 'string', example: 'FULL_TIME'),
                    new OA\Property(property: 'experience_level', type: 'string', example: 'MID'),
                    new OA\Property(property: 'quota', type: 'integer', example: 2),
                    new OA\Property(property: 'description', type: 'string', example: 'Mengembangkan aplikasi web React/Vue')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Lowongan pekerjaan berhasil dibuat')
        ]
    )]
    public function storeVacancy(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->manageVacancies($admin)) {
            return response()->json(['message' => 'Unauthorized to manage vacancies.'], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'division_id' => 'nullable|exists:divisions,id',
            'position_id' => 'nullable|exists:positions,id',
            'building_id' => 'nullable|exists:buildings,id',
            'employment_type' => 'required|in:FULL_TIME,CONTRACT,INTERNSHIP,PART_TIME',
            'experience_level' => 'required|in:ENTRY,JUNIOR,MID,SENIOR,LEAD',
            'quota' => 'required|integer|min:1',
            'salary_min' => 'nullable|numeric|min:0',
            'salary_max' => 'nullable|numeric|gte:salary_min',
            'description' => 'required|string',
            'requirements' => 'nullable|string',
            'status' => 'nullable|in:DRAFT,OPEN,CLOSED,ARCHIVED',
            'deadline' => 'nullable|date',
        ]);

        $vacancy = $this->recruitmentService->createVacancy($validated, $admin);

        return response()->json([
            'status' => 'success',
            'message' => 'Lowongan pekerjaan berhasil dibuat.',
            'data' => $vacancy->load(['division', 'position', 'building']),
        ], 201);
    }

    #[OA\Get(
        path: '/recruitment/vacancies/{id}',
        summary: 'Detail Lowongan Kerja',
        description: 'Mendapatkan rincian lowongan kerja beserta daftarnya.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lowongan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail lowongan ditemukan')
        ]
    )]
    public function showVacancy(int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        $vacancy = JobVacancy::with(['division', 'position', 'building', 'creator', 'applications.candidate'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $vacancy,
        ]);
    }

    #[OA\Put(
        path: '/recruitment/vacancies/{id}',
        summary: 'Perbarui Lowongan Kerja',
        description: 'Memperbarui data lowongan kerja.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lowongan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lowongan kerja diperbarui')
        ]
    )]
    public function updateVacancy(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->manageVacancies($admin)) {
            return response()->json(['message' => 'Unauthorized to manage vacancies.'], 403);
        }

        $vacancy = JobVacancy::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'division_id' => 'nullable|exists:divisions,id',
            'position_id' => 'nullable|exists:positions,id',
            'building_id' => 'nullable|exists:buildings,id',
            'employment_type' => 'sometimes|in:FULL_TIME,CONTRACT,INTERNSHIP,PART_TIME',
            'experience_level' => 'sometimes|in:ENTRY,JUNIOR,MID,SENIOR,LEAD',
            'quota' => 'sometimes|integer|min:1',
            'salary_min' => 'nullable|numeric|min:0',
            'salary_max' => 'nullable|numeric',
            'description' => 'sometimes|string',
            'requirements' => 'nullable|string',
            'status' => 'sometimes|in:DRAFT,OPEN,CLOSED,ARCHIVED',
            'deadline' => 'nullable|date',
        ]);

        $vacancy->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Lowongan pekerjaan diperbarui.',
            'data' => $vacancy->fresh(['division', 'position', 'building']),
        ]);
    }

    #[OA\Get(
        path: '/recruitment/candidates',
        summary: 'Daftar Kandidat Pelamar',
        description: 'Mendapatkan daftar kandidat dalam database rekrutmen.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar kandidat berhasil diambil')
        ]
    )]
    public function candidates(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        $query = Candidate::with(['convertedEmployee', 'applications.vacancy']);

        if ($request->boolean('talent_pool_only')) {
            $query->where('talent_pool_status', true);
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('candidate_no', 'like', "%{$search}%");
            });
        }

        $candidates = $query->latest()->paginate($request->query('per_page', 15));

        return response()->json([
            'status' => 'success',
            'data' => $candidates,
        ]);
    }

    #[OA\Post(
        path: '/recruitment/candidates',
        summary: 'Tambah Data Kandidat',
        description: 'Mendaftarkan kandidat baru.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['first_name', 'email', 'phone'],
                properties: [
                    new OA\Property(property: 'first_name', type: 'string', example: 'Ahmad'),
                    new OA\Property(property: 'last_name', type: 'string', example: 'Fauzi'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'ahmad.fauzi@example.com'),
                    new OA\Property(property: 'phone', type: 'string', example: '081299887766')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Kandidat berhasil didaftarkan')
        ]
    )]
    public function storeCandidate(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->manageCandidates($admin)) {
            return response()->json(['message' => 'Unauthorized to manage candidates.'], 403);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|unique:candidates,email',
            'phone' => 'required|string|max:30',
            'national_id' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'current_company' => 'nullable|string|max:150',
            'current_position' => 'nullable|string|max:150',
            'resume_path' => 'nullable|string|max:255',
            'portfolio_url' => 'nullable|url|max:255',
            'linkedin_url' => 'nullable|url|max:255',
            'source' => 'nullable|in:CAREER_SITE,LINKEDIN,REFERRAL,JOB_FAIR,INTERNAL',
            'talent_pool_status' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $candidate = $this->recruitmentService->createCandidate($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data kandidat berhasil disimpan.',
            'data' => $candidate,
        ], 201);
    }

    #[OA\Get(
        path: '/recruitment/candidates/{id}',
        summary: 'Detail Data Kandidat',
        description: 'Mendapatkan rincian profil kandidat pelamar.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Kandidat', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail kandidat ditemukan')
        ]
    )]
    public function showCandidate(int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        $candidate = Candidate::with(['convertedEmployee', 'applications.vacancy', 'applications.interviews', 'applications.offers'])
            ->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => $candidate,
        ]);
    }

    #[OA\Get(
        path: '/recruitment/applications',
        summary: 'Daftar Berkas Lamaran',
        description: 'Mendapatkan daftar berkas lamaran pekerjaan.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar lamaran berhasil diambil')
        ]
    )]
    public function applications(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized recruitment scope.'], 403);
        }

        $query = JobApplication::with(['vacancy.division', 'candidate', 'interviews.interviewer', 'latestOffer']);

        if ($request->filled('vacancy_id')) {
            $query->where('job_vacancy_id', $request->query('vacancy_id'));
        }

        if ($request->filled('stage')) {
            $query->where('current_stage', $request->query('stage'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($admin->role === 'supervisor') {
            $divisionId = $admin->employee?->division_id;
            $adminId = $admin->id;

            $query->where(function ($q) use ($divisionId, $adminId) {
                if ($divisionId) {
                    $q->whereHas('vacancy', function ($vq) use ($divisionId) {
                        $vq->where('division_id', $divisionId);
                    });
                }
                $q->orWhereHas('interviews', function ($iq) use ($adminId) {
                    $iq->where('interviewer_id', $adminId);
                });
            });
        }

        $applications = $query->latest()->paginate($request->query('per_page', 25));

        return response()->json([
            'status' => 'success',
            'data' => $applications,
        ]);
    }

    #[OA\Post(
        path: '/recruitment/applications',
        summary: 'Daftarkan Lamaran Kandidat',
        description: 'Mendaftarkan lamaran kandidat pada lowongan pekerjaan.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['job_vacancy_id', 'candidate_id'],
                properties: [
                    new OA\Property(property: 'job_vacancy_id', type: 'integer', example: 1),
                    new OA\Property(property: 'candidate_id', type: 'integer', example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Lamaran kandidat berhasil didaftarkan')
        ]
    )]
    public function apply(Request $request): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        if (!$admin || !$this->policy->manageCandidates($admin)) {
            return response()->json(['message' => 'Unauthorized to apply candidate.'], 403);
        }

        $validated = $request->validate([
            'job_vacancy_id' => 'required|exists:job_vacancies,id',
            'candidate_id' => 'required|exists:candidates,id',
            'expected_salary' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $vacancy = JobVacancy::findOrFail($validated['job_vacancy_id']);
        $candidate = Candidate::findOrFail($validated['candidate_id']);

        $application = $this->recruitmentService->submitApplication($vacancy, $candidate, $validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Lamaran kandidat berhasil didaftarkan.',
            'data' => $application->load(['vacancy', 'candidate']),
        ], 201);
    }

    #[OA\Post(
        path: '/recruitment/applications/{id}/stage',
        summary: 'Ubah Tahapan Lamaran',
        description: 'Memindahkan kandidat ke tahapan rekrutmen berikutnya.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lamaran', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stage'],
                properties: [
                    new OA\Property(property: 'stage', type: 'string', example: 'USER_INTERVIEW')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tahapan lamaran berhasil diubah')
        ]
    )]
    public function transitionStage(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        $application = JobApplication::findOrFail($id);

        if (!$admin || !$this->policy->manageApplication($admin, $application)) {
            return response()->json(['message' => 'Unauthorized to move application stage.'], 403);
        }

        $validated = $request->validate([
            'stage' => 'required|in:APPLIED,SCREENING,HR_INTERVIEW,TECHNICAL_TEST,USER_INTERVIEW,MANAGEMENT_REVIEW,OFFER,ACCEPTED,REJECTED,TALENT_POOL,WITHDRAWN',
            'reason' => 'nullable|string',
        ]);

        $updated = $this->recruitmentService->transitionStage($application, $validated['stage'], $validated['reason'] ?? null, $admin);

        return response()->json([
            'status' => 'success',
            'message' => "Tahapan lamaran berhasil diubah ke {$validated['stage']}.",
            'data' => $updated->fresh(['vacancy', 'candidate']),
        ]);
    }

    #[OA\Post(
        path: '/recruitment/applications/{id}/interview',
        summary: 'Jadwalkan Interview',
        description: 'Membuat jadwal wawancara/interview untuk lamaran kandidat.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lamaran', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stage_code', 'scheduled_at'],
                properties: [
                    new OA\Property(property: 'stage_code', type: 'string', example: 'USER_INTERVIEW'),
                    new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', example: '2026-09-25 10:00:00')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Jadwal interview berhasil dibuat')
        ]
    )]
    public function scheduleInterview(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        $application = JobApplication::findOrFail($id);

        if (!$admin || !$this->policy->manageApplication($admin, $application)) {
            return response()->json(['message' => 'Unauthorized to schedule interview.'], 403);
        }

        $validated = $request->validate([
            'stage_code' => 'required|string',
            'interviewer_id' => 'nullable|exists:admins,id',
            'scheduled_at' => 'required|date|after:now',
            'duration_minutes' => 'nullable|integer|min:15|max:240',
            'location_or_link' => 'nullable|string|max:255',
        ]);

        $interview = $this->recruitmentService->scheduleInterview($application, $validated, $admin);

        return response()->json([
            'status' => 'success',
            'message' => 'Jadwal interview berhasil dibuat.',
            'data' => $interview->load(['application.candidate', 'interviewer']),
        ], 201);
    }

    #[OA\Put(
        path: '/recruitment/interviews/{id}/feedback',
        summary: 'Kirim Feedback Interview',
        description: 'Mengisi catatan hasil wawancara dan skor evaluasi kandidat.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Interview', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['feedback', 'score', 'recommendation'],
                properties: [
                    new OA\Property(property: 'feedback', type: 'string', example: 'Kandidat memahami arsitektur API dengan sangat baik'),
                    new OA\Property(property: 'score', type: 'integer', example: 85),
                    new OA\Property(property: 'recommendation', type: 'string', example: 'PROCEED')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Feedback interview berhasil disimpan')
        ]
    )]
    public function submitFeedback(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        $interview = Interview::findOrFail($id);

        if (!$admin || !$this->policy->submitInterviewFeedback($admin, $interview)) {
            return response()->json(['message' => 'Unauthorized to submit interview feedback.'], 403);
        }

        $validated = $request->validate([
            'feedback' => 'required|string',
            'score' => 'required|integer|min:1|max:100',
            'recommendation' => 'required|in:PROCEED,HOLD,REJECT',
        ]);

        $updated = $this->recruitmentService->submitInterviewFeedback($interview, $validated, $admin);

        return response()->json([
            'status' => 'success',
            'message' => 'Feedback interview berhasil disimpan.',
            'data' => $updated,
        ]);
    }

    #[OA\Post(
        path: '/recruitment/applications/{id}/offer',
        summary: 'Buat Surat Penawaran (Offering Letter)',
        description: 'Membuat surat penawaran kerja (offering letter) untuk kandidat terpilih.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lamaran', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['offered_salary', 'start_date', 'expiry_date'],
                properties: [
                    new OA\Property(property: 'offered_salary', type: 'number', example: 8500000),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-10-01'),
                    new OA\Property(property: 'expiry_date', type: 'string', format: 'date', example: '2026-10-05')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Offering letter berhasil dibuat')
        ]
    )]
    public function createOffer(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        $application = JobApplication::findOrFail($id);

        if (!$admin || !$this->policy->manageOffers($admin)) {
            return response()->json(['message' => 'Unauthorized to create job offer.'], 403);
        }

        $validated = $request->validate([
            'offered_salary' => 'required|numeric|min:0',
            'start_date' => 'required|date|after_or_equal:today',
            'expiry_date' => 'required|date|after:start_date',
            'terms' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $offer = $this->recruitmentService->createOffer($application, $validated, $admin);

        return response()->json([
            'status' => 'success',
            'message' => 'Offering letter berhasil dibuat.',
            'data' => $offer->load('application.candidate'),
        ], 201);
    }

    #[OA\Post(
        path: '/recruitment/applications/{id}/convert-to-employee',
        summary: 'Angkat Kandidat Menjadi Karyawan',
        description: 'Mengonversi kandidat pelamar yang menerima penawaran kerja menjadi karyawan tetap/kontrak resmi.',
        tags: ['Recruitment & ATS'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lamaran', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Kandidat berhasil diangkat sebagai karyawan')
        ]
    )]
    public function convertToEmployee(Request $request, int $id): JsonResponse
    {
        $admin = $this->getAuthAdmin();
        $application = JobApplication::findOrFail($id);

        if (!$admin || !$this->policy->convertCandidate($admin)) {
            return response()->json(['message' => 'Unauthorized to convert candidate to employee.'], 403);
        }

        $validated = $request->validate([
            'employee_no' => 'nullable|string|unique:employees,employee_no',
            'division_id' => 'nullable|exists:divisions,id',
            'position_id' => 'nullable|exists:positions,id',
            'building_id' => 'nullable|exists:buildings,id',
            'employment_status' => 'nullable|string|in:permanent,contract,probation,internship',
        ]);

        $employee = $this->recruitmentService->hireAndConvertToEmployee($application, $validated, $admin);

        return response()->json([
            'status' => 'success',
            'message' => "Kandidat {$application->candidate->full_name} berhasil diangkat sebagai karyawan ({$employee->employee_id}).",
            'data' => $employee->load(['division', 'position', 'building']),
        ], 200);
    }
}

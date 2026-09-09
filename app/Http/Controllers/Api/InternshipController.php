<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Internship;
use App\Models\InternshipDailyActivity;
use App\Models\InternshipReport;
use App\Services\InternshipService;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InternshipController extends Controller
{
    protected InternshipService $internshipService;
    protected PortalAccess $portalAccess;

    public function __construct(InternshipService $internshipService, PortalAccess $portalAccess)
    {
        $this->internshipService = $internshipService;
        $this->portalAccess = $portalAccess;
    }

    /**
     * Get Internship metrics
     */
    public function metrics(): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.view')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->internshipService->getMetrics()
        ]);
    }

    /**
     * List internships with filtering and scoping
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.view')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $query = Internship::with(['employee', 'candidate', 'division', 'mentor']);

        // Scope for supervisor: only see assigned interns or division
        if (strtolower((string) $user->role) === 'supervisor') {
            $empId = $user->employee_id ?? $user->id;
            $divId = $user->employee?->division_id ?? $user->division_id ?? null;
            $query->where(function ($q) use ($empId, $divId) {
                $q->where('mentor_id', $empId)
                  ->orWhere('supervisor_id', $empId);
                if ($divId) {
                    $q->orWhere('division_id', $divId);
                }
            });
        }

        // Scope for intern/employee: only see own record
        if (in_array(strtolower((string) $user->role), ['intern', 'employee'], true)) {
            $query->where('employee_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('intern_id', 'like', "%{$s}%")
                  ->orWhere('institution', 'like', "%{$s}%")
                  ->orWhere('major', 'like', "%{$s}%")
                  ->orWhere('position_title', 'like', "%{$s}%")
                  ->orWhereHas('employee', function ($eq) use ($s) {
                      $eq->where('name', 'like', "%{$s}%")
                         ->orWhere('employee_id', 'like', "%{$s}%");
                  });
            });
        }

        $internships = $query->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $internships
        ]);
    }

    /**
     * Create Internship directly
     */
    public function store(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.manage')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to create internship.'], 403);
        }

        $validated = $request->validate([
            'institution' => 'required|string|max:255',
            'major' => 'required|string|max:255',
            'education_level' => 'nullable|string|in:SMK,D3,S1,S2',
            'semester' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'division_id' => 'nullable|exists:divisions,id',
            'position_title' => 'nullable|string|max:255',
            'mentor_id' => 'nullable|exists:employees,id',
            'supervisor_id' => 'nullable|exists:employees,id',
            'campus_supervisor_name' => 'nullable|string|max:255',
            'campus_supervisor_contact' => 'nullable|string|max:255',
            'project_assignment' => 'nullable|string',
            'employee_id' => 'nullable|exists:employees,id',
            'candidate_id' => 'nullable|exists:candidates,id',
        ]);

        try {
            $internship = $this->internshipService->createInternship($validated, $user);
            return response()->json([
                'status' => 'success',
                'message' => 'Program magang berhasil dibuat.',
                'data' => $internship->load(['employee', 'candidate', 'division', 'mentor'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Get single internship details with activities, reports, and evaluations
     */
    public function show(int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::with(['employee', 'candidate', 'division', 'mentor', 'dailyActivities', 'reports', 'evaluations'])->find($id);

        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        // Check privacy / policy view
        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->view($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized access to internship details.'], 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => $internship
        ]);
    }

    /**
     * Update internship
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->update($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to update internship.'], 403);
        }

        $validated = $request->validate([
            'institution' => 'sometimes|string|max:255',
            'major' => 'sometimes|string|max:255',
            'education_level' => 'nullable|string',
            'semester' => 'nullable|integer',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date',
            'division_id' => 'nullable|exists:divisions,id',
            'position_title' => 'nullable|string|max:255',
            'mentor_id' => 'nullable|exists:employees,id',
            'campus_supervisor_name' => 'nullable|string|max:255',
            'campus_supervisor_contact' => 'nullable|string|max:255',
            'project_assignment' => 'nullable|string',
            'status' => 'nullable|string',
        ]);

        if (isset($validated['mentor_id']) && $internship->employee_id && (int) $validated['mentor_id'] === (int) $internship->employee_id) {
            return response()->json(['status' => 'error', 'message' => 'Mentor tidak boleh pemagang yang sama.'], 422);
        }

        $internship->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Data magang berhasil diperbarui.',
            'data' => $internship->load(['employee', 'candidate', 'division', 'mentor'])
        ]);
    }

    /**
     * Convert Candidate to Intern
     */
    public function convertCandidate(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.manage')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'candidate_id' => 'required|exists:candidates,id',
            'institution' => 'required|string|max:255',
            'major' => 'required|string|max:255',
            'education_level' => 'nullable|string|in:SMK,D3,S1,S2',
            'semester' => 'nullable|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'division_id' => 'nullable|exists:divisions,id',
            'building_id' => 'nullable|exists:buildings,id',
            'position_title' => 'nullable|string|max:255',
            'mentor_id' => 'nullable|exists:employees,id',
            'job_application_id' => 'nullable|exists:job_applications,id',
            'campus_supervisor_name' => 'nullable|string|max:255',
            'campus_supervisor_contact' => 'nullable|string|max:255',
            'project_assignment' => 'nullable|string',
        ]);

        $candidate = Candidate::find($validated['candidate_id']);

        try {
            $internship = $this->internshipService->convertCandidateToIntern($candidate, $validated, $user);
            return response()->json([
                'status' => 'success',
                'message' => "Kandidat {$candidate->full_name} berhasil dikonversi menjadi Pemagang resmi!",
                'data' => $internship->load(['employee', 'candidate', 'division', 'mentor'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Assign Mentor
     */
    public function assignMentor(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.manage')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'mentor_id' => 'required|exists:employees,id',
        ]);

        try {
            $updated = $this->internshipService->assignMentor($internship, $validated['mentor_id'], $user);
            return response()->json([
                'status' => 'success',
                'message' => 'Mentor berhasil ditugaskan.',
                'data' => $updated->load('mentor')
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Activate Internship
     */
    public function activate(int $id): JsonResponse
    {
        $user = Auth::user();
        if (!$this->portalAccess->can($user, 'internship.manage')) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $activated = $this->internshipService->activateInternship($internship, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Program magang berhasil diaktifkan.',
            'data' => $activated
        ]);
    }

    /**
     * Complete Internship
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->complete($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to complete internship.'], 403);
        }

        $validated = $request->validate([
            'completion_notes' => 'nullable|string',
            'certificate_no' => 'nullable|string',
        ]);

        $completed = $this->internshipService->completeInternship($internship, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Program magang berhasil diselesaikan.',
            'data' => $completed
        ]);
    }

    /**
     * List Daily Activities
     */
    public function activities(int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->view($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $activities = $internship->dailyActivities()->latest('activity_date')->get();

        return response()->json([
            'status' => 'success',
            'data' => $activities
        ]);
    }

    /**
     * Log Daily Activity
     */
    public function storeActivity(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->logActivity($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to log activity.'], 403);
        }

        $validated = $request->validate([
            'activity_date' => 'required|date',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'project_task_ref' => 'nullable|string|max:255',
            'start_time' => 'nullable|string|max:10',
            'end_time' => 'nullable|string|max:10',
            'progress_percent' => 'nullable|integer|between:0,100',
            'attachment_url' => 'nullable|string|max:255',
        ]);

        $activity = $this->internshipService->logDailyActivity($internship, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Aktivitas harian berhasil dicatat.',
            'data' => $activity
        ], 201);
    }

    /**
     * Review Daily Activity
     */
    public function reviewActivity(Request $request, int $activityId): JsonResponse
    {
        $user = Auth::user();
        $activity = InternshipDailyActivity::with('internship')->find($activityId);
        if (!$activity) {
            return response()->json(['status' => 'error', 'message' => 'Aktivitas tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->reviewActivity($user, $activity->internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to review activity.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:REVIEWED,REJECTED',
            'mentor_notes' => 'nullable|string',
        ]);

        $reviewed = $this->internshipService->reviewDailyActivity($activity, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Aktivitas harian berhasil diverifikasi.',
            'data' => $reviewed
        ]);
    }

    /**
     * List Reports
     */
    public function reports(int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->view($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $reports = $internship->reports()->latest()->get();

        return response()->json([
            'status' => 'success',
            'data' => $reports
        ]);
    }

    /**
     * Submit Report
     */
    public function storeReport(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->submitReport($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to submit report.'], 403);
        }

        $validated = $request->validate([
            'report_type' => 'required|string|in:MONTHLY,MID_TERM,FINAL',
            'period_month' => 'nullable|string|max:20',
            'title' => 'required|string|max:255',
            'summary' => 'required|string',
            'achievements' => 'nullable|string',
            'issues_and_blockers' => 'nullable|string',
            'intern_notes' => 'nullable|string',
            'attachment_url' => 'nullable|string|max:255',
        ]);

        $report = $this->internshipService->submitReport($internship, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Laporan magang berhasil dikirimkan.',
            'data' => $report
        ], 201);
    }

    /**
     * Review Report
     */
    public function reviewReport(Request $request, int $reportId): JsonResponse
    {
        $user = Auth::user();
        $report = InternshipReport::with('internship')->find($reportId);
        if (!$report) {
            return response()->json(['status' => 'error', 'message' => 'Laporan tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->reviewReport($user, $report->internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to review report.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|string|in:APPROVED,REVISION_REQUIRED',
            'mentor_notes' => 'nullable|string',
        ]);

        $reviewed = $this->internshipService->reviewReport($report, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Laporan magang berhasil ditinjau.',
            'data' => $reviewed
        ]);
    }

    /**
     * List Evaluations
     */
    public function evaluations(int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->view($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        $evaluations = $internship->evaluations()->latest('evaluated_at')->get();

        return response()->json([
            'status' => 'success',
            'data' => $evaluations
        ]);
    }

    /**
     * Record Evaluation
     */
    public function storeEvaluation(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $internship = Internship::find($id);
        if (!$internship) {
            return response()->json(['status' => 'error', 'message' => 'Data magang tidak ditemukan.'], 404);
        }

        $policy = app(\App\Policies\InternshipPolicy::class);
        if (!$policy->evaluate($user, $internship)) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized to evaluate internship.'], 403);
        }

        $validated = $request->validate([
            'evaluation_type' => 'required|string|in:MID_TERM,FINAL',
            'discipline_score' => 'required|integer|between:1,100',
            'communication_score' => 'required|integer|between:1,100',
            'technical_score' => 'required|integer|between:1,100',
            'initiative_score' => 'required|integer|between:1,100',
            'teamwork_score' => 'required|integer|between:1,100',
            'attendance_score' => 'required|integer|between:1,100',
            'task_completion_score' => 'required|integer|between:1,100',
            'professionalism_score' => 'required|integer|between:1,100',
            'strengths' => 'nullable|string',
            'improvements' => 'nullable|string',
            'final_recommendation' => 'required|string|in:HIRE_AS_EMPLOYEE,EXTEND_INTERNSHIP,COMPLETE,NOT_RECOMMENDED',
            'evaluated_at' => 'nullable|date',
        ]);

        $evaluation = $this->internshipService->recordEvaluation($internship, $validated, $user);

        return response()->json([
            'status' => 'success',
            'message' => 'Evaluasi penilaian magang berhasil disimpan.',
            'data' => $evaluation
        ], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\JobDescription;
use App\Models\Skill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class JobDescriptionSkillController extends Controller
{
    #[OA\Get(
        path: '/admin/job-descriptions',
        summary: 'Daftar Deskripsi Pekerjaan (JD)',
        description: 'Mendapatkan daftar versi deskripsi pekerjaan (JD) dan persyaratan skill.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar JD berhasil diambil')
        ]
    )]
    public function jobDescriptions(Request $request)
    {
        $this->authorizeManagement($request->user());

        return response()->json(['status' => 'success', 'data' => JobDescription::with('skillRequirements.skill')->latest()->get()]);
    }

    #[OA\Post(
        path: '/admin/job-descriptions',
        summary: 'Tambah Deskripsi Pekerjaan (JD)',
        description: 'Membuat versi baru deskripsi pekerjaan beserta pemetaan skill terverifikasi.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'version', 'content'],
                properties: [
                    new OA\Property(property: 'position_id', type: 'integer', example: 1),
                    new OA\Property(property: 'title', type: 'string', example: 'Senior Backend Engineer'),
                    new OA\Property(property: 'version', type: 'integer', example: 1),
                    new OA\Property(property: 'content', type: 'string', example: 'Bertanggung jawab mengelola API & Database'),
                    new OA\Property(property: 'skill_requirements', type: 'array', items: new OA\Items(type: 'object'))
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'JD berhasil dibuat')
        ]
    )]
    public function storeJobDescription(Request $request)
    {
        $this->authorizeManagement($request->user());
        $data = $request->validate([
            'position_id' => 'nullable|exists:positions,id',
            'title' => 'required|string|max:255',
            'version' => 'required|integer|min:1',
            'content' => 'required|string',
            'effective_from' => 'nullable|date',
            'effective_until' => 'nullable|date|after_or_equal:effective_from',
            'skill_requirements' => 'array',
            'skill_requirements.*.skill_id' => 'required|exists:skills,id',
            'skill_requirements.*.required_level' => 'required|integer|min:1|max:5',
        ]);

        $requirements = $data['skill_requirements'] ?? [];
        unset($data['skill_requirements']);
        $data['created_by'] = $request->user()->id;

        $jobDescription = DB::transaction(function () use ($data, $requirements) {
            $jobDescription = JobDescription::create($data);
            foreach ($requirements as $requirement) {
                $jobDescription->skillRequirements()->create($requirement);
            }
            return $jobDescription->load('skillRequirements.skill');
        });

        return response()->json(['status' => 'success', 'data' => $jobDescription], 201);
    }

    #[OA\Post(
        path: '/admin/job-descriptions/{jobDescription}/publish',
        summary: 'Publikasikan Deskripsi Pekerjaan',
        description: 'Mengaktifkan status deskripsi pekerjaan menjadi PUBLISHED.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'jobDescription', in: 'path', description: 'ID Job Description', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'JD berhasil dipublikasikan')
        ]
    )]
    public function publishJobDescription(Request $request, JobDescription $jobDescription)
    {
        $this->authorizeManagement($request->user());
        $jobDescription->update(['status' => 'PUBLISHED']);
        return response()->json(['status' => 'success', 'data' => $jobDescription->fresh('skillRequirements.skill')]);
    }

    #[OA\Post(
        path: '/admin/job-descriptions/{jobDescription}/archive',
        summary: 'Arsip Deskripsi Pekerjaan',
        description: 'Mengarsipkan versi deskripsi pekerjaan.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'jobDescription', in: 'path', description: 'ID Job Description', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'JD berhasil diarsipkan')
        ]
    )]
    public function archiveJobDescription(Request $request, JobDescription $jobDescription)
    {
        $this->authorizeManagement($request->user());
        $jobDescription->update(['status' => 'ARCHIVED']);
        return response()->json(['status' => 'success', 'data' => $jobDescription->fresh('skillRequirements.skill')]);
    }

    #[OA\Get(
        path: '/admin/skills',
        summary: 'Daftar Matriks Skill',
        description: 'Mendapatkan master daftar keahlian/skill.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar skill berhasil diambil')
        ]
    )]
    public function skills(Request $request)
    {
        $this->authorizeManagement($request->user());
        return response()->json(['status' => 'success', 'data' => Skill::orderBy('name')->get()]);
    }

    #[OA\Post(
        path: '/admin/skills',
        summary: 'Tambah Master Skill',
        description: 'Membuat jenis keahlian/skill baru.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'SKILL-PHP'),
                    new OA\Property(property: 'name', type: 'string', example: 'PHP & Laravel Development'),
                    new OA\Property(property: 'description', type: 'string', example: 'Penguasaan framework Laravel')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Skill berhasil dibuat')
        ]
    )]
    public function storeSkill(Request $request)
    {
        $this->authorizeManagement($request->user());
        $skill = Skill::create($request->validate([
            'code' => 'required|string|max:50|unique:skills,code',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
        ]));
        return response()->json(['status' => 'success', 'data' => $skill], 201);
    }

    #[OA\Post(
        path: '/employees/{employee}/skills',
        summary: 'Deklarasi Skill Karyawan',
        description: 'Mendeklarasikan tingkat keahlian (level 1-5) oleh karyawan.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'employee', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['skill_id', 'declared_level'],
                properties: [
                    new OA\Property(property: 'skill_id', type: 'integer', example: 1),
                    new OA\Property(property: 'declared_level', type: 'integer', example: 4)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Deklarasi skill berhasil disimpan')
        ]
    )]
    public function declareSkill(Request $request, Employee $employee)
    {
        $admin = $request->user();
        abort_unless($this->canManageEmployeeSkill($admin, $employee), 403);
        $data = $request->validate(['skill_id' => 'required|exists:skills,id', 'declared_level' => 'required|integer|min:1|max:5']);

        $skill = EmployeeSkill::updateOrCreate(
            ['employee_id' => $employee->id, 'skill_id' => $data['skill_id']],
            ['declared_level' => $data['declared_level'], 'declared_at' => now()]
        );

        return response()->json(['status' => 'success', 'data' => $skill->load('skill')], 201);
    }

    #[OA\Post(
        path: '/employees/{employee}/skills/{skill}/verify',
        summary: 'Verifikasi Skill Karyawan',
        description: 'Supervisor / Manager melakukan verifikasi tingkat skill karyawan.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'employee', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'skill', in: 'path', description: 'ID Skill', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['verified_level'],
                properties: [
                    new OA\Property(property: 'verified_level', type: 'integer', example: 4),
                    new OA\Property(property: 'verification_notes', type: 'string', example: 'Lulus tes kompetensi backend')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Verifikasi skill berhasil')
        ]
    )]
    public function verifySkill(Request $request, Employee $employee, Skill $skill)
    {
        $admin = $request->user();
        abort_unless($this->canVerifySkill($admin, $employee), 403);
        $data = $request->validate(['verified_level' => 'required|integer|min:1|max:5', 'verification_notes' => 'nullable|string|max:2000']);

        $employeeSkill = EmployeeSkill::where('employee_id', $employee->id)->where('skill_id', $skill->id)->firstOrFail();
        $employeeSkill->update([
            'verified_level' => $data['verified_level'],
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'verification_notes' => $data['verification_notes'] ?? null,
        ]);

        return response()->json(['status' => 'success', 'data' => $employeeSkill->fresh('skill', 'verifier')]);
    }

    #[OA\Get(
        path: '/employees/{employee}/skill-gap',
        summary: 'Analisis Gap Skill Karyawan',
        description: 'Menganalisis kesenjangan antara skill terverifikasi karyawan dengan tuntutan Job Description posisi.',
        tags: ['Job & Skill Matrix'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'employee', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Analisis skill gap berhasil')
        ]
    )]
    public function skillGap(Request $request, Employee $employee)
    {
        abort_unless($this->canViewEmployeeSkill($request->user(), $employee), 403);
        $jobDescription = JobDescription::with('skillRequirements.skill')
            ->where('position_id', $employee->position_id)
            ->where('status', 'PUBLISHED')
            ->where(function ($query) {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', now());
            })
            ->latest('version')
            ->first();

        if (!$jobDescription) {
            return response()->json(['status' => 'success', 'data' => ['job_description' => null, 'gaps' => [], 'message' => 'No active published job description for this position.']]);
        }

        $verified = $employee->skills()->whereNotNull('verified_level')->get()->keyBy('skill_id');
        $gaps = $jobDescription->skillRequirements->map(function ($requirement) use ($verified) {
            $verifiedLevel = $verified->get($requirement->skill_id)?->verified_level;
            return [
                'skill_id' => $requirement->skill_id,
                'skill' => $requirement->skill->name,
                'required_level' => $requirement->required_level,
                'verified_level' => $verifiedLevel,
                'meets_requirement' => $verifiedLevel !== null && $verifiedLevel >= $requirement->required_level,
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => [
            'job_description' => ['id' => $jobDescription->id, 'version' => $jobDescription->version, 'title' => $jobDescription->title],
            'gaps' => $gaps,
            'missing_count' => $gaps->where('meets_requirement', false)->count(),
        ]]);
    }

    private function authorizeManagement(?Admin $admin): void
    {
        abort_unless($admin && in_array($admin->role, ['super_admin', 'hrd', 'management'], true), 403);
    }

    private function canManageEmployeeSkill(?Admin $admin, Employee $employee): bool
    {
        if (!$admin) return false;
        if (in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) return true;
        return in_array($admin->role, ['employee', 'intern'], true) && (int) $admin->employee_id === (int) $employee->id;
    }

    private function canVerifySkill(?Admin $admin, Employee $employee): bool
    {
        return $admin && in_array($admin->role, ['super_admin', 'hrd', 'management', 'supervisor'], true)
            && (int) $admin->employee_id !== (int) $employee->id;
    }

    private function canViewEmployeeSkill(?Admin $admin, Employee $employee): bool
    {
        if (!$admin) return false;
        if (in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) return true;
        if (in_array($admin->role, ['employee', 'intern'], true)) return (int) $admin->employee_id === (int) $employee->id;
        return $admin->role === 'supervisor' && (int) $employee->supervisor_id === (int) $admin->employee_id;
    }
}

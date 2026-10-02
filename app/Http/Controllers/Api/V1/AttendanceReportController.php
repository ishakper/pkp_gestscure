<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Policies\AttendancePolicy;
use App\Services\AttendanceProcessor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function __construct(
        private readonly AttendancePolicy $policy,
        private readonly AttendanceProcessor $processor,
    ) {}

    #[OA\Get(
        path: '/api/v1/attendance/reports/monthly',
        summary: 'Laporan Bulanan Presensi Karyawan',
        description: 'Mengambil ringkasan rekapitulasi presensi bulanan (kehadiran, keterlambatan, ketidakhadiran, rasio hadir) per karyawan.',
        security: [['sanctum' => []]],
        tags: ['Attendance'],
        parameters: [
            new OA\Parameter(name: 'month', in: 'query', description: 'Format bulan (YYYY-MM)', required: false, schema: new OA\Schema(type: 'string', example: '2026-03')),
            new OA\Parameter(name: 'building_id', in: 'query', description: 'Filter berdasarkan ID Gedung', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter berdasarkan ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Laporan bulanan presensi berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak (Unauthorized / Forbidden)')
        ]
    )]
    public function monthly(Request $request): JsonResponse
    {
        [$actor, $filters, $from, $to] = $this->validatedContext($request);
        $report = $this->buildReport($actor, $filters, $from, $to);

        return response()->json(['success' => true, 'data' => [
            'period' => ['month' => $from->format('Y-m'), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $report['totals'],
            'rows' => $report['rows'],
        ]]);
    }

    #[OA\Get(
        path: '/api/v1/attendance/reports/monthly/export',
        summary: 'Ekspor CSV Laporan Bulanan Presensi Karyawan',
        description: 'Mengunduh berkas CSV rekapitulasi presensi bulanan karyawan.',
        security: [['sanctum' => []]],
        tags: ['Attendance'],
        parameters: [
            new OA\Parameter(name: 'month', in: 'query', description: 'Format bulan (YYYY-MM)', required: false, schema: new OA\Schema(type: 'string', example: '2026-03')),
            new OA\Parameter(name: 'building_id', in: 'query', description: 'Filter berdasarkan ID Gedung', required: false, schema: new OA\Schema(type: 'integer', example: 1)),
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter berdasarkan ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Berkas CSV laporan berhasil dikirim (StreamDownload)'),
            new OA\Response(response: 403, description: 'Akses ditolak (Unauthorized / Forbidden)')
        ]
    )]
    public function export(Request $request): StreamedResponse
    {
        [$actor, $filters, $from, $to] = $this->validatedContext($request);
        $rows = $this->buildReport($actor, $filters, $from, $to)['rows'];

        return response()->streamDownload(function () use ($rows): void {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Employee ID', 'Employee', 'Building', 'Present', 'Late', 'Absent', 'Attendance Rate', 'Late Minutes']);
            foreach ($rows as $row) {
                fputcsv($stream, [$this->csvCell($row['employee_code']), $this->csvCell($row['employee_name']), $this->csvCell($row['building']), $row['present'], $row['late'], $row['absent'], $row['attendance_rate'].'%', $row['late_minutes']]);
            }
            fclose($stream);
        }, "attendance-report-{$from->format('Y-m')}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function validatedContext(Request $request): array
    {
        $actor = $request->user();
        abort_unless($actor && ($this->policy->viewAny($actor) || $this->policy->self($actor)), 403);
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);
        $from = Carbon::createFromFormat('Y-m', $filters['month'] ?? now()->format('Y-m'))->startOfMonth();
        return [$actor, $filters, $from, $from->copy()->endOfMonth()];
    }

    private function reportQuery($actor, array $filters, Carbon $from, Carbon $to): Builder
    {
        $query = Attendance::query()
            ->with(['employee:id,employee_id,name,email,building_id', 'employee.building:id,name'])
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('employee_id')->orderBy('attendance_date');

        if ($this->policy->self($actor) && !$this->policy->viewAny($actor)) {
            $query->where('employee_id', Employee::where('email', $actor->email)->value('id') ?? 0);
        } elseif (!empty($actor->assigned_building)) {
            $query->whereHas('employee.building', fn (Builder $building) => $building->where('name', $actor->assigned_building));
        }
        if (!empty($filters['building_id'])) {
            $query->whereHas('employee', fn (Builder $employee) => $employee->where('building_id', $filters['building_id']));
        }
        if (!empty($filters['employee_id'])) $query->where('employee_id', $filters['employee_id']);
        return $query;
    }

    /**
     * Active employees the report is about, under the same actor/building/employee
     * scope as reportQuery(). This is the population; it does not depend on whether
     * anyone has attendance rows in the period.
     */
    private function populationQuery($actor, array $filters): Builder
    {
        $query = Employee::query()
            ->with('building:id,name')
            ->where(fn (Builder $q) => $q->where('employment_status', 'ACTIVE')->orWhereNull('employment_status'));

        if ($this->policy->self($actor) && !$this->policy->viewAny($actor)) {
            $query->where('email', $actor->email);
        } elseif (!empty($actor->assigned_building)) {
            $query->whereHas('building', fn (Builder $building) => $building->where('name', $actor->assigned_building));
        }
        if (!empty($filters['building_id'])) $query->where('building_id', $filters['building_id']);
        if (!empty($filters['employee_id'])) $query->where('id', $filters['employee_id']);
        return $query;
    }

    private function buildReport($actor, array $filters, Carbon $from, Carbon $to): array
    {
        $records = $this->reportQuery($actor, $filters, $from, $to)->get();
        $population = $this->populationQuery($actor, $filters)->get(['id', 'employee_id', 'name', 'building_id', 'hire_date']);

        $recordedDates = [];
        foreach ($records as $record) {
            $recordedDates[$record->employee_id][Carbon::parse($record->attendance_date)->toDateString()] = true;
        }
        // A day only becomes a missed working day once it is over (Asia/Jakarta), only
        // once the system was recording attendance at all (days before the first row
        // anywhere predate go-live), and only from each employee's own defensible start
        // date (hire date, effective contract, calendar assignment or first attendance).
        $trackingStart = Attendance::query()->min('attendance_date');
        $eligibility = $this->processor->absenceEligibilityStarts($population);
        $derivedAbsent = [];
        if ($trackingStart) {
            $absenceFrom = $from->copy()->max(Carbon::parse($trackingStart)->startOfDay());
            $absenceUntil = $to->copy()->min(now()->subDay()->endOfDay());
            $derivedAbsent = $this->processor->derivedAbsences($population, $absenceFrom, $absenceUntil, $recordedDates, $eligibility);
        }

        $rows = $this->rows($records, $population, $derivedAbsent, $eligibility);
        $present = array_sum(array_column($rows, 'present'));
        $late = array_sum(array_column($rows, 'late'));
        $absent = array_sum(array_column($rows, 'absent'));

        return ['rows' => $rows, 'totals' => [
            'employees' => $population->count(),
            'employees_with_attendance' => $records->pluck('employee_id')->unique()->count(),
            'attendance_rows' => $records->count(),
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'absent_derived' => array_sum($derivedAbsent),
            'tracking_started_on' => $trackingStart ? Carbon::parse($trackingStart)->toDateString() : null,
            // Employees whose absences can be evaluated vs. those with no known start date.
            'employees_absence_evaluated' => count($eligibility),
            'employees_without_start_date' => $population->count() - count($eligibility),
            'attendance_rate' => $this->attendanceRate($present, $late, $absent),
        ]];
    }

    /**
     * One row per employee that has something to report: attendance rows and/or
     * derived absences. PRESENT/FIELD/WFH count as present; LATE as late; ABSENT rows
     * plus working days with no row as absent. OFF/LEAVE/SICK/PERMISSION are excluded
     * from the rate denominator.
     */
    private function rows(Collection $records, Collection $population, array $derivedAbsent, array $eligibility): array
    {
        $byEmployee = $records->groupBy('employee_id');
        $employees = $population->keyBy('id');
        $ids = collect($byEmployee->keys())->merge(array_keys($derivedAbsent))->unique();

        return $ids->map(function ($id) use ($byEmployee, $employees, $derivedAbsent, $eligibility): array {
            $items = $byEmployee->get($id, collect());
            $employee = $employees->get($id) ?? $items->first()?->employee;
            $present = $items->whereIn('status', ['PRESENT', 'FIELD', 'WFH'])->count();
            $late = $items->where('status', 'LATE')->count();
            $absent = $items->where('status', 'ABSENT')->count() + ($derivedAbsent[$id] ?? 0);
            return [
                'employee_id' => $employee?->id ?? $id,
                'employee_code' => $employee?->employee_id ?? '-',
                'employee_name' => $employee?->name ?? 'Unknown employee',
                'building' => $employee?->building?->name ?? 'Unassigned',
                'present' => $present, 'late' => $late, 'absent' => $absent,
                'attendance_rate' => $this->attendanceRate($present, $late, $absent),
                'late_minutes' => (int) $items->sum('late_minutes'),
                'absence_counted_from' => $eligibility[$id]['date'] ?? null,
                'absence_start_source' => $eligibility[$id]['source'] ?? null,
            ];
        })->sortBy(fn (array $row) => [$row['building'], $row['employee_name']])->values()->all();
    }

    /** (present + late) / (present + late + absent), as a percentage with one decimal. */
    private function attendanceRate(int $present, int $late, int $absent): float
    {
        $scheduled = $present + $late + $absent;
        return $scheduled ? round((($present + $late) / $scheduled) * 100, 1) : 0.0;
    }

    private function csvCell(?string $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}

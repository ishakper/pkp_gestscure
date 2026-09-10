<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use App\Policies\AttendancePolicy;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function __construct(private readonly AttendancePolicy $policy) {}

    public function monthly(Request $request): JsonResponse
    {
        [$actor, $filters, $from, $to] = $this->validatedContext($request);
        $records = $this->reportQuery($actor, $filters, $from, $to)->get();

        return response()->json(['success' => true, 'data' => [
            'period' => ['month' => $from->format('Y-m'), 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => $this->totals($records),
            'rows' => $this->rows($records),
        ]]);
    }

    public function export(Request $request): StreamedResponse
    {
        [$actor, $filters, $from, $to] = $this->validatedContext($request);
        $rows = $this->rows($this->reportQuery($actor, $filters, $from, $to)->get());

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

    private function rows(Collection $records): array
    {
        return $records->groupBy('employee_id')->map(function (Collection $items): array {
            $employee = $items->first()->employee;
            $present = $items->whereIn('status', ['PRESENT', 'FIELD', 'WFH'])->count();
            $late = $items->where('status', 'LATE')->count();
            $absent = $items->where('status', 'ABSENT')->count();
            $scheduled = $present + $late + $absent;
            return [
                'employee_id' => $employee?->id,
                'employee_code' => $employee?->employee_id ?? '-',
                'employee_name' => $employee?->name ?? 'Unknown employee',
                'building' => $employee?->building?->name ?? 'Unassigned',
                'present' => $present, 'late' => $late, 'absent' => $absent,
                'attendance_rate' => $scheduled ? round((($present + $late) / $scheduled) * 100, 1) : 0.0,
                'late_minutes' => (int) $items->sum('late_minutes'),
            ];
        })->sortBy(fn (array $row) => [$row['building'], $row['employee_name']])->values()->all();
    }

    private function totals(Collection $records): array
    {
        $present = $records->whereIn('status', ['PRESENT', 'FIELD', 'WFH'])->count();
        $late = $records->where('status', 'LATE')->count();
        $absent = $records->where('status', 'ABSENT')->count();
        $scheduled = $present + $late + $absent;
        return ['employees' => $records->pluck('employee_id')->unique()->count(), 'present' => $present, 'late' => $late, 'absent' => $absent, 'attendance_rate' => $scheduled ? round((($present + $late) / $scheduled) * 100, 1) : 0.0];
    }

    private function csvCell(?string $value): string
    {
        $value = (string) $value;
        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}

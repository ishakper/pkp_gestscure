<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\TaskWorklog;
use App\Models\WorkTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $query = WorkTask::with(['employee:id,employee_id,name,supervisor_id', 'assigner:id,name'])->withSum('worklogs', 'duration_minutes');
        $this->scopeTasks($query, $admin);

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($task) => $task->where('title', 'like', "%{$search}%")->orWhere('task_code', 'like', "%{$search}%")->orWhere('project_name', 'like', "%{$search}%"));
        }
        foreach (['status', 'priority', 'employee_id', 'project_name'] as $filter) {
            if ($request->filled($filter)) $query->where($filter, $request->input($filter));
        }

        $tasks = $query->latest()->paginate(min(max((int) $request->input('per_page', 15), 1), 100));
        return response()->json(['status' => 'success', 'pagination' => [
            'current_page' => $tasks->currentPage(), 'per_page' => $tasks->perPage(), 'total_records' => $tasks->total(), 'total_pages' => $tasks->lastPage(),
        ], 'data' => $tasks]);
    }

    public function store(Request $request)
    {
        $admin = $request->user();
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'employee_id' => 'required|exists:employees,id',
            'field_assignment_id' => 'nullable|exists:field_assignments,id',
            'project_name' => 'nullable|string|max:255',
            'priority' => 'sometimes|in:' . implode(',', WorkTask::PRIORITIES),
            'status' => 'sometimes|in:' . implode(',', WorkTask::STATUSES),
            'progress' => 'sometimes|integer|min:0|max:100',
            'due_date' => 'nullable|date',
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless($this->canAssign($admin, $employee), 403);

        $task = DB::transaction(function () use ($data, $admin) {
            $data['task_code'] = 'TASK-' . now()->format('YmdHis') . '-' . strtoupper(substr((string) str()->uuid(), 0, 6));
            $data['assigned_by'] = $admin->id;
            return WorkTask::create($data);
        });
        $this->audit($admin, 'task_created', $task, "Created task {$task->task_code}");
        return response()->json(['status' => 'success', 'data' => $task->load('employee')], 201);
    }

    public function show(Request $request, WorkTask $task)
    {
        abort_unless($this->canView($request->user(), $task), 403);
        return response()->json(['status' => 'success', 'data' => $task->load(['employee', 'assigner', 'worklogs.employee'])]);
    }

    public function update(Request $request, WorkTask $task)
    {
        abort_unless($this->canManage($request->user(), $task), 403);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255', 'description' => 'nullable|string',
            'priority' => 'sometimes|in:' . implode(',', WorkTask::PRIORITIES),
            'status' => 'sometimes|in:' . implode(',', WorkTask::STATUSES),
            'progress' => 'sometimes|integer|min:0|max:100', 'due_date' => 'nullable|date',
        ]);
        if (($data['status'] ?? null) === 'DONE' || ($data['progress'] ?? null) === 100) {
            $data['status'] = 'DONE';
            $data['progress'] = 100;
            $data['completed_at'] = now();
        }
        $task->update($data);
        $this->audit($request->user(), 'task_updated', $task, "Updated task {$task->task_code}");
        return response()->json(['status' => 'success', 'data' => $task->fresh('employee')]);
    }

    public function worklogs(Request $request, WorkTask $task)
    {
        abort_unless($this->canView($request->user(), $task), 403);
        return response()->json(['status' => 'success', 'data' => $task->worklogs()->with('employee')->latest('work_date')->latest()->paginate(20)]);
    }

    public function storeWorklog(Request $request, WorkTask $task)
    {
        $admin = $request->user();
        abort_unless($this->canView($admin, $task), 403);
        $data = $request->validate(['work_date' => 'required|date', 'duration_minutes' => 'required|integer|min:1|max:1440', 'notes' => 'nullable|string|max:5000']);
        $employeeId = (int) $admin->employee_id === (int) $task->employee_id ? $task->employee_id : $task->employee_id;
        $worklog = $task->worklogs()->create($data + ['employee_id' => $employeeId]);
        $this->audit($admin, 'task_worklog_created', $task, "Added worklog to {$task->task_code}");
        return response()->json(['status' => 'success', 'data' => $worklog->load('employee')], 201);
    }

    public function metrics(Request $request)
    {
        $query = WorkTask::query();
        $this->scopeTasks($query, $request->user());
        return response()->json(['status' => 'success', 'data' => [
            'total' => (clone $query)->count(),
            'todo' => (clone $query)->where('status', 'TODO')->count(),
            'in_progress' => (clone $query)->where('status', 'IN_PROGRESS')->count(),
            'blocked' => (clone $query)->where('status', 'BLOCKED')->count(),
            'done' => (clone $query)->where('status', 'DONE')->count(),
            'overdue' => (clone $query)->whereNotNull('due_date')->whereDate('due_date', '<', now())->whereNotIn('status', ['DONE', 'CANCELLED'])->count(),
        ]]);
    }

    private function scopeTasks($query, ?Admin $admin): void
    {
        if (!$admin || in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) return;
        if (in_array($admin->role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $admin->employee_id);
            return;
        }
        if ($admin->role === 'supervisor') {
            $query->whereHas('employee', fn ($employee) => $employee->where('supervisor_id', $admin->employee_id)->orWhere('employees.id', $admin->employee_id));
            return;
        }
        $query->whereRaw('1 = 0');
    }

    private function canView(?Admin $admin, WorkTask $task): bool
    {
        if (!$admin) return false;
        if (in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) return true;
        if (in_array($admin->role, ['employee', 'intern'], true)) return (int) $task->employee_id === (int) $admin->employee_id;
        return $admin->role === 'supervisor' && ((int) $task->employee_id === (int) $admin->employee_id || (int) $task->employee?->supervisor_id === (int) $admin->employee_id);
    }

    private function canManage(?Admin $admin, WorkTask $task): bool
    {
        if (!$this->canView($admin, $task)) return false;
        return in_array($admin->role, ['super_admin', 'hrd', 'management', 'supervisor'], true)
            || (int) $task->employee_id === (int) $admin->employee_id;
    }

    private function canAssign(?Admin $admin, Employee $employee): bool
    {
        if (!$admin) return false;
        if (in_array($admin->role, ['super_admin', 'hrd', 'management'], true)) return true;
        if ($admin->role === 'supervisor') return (int) $employee->supervisor_id === (int) $admin->employee_id;
        return in_array($admin->role, ['employee', 'intern'], true) && (int) $employee->id === (int) $admin->employee_id;
    }

    private function audit(Admin $admin, string $action, WorkTask $task, string $description): void
    {
        ActivityLog::create(['admin_id' => $admin->id, 'action' => $action, 'subject_type' => 'WorkTask', 'subject_id' => $task->id, 'description' => $description, 'timestamp' => now()]);
    }
}

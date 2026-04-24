<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StaffTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Response as InertiaResponse;

class StaffTaskController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
    }

    private function requireSameTenant($r, $u): void { abort_if($r->tenant_id !== $u->tenant_id, 403); }

    /**
     * GET /tasks?view=mine|my_department|all
     */
    public function index(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate();
        $u = Auth::user();
        $view = $request->query('view', 'mine');

        $query = StaffTask::forTenant($u->tenant_id)
            ->with(['participant:id,mrn,first_name,last_name', 'assignedUser:id,first_name,last_name,department', 'createdBy:id,first_name,last_name']);
        match ($view) {
            'my_department' => $query->where('assigned_to_department', $u->department),
            'all'           => null,
            default         => $query->where('assigned_to_user_id', $u->id),
        };

        $tasks = $query->orderBy('due_at')->limit(200)->get();
        $overdueCount = StaffTask::forTenant($u->tenant_id)->overdue()
            ->when($view === 'mine', fn ($q) => $q->where('assigned_to_user_id', $u->id))
            ->count();

        if ($request->wantsJson()) {
            return response()->json([
                'tasks'         => $tasks,
                'overdue_count' => $overdueCount,
            ]);
        }
        return \Inertia\Inertia::render('Tasks/Index', [
            'tasks'         => $tasks,
            'overdue_count' => $overdueCount,
            'view'          => $view,
            'current_user_department' => $u->department,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'participant_id'         => 'nullable|integer|exists:emr_participants,id',
            'assigned_to_user_id'    => 'nullable|integer|exists:shared_users,id',
            'assigned_to_department' => 'nullable|string|max:40',
            'title'                  => 'required|string|max:200',
            'description'            => 'nullable|string|max:4000',
            'priority'               => 'nullable|in:' . implode(',', StaffTask::PRIORITIES),
            'due_at'                 => 'nullable|date',
            'related_to_type'        => 'nullable|string|max:60',
            'related_to_id'          => 'nullable|integer',
        ]);

        if (empty($validated['assigned_to_user_id']) && empty($validated['assigned_to_department'])) {
            return response()->json([
                'error' => 'assignee_required',
                'message' => 'Must assign task to a user or a department.',
            ], 422);
        }

        $task = StaffTask::create(array_merge($validated, [
            'tenant_id'          => $u->tenant_id,
            'created_by_user_id' => $u->id,
            'status'             => 'pending',
            'priority'           => $validated['priority'] ?? 'normal',
        ]));

        AuditLog::record(
            action: 'task.created',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'staff_task',
            resourceId: $task->id,
            description: "Task created: {$task->title}",
        );

        return response()->json(['task' => $task], 201);
    }

    public function complete(Request $request, StaffTask $task): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($task, $u);

        if (! in_array($task->status, StaffTask::OPEN_STATUSES, true)) {
            return response()->json(['error' => 'invalid_state'], 409);
        }

        $validated = $request->validate([
            'completion_note' => 'nullable|string|max:4000',
        ]);

        $task->update([
            'status'          => 'completed',
            'completed_at'    => now(),
            'completion_note' => $validated['completion_note'] ?? null,
        ]);

        AuditLog::record(
            action: 'task.completed',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'staff_task',
            resourceId: $task->id,
            description: "Task completed.",
        );

        return response()->json(['task' => $task->fresh()]);
    }

    public function cancel(Request $request, StaffTask $task): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($task, $u);

        if (! in_array($task->status, StaffTask::OPEN_STATUSES, true)) {
            return response()->json(['error' => 'invalid_state'], 409);
        }

        $task->update(['status' => 'cancelled']);
        AuditLog::record(
            action: 'task.cancelled',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'staff_task',
            resourceId: $task->id,
            description: "Task cancelled.",
        );
        return response()->json(['task' => $task->fresh()]);
    }
}

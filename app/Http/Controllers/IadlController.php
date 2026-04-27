<?php

// ─── IadlController ──────────────────────────────────────────────────────────
// Phase C1. Lawton IADL assessment CRUD.
//
// Routes:
//   GET  /participants/{participant}/iadl          index()  : history + trend
//   POST /participants/{participant}/iadl          store()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\IadlRecord;
use App\Models\Participant;
use App\Models\StaffTask;
use App\Services\IadlScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class IadlController extends Controller
{
    public function __construct(private IadlScoringService $scorer) {}

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['primary_care', 'home_care', 'social_work', 'therapies', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($resource, $user): void
    {
        abort_if($resource->tenant_id !== $user->tenant_id, 403);
    }

    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $records = IadlRecord::forTenant($u->tenant_id)
            ->forParticipant($participant->id)
            ->with('recordedBy:id,first_name,last_name')
            ->orderByDesc('recorded_at')
            ->limit(50)
            ->get();

        $trend = $records->map(fn ($r) => [
            'recorded_at'    => $r->recorded_at->toDateString(),
            'total_score'    => $r->total_score,
            'interpretation' => $r->interpretation,
        ])->values();

        return response()->json([
            'records'  => $records,
            'trend'    => $trend,
            'baseline' => $records->last(),
            'current'  => $records->first(),
        ]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $rules = [
            'recorded_at' => 'nullable|date',
            'notes'       => 'nullable|string|max:4000',
        ];
        foreach (IadlRecord::ITEMS as $item) {
            $rules[$item] = 'required|integer|in:0,1';
        }

        $validated = $request->validate($rules);

        $scored = $this->scorer->score($validated);

        $record = IadlRecord::create(array_merge(
            array_intersect_key($validated, array_flip(IadlRecord::ITEMS)),
            [
                'tenant_id'           => $u->tenant_id,
                'participant_id'      => $participant->id,
                'recorded_by_user_id' => $u->id,
                'recorded_at'         => isset($validated['recorded_at'])
                    ? Carbon::parse($validated['recorded_at'])
                    : now(),
                'total_score'         => $scored['total'],
                'interpretation'      => $scored['interpretation'],
                'notes'               => $validated['notes'] ?? null,
            ]
        ));

        AuditLog::record(
            action: 'iadl.recorded',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'iadl_record',
            resourceId: $record->id,
            description: "IADL recorded for participant #{$participant->id}: {$scored['total']}/8 ({$scored['interpretation']}).",
        );

        // Phase R3 : auto-create one StaffTask per impaired-item referral
        // suggestion so the right department picks it up via the tasks queue.
        $tasksCreated = [];
        foreach ($record->referralSuggestions() as $sugg) {
            $tasksCreated[] = StaffTask::create([
                'tenant_id'              => $u->tenant_id,
                'participant_id'         => $participant->id,
                'assigned_to_department' => $sugg['dept'],
                'created_by_user_id'     => $u->id,
                'title'                  => "IADL referral: {$sugg['item']}",
                'description'            => $sugg['goal'] . " (Auto-created from IADL record #{$record->id}; total {$scored['total']}/8 : {$scored['interpretation']}.)",
                'priority'               => $scored['interpretation'] === 'severe_impairment' ? 'high' : 'normal',
                'status'                 => 'pending',
                'related_to_type'        => IadlRecord::class,
                'related_to_id'          => $record->id,
            ]);
        }

        return response()->json([
            'record'      => $record,
            'suggestions' => $record->referralSuggestions(),
            'tasks'       => $tasksCreated,
        ], 201);
    }
}

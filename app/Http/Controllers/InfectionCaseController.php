<?php

// ─── InfectionCaseController ─────────────────────────────────────────────────
// Phase B2 — individual infection case CRUD + outbreak declaration.
//
// Routes:
//   GET    /participants/{participant}/infections         index()
//   POST   /participants/{participant}/infections         store() (triggers OutbreakDetectionService::evaluateCase)
//   POST   /infections/{case}/resolve                     resolve()
//   POST   /infection-outbreaks/{outbreak}/update         updateOutbreak() (contain/end + containment text + state-report timestamp)
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InfectionCase;
use App\Models\InfectionOutbreak;
use App\Models\Participant;
use App\Services\OutbreakDetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InfectionCaseController extends Controller
{
    public function __construct(private OutbreakDetectionService $detector) {}

    private function gate(array $extra = []): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        // Clinical + QA + IT can report/review cases. (Nursing sits under
        // primary_care/home_care per backlog_department_model_gap.md.)
        $allow = array_merge(['primary_care', 'home_care', 'qa_compliance', 'it_admin'], $extra);
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant($resource, $user): void
    {
        abort_if($resource->tenant_id !== $user->tenant_id, 403);
    }

    // GET /participants/{p}/infections
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $cases = $participant->hasMany(InfectionCase::class, 'participant_id')
            ->with(['outbreak:id,organism_type,status,started_at,site_id', 'reportedBy:id,first_name,last_name'])
            ->orderByDesc('onset_date')
            ->limit(100)
            ->get();

        return response()->json(['cases' => $cases]);
    }

    // POST /participants/{p}/infections
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'organism_type'            => 'required|string|max:40',
            'organism_detail'          => 'nullable|string|max:200',
            'onset_date'               => 'required|date',
            'severity'                 => 'nullable|in:' . implode(',', InfectionCase::SEVERITIES),
            'source'                   => 'nullable|in:' . implode(',', InfectionCase::SOURCES),
            'hospitalization_required' => 'boolean',
            'isolation_started_at'     => 'nullable|date',
            'isolation_ended_at'       => 'nullable|date|after_or_equal:isolation_started_at',
            'notes'                    => 'nullable|string|max:4000',
        ]);

        $case = InfectionCase::create(array_merge($validated, [
            'tenant_id'           => $u->tenant_id,
            'participant_id'      => $participant->id,
            'site_id'             => $participant->site_id,
            'severity'            => $validated['severity'] ?? 'mild',
            'source'              => $validated['source']   ?? 'unknown',
            'reported_by_user_id' => $u->id,
        ]));

        AuditLog::record(
            action: 'infection.case_reported',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'infection_case',
            resourceId: $case->id,
            description: "Infection case reported: {$case->organism_type} for participant #{$participant->id}",
        );

        // Evaluate the cluster immediately (don't wait for the daily job).
        $outbreak = $this->detector->evaluateCase($case);

        return response()->json([
            'case'               => $case->fresh(['outbreak']),
            'outbreak_declared'  => $outbreak?->only(['id', 'organism_type', 'site_id', 'started_at', 'status']) ?? null,
        ], 201);
    }

    // POST /infections/{case}/resolve
    public function resolve(Request $request, InfectionCase $case): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($case, $u);

        $validated = $request->validate([
            'resolution_date'     => 'required|date|after_or_equal:' . $case->onset_date->toDateString(),
            'isolation_ended_at'  => 'nullable|date',
            'notes'               => 'nullable|string|max:4000',
        ]);

        $case->update($validated);

        AuditLog::record(
            action: 'infection.case_resolved',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'infection_case',
            resourceId: $case->id,
            description: "Infection case resolved on {$case->resolution_date->toDateString()}",
        );

        return response()->json(['case' => $case->fresh()]);
    }

    // POST /infection-outbreaks/{outbreak}/update
    public function updateOutbreak(Request $request, InfectionOutbreak $outbreak): JsonResponse
    {
        $this->gate(['qa_compliance']);
        $u = Auth::user();
        $this->requireSameTenant($outbreak, $u);

        $validated = $request->validate([
            'status'                    => 'sometimes|in:' . implode(',', InfectionOutbreak::STATUSES),
            'attack_rate_pct'           => 'nullable|numeric|min:0|max:100',
            'containment_measures_text' => 'nullable|string|max:8000',
            'reported_to_state_at'      => 'nullable|date',
            'declared_ended_at'         => 'nullable|date',
            'notes'                     => 'nullable|string|max:4000',
        ]);

        // Automatically stamp declared_ended_at when status transitions to ended/contained
        if (isset($validated['status']) && in_array($validated['status'], ['ended', 'contained'], true)
            && empty($outbreak->declared_ended_at)) {
            $validated['declared_ended_at'] = $validated['declared_ended_at'] ?? now();
        }

        $outbreak->update(array_merge($validated, [
            'declared_by_user_id' => $outbreak->declared_by_user_id ?? $u->id,
        ]));

        AuditLog::record(
            action: 'infection.outbreak_updated',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'infection_outbreak',
            resourceId: $outbreak->id,
            description: "Outbreak #{$outbreak->id} updated: status={$outbreak->status}",
        );

        return response()->json(['outbreak' => $outbreak->fresh(['cases'])]);
    }
}

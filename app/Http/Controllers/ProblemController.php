<?php

// ─── ProblemController ────────────────────────────────────────────────────────
// Manages the participant problem list (active diagnoses / chronic conditions).
// Also exposes a global ICD-10 code search endpoint for typeahead.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreProblemRequest;
use App\Models\AuditLog;
use App\Models\Icd10Lookup;
use App\Models\Participant;
use App\Models\Problem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProblemController extends Controller
{
    private function authorizeForTenant(Participant $participant, $user): void
    {
        abort_if($participant->tenant_id !== $user->effectiveTenantId(), 403);
    }

    private function authorizeProblemForParticipant(Problem $problem, Participant $participant): void
    {
        abort_if($problem->participant_id !== $participant->id, 404);
    }

    /**
     * GET /participants/{participant}/problems
     * Returns all problems grouped by status (active/chronic/resolved/ruled_out).
     */
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $problems = $participant->problems()
            ->with('addedBy:id,first_name,last_name')
            ->orderBy('is_primary_diagnosis', 'desc')
            ->orderBy('onset_date', 'desc')
            ->get()
            ->groupBy('status');

        return response()->json($problems);
    }

    /**
     * POST /participants/{participant}/problems
     * Adds a diagnosis to the problem list.
     */
    public function store(StoreProblemRequest $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);

        $problem = Problem::create(array_merge($request->validated(), [
            'participant_id'   => $participant->id,
            'tenant_id'        => $user->effectiveTenantId(),
            'added_by_user_id' => $user->id,
        ]));

        AuditLog::record(
            action: 'participant.problem.added',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Problem {$problem->icd10_code} added to {$participant->mrn}",
            newValues: ['icd10_code' => $problem->icd10_code, 'icd10_description' => $problem->icd10_description],
        );

        return response()->json($problem->load('addedBy:id,first_name,last_name'), 201);
    }

    /**
     * PUT /participants/{participant}/problems/{problem}
     * Updates a problem entry (status, notes, resolved_date, etc.).
     */
    public function update(StoreProblemRequest $request, Participant $participant, Problem $problem): JsonResponse
    {
        $user = $request->user();
        $this->authorizeForTenant($participant, $user);
        $this->authorizeProblemForParticipant($problem, $participant);

        $old = $problem->only(array_keys($request->validated()));
        $problem->update(array_merge($request->validated(), [
            'last_reviewed_by_user_id' => $user->id,
            'last_reviewed_at'         => now(),
        ]));

        AuditLog::record(
            action: 'participant.problem.updated',
            tenantId: $user->tenant_id,
            userId: $user->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Problem {$problem->icd10_code} updated for {$participant->mrn}",
            oldValues: $old,
            newValues: $request->validated(),
        );

        return response()->json($problem->fresh('addedBy:id,first_name,last_name'));
    }

    /**
     * GET /icd10/search?q=...
     * Searches the ICD-10 lookup table by code or description.
     * Returns up to 20 results, with code-prefix matches ranked first.
     */
    public function icd10Search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:50']]);

        $term = trim($request->input('q'));
        $like = '%' . $term . '%';

        // Single query: matches code OR description (case-insensitive ILIKE)
        // Ordered so that codes starting with the search term appear first
        $results = Icd10Lookup::where(function ($q) use ($like) {
                $q->where('code', 'ilike', $like)
                  ->orWhere('description', 'ilike', $like);
            })
            ->orderByRaw('CASE WHEN code ilike ? THEN 0 ELSE 1 END', [$term . '%'])
            ->orderBy('code')
            ->limit(20)
            ->get(['code', 'description', 'category']);

        return response()->json($results);
    }
}

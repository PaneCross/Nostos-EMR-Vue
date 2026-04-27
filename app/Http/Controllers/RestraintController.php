<?php

// ─── RestraintController ─────────────────────────────────────────────────────
// Phase B1 : physical + chemical restraint episode management.
//
// Routes (all nested under /participants/{participant}):
//   GET    /restraints                                    index()
//   POST   /restraints                                    store()          : initiate episode
//   POST   /restraints/{episode}/observations             storeObservation() : record a monitoring check
//   POST   /restraints/{episode}/discontinue              discontinue()    : end the episode
//   POST   /restraints/{episode}/idt-review               recordIdtReview(): IDT review + outcome
//
// Tenant isolation: abort_if($participant->tenant_id !== $user->tenant_id, 403).
// Cross-tenant access to an episode returns 404 per FHIR convention.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\RestraintEpisode;
use App\Models\RestraintMonitoringObservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RestraintController extends Controller
{
    /** Gate: primary_care (includes RNs) / home_care / qa_compliance / it_admin / super_admin. */
    private function gate(array $extra = []): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        // NOTE: There's no `nursing` dept in shared_users_department_check;
        // nurses are under `primary_care` or `home_care`. See
        // backlog_department_model_gap.md (flagged in Phase B1).
        $allow = array_merge(['primary_care', 'home_care', 'qa_compliance', 'it_admin'], $extra);
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    private function requireSameTenant(Participant $p, $user): void
    {
        abort_if($p->tenant_id !== $user->tenant_id, 403);
    }

    // ── GET /participants/{p}/restraints ─────────────────────────────────────
    public function index(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $episodes = $participant->restraintEpisodes()
            ->with([
                'initiatedBy:id,first_name,last_name,department',
                'orderedBy:id,first_name,last_name,department',
                'discontinuedBy:id,first_name,last_name',
                'idtReviewer:id,first_name,last_name',
                'observations' => fn ($q) => $q->orderByDesc('observed_at')->limit(20),
                'observations.observedBy:id,first_name,last_name',
            ])
            ->orderByDesc('initiated_at')
            ->limit(50)
            ->get();

        return response()->json([
            'episodes' => $episodes->map(fn (RestraintEpisode $e) => array_merge($e->toArray(), [
                'is_active'                      => $e->isActive(),
                'is_chemical'                    => $e->isChemical(),
                'minutes_since_last_observation' => $e->isActive() ? $e->minutesSinceLastObservation() : null,
                'monitoring_overdue'             => $e->monitoringOverdue(),
                'idt_review_overdue'             => $e->idtReviewOverdue(),
            ])),
        ]);
    }

    // ── POST /participants/{p}/restraints ────────────────────────────────────
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'restraint_type'           => 'required|in:' . implode(',', RestraintEpisode::TYPES),
            'reason_text'              => 'required|string|min:15|max:4000',
            'alternatives_tried_text'  => 'nullable|string|max:4000',
            'ordering_provider_user_id'=> 'nullable|integer|exists:shared_users,id',
            'medication_text'          => 'nullable|string|max:500',
            'monitoring_interval_min'  => 'nullable|integer|min:5|max:240',
        ]);

        // Chemical requires a provider order.
        if (in_array($validated['restraint_type'], ['chemical', 'both'], true)
            && empty($validated['ordering_provider_user_id'])) {
            return response()->json([
                'error' => 'ordering_provider_required',
                'message' => 'Chemical or combined restraint requires an ordering provider.',
            ], 422);
        }

        $defaultInterval = $validated['restraint_type'] === 'physical'
            ? RestraintEpisode::DEFAULT_MONITORING_INTERVAL_MIN_PHYSICAL
            : RestraintEpisode::DEFAULT_MONITORING_INTERVAL_MIN_CHEMICAL;

        $episode = RestraintEpisode::create([
            'tenant_id'                => $u->tenant_id,
            'participant_id'           => $participant->id,
            'restraint_type'           => $validated['restraint_type'],
            'initiated_at'             => now(),
            'initiated_by_user_id'     => $u->id,
            'reason_text'              => $validated['reason_text'],
            'alternatives_tried_text'  => $validated['alternatives_tried_text'] ?? null,
            'ordering_provider_user_id'=> $validated['ordering_provider_user_id'] ?? null,
            'medication_text'          => $validated['medication_text'] ?? null,
            'monitoring_interval_min'  => $validated['monitoring_interval_min'] ?? $defaultInterval,
            'status'                   => 'active',
        ]);

        AuditLog::record(
            action: 'restraint.episode_initiated',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'restraint_episode',
            resourceId: $episode->id,
            description: "Restraint initiated: type={$episode->restraint_type} participant=#{$participant->id}",
        );

        return response()->json(['episode' => $episode->fresh()], 201);
    }

    // ── POST /participants/{p}/restraints/{episode}/observations ─────────────
    public function storeObservation(Request $request, Participant $participant, RestraintEpisode $episode): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        abort_if($episode->participant_id !== $participant->id, 404);
        abort_if($episode->status !== 'active', 409, 'Observations can only be recorded on active episodes.');

        $validated = $request->validate([
            'skin_integrity'    => 'nullable|in:' . implode(',', RestraintMonitoringObservation::SKIN_VALUES),
            'circulation'       => 'nullable|in:' . implode(',', RestraintMonitoringObservation::CIRCULATION_VALUES),
            'mental_status'     => 'nullable|in:' . implode(',', RestraintMonitoringObservation::MENTAL_VALUES),
            'toileting_offered' => 'boolean',
            'hydration_offered' => 'boolean',
            'repositioning_done'=> 'boolean',
            'notes'             => 'nullable|string|max:4000',
        ]);

        $observation = RestraintMonitoringObservation::create(array_merge($validated, [
            'tenant_id'            => $u->tenant_id,
            'restraint_episode_id' => $episode->id,
            'observed_by_user_id'  => $u->id,
            'observed_at'          => now(),
        ]));

        AuditLog::record(
            action: 'restraint.observation_recorded',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'restraint_episode',
            resourceId: $episode->id,
            description: "Observation recorded on episode #{$episode->id}",
        );

        return response()->json(['observation' => $observation], 201);
    }

    // ── POST /participants/{p}/restraints/{episode}/discontinue ──────────────
    public function discontinue(Request $request, Participant $participant, RestraintEpisode $episode): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        abort_if($episode->participant_id !== $participant->id, 404);
        abort_if($episode->status !== 'active', 409, 'Episode is not active.');

        $validated = $request->validate([
            'discontinuation_reason' => 'required|string|min:5|max:2000',
        ]);

        $episode->update([
            'status'                  => 'discontinued',
            'discontinued_at'         => now(),
            'discontinued_by_user_id' => $u->id,
            'discontinuation_reason'  => $validated['discontinuation_reason'],
        ]);

        AuditLog::record(
            action: 'restraint.episode_discontinued',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'restraint_episode',
            resourceId: $episode->id,
            description: "Restraint discontinued: " . substr($validated['discontinuation_reason'], 0, 120),
        );

        return response()->json(['episode' => $episode->fresh()]);
    }

    // ── POST /participants/{p}/restraints/{episode}/idt-review ───────────────
    public function recordIdtReview(Request $request, Participant $participant, RestraintEpisode $episode): JsonResponse
    {
        $this->gate(['qa_compliance']);
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);
        abort_if($episode->participant_id !== $participant->id, 404);

        $validated = $request->validate([
            'idt_review_date' => 'nullable|date',
            'outcome_text'    => 'required|string|min:15|max:4000',
        ]);

        $episode->update([
            'idt_review_date'    => $validated['idt_review_date'] ?? now()->toDateString(),
            'idt_review_user_id' => $u->id,
            'outcome_text'       => $validated['outcome_text'],
        ]);

        AuditLog::record(
            action: 'restraint.idt_review_completed',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'restraint_episode',
            resourceId: $episode->id,
            description: "IDT review completed on episode #{$episode->id}",
        );

        return response()->json(['episode' => $episode->fresh()]);
    }
}

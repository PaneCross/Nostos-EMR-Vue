<?php

// ─── ReferralController ────────────────────────────────────────────────────────
// Manages PACE enrollment referrals: CRUD + state machine transitions.
//
// Route list:
//   GET    /enrollment/referrals                    → index()   (pipeline data)
//   POST   /enrollment/referrals                    → store()   (new referral)
//   GET    /enrollment/referrals/{referral}          → show()    (detail)
//   PUT    /enrollment/referrals/{referral}          → update()  (edit fields)
//   POST   /enrollment/referrals/{referral}/transition → transition() (status change)
//   POST   /participants/{participant}/disenroll     → disenroll() (end enrollment)
//
// Authorization:
//   - All authenticated users can view referrals for their tenant.
//   - Creating and transitioning requires enrollment or it_admin department.
//   - Disenrollment requires enrollment admin or it_admin (enforced in FormRequest).
//
// Tenant isolation: all queries are scoped to the authenticated user's tenant_id.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Exceptions\InvalidStateTransitionException;
use App\Http\Requests\DisenrollParticipantRequest;
use App\Http\Requests\StoreReferralRequest;
use App\Http\Requests\TransitionReferralRequest;
use App\Http\Requests\UpdateReferralRequest;
use App\Models\AuditLog;
use App\Models\ReferralStatusHistory;
use App\Models\Participant;
use App\Models\Referral;
use App\Services\EnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
    ) {}

    // ── Pipeline index ─────────────────────────────────────────────────────────

    /**
     * Return the enrollment pipeline page (Inertia) with all active referrals
     * grouped by status for Kanban display.
     *
     * GET /enrollment/referrals
     */
    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        // Load all referrals for the tenant (including terminal for history)
        $referrals = Referral::forTenant($tenantId)
            ->with(['assignedTo:id,first_name,last_name', 'participant:id,mrn,first_name,last_name', 'createdBy:id,first_name,last_name'])
            ->orderBy('referral_date', 'desc')
            ->get();

        // Group by status for Kanban columns
        $pipeline = collect(Referral::PIPELINE_STATUSES)
            ->mapWithKeys(fn ($status) => [
                $status => $referrals->where('status', $status)->values(),
            ]);

        return Inertia::render('Enrollment/Index', [
            'pipeline'      => $pipeline,
            'statuses'      => Referral::STATUS_LABELS,
            'sources'       => Referral::SOURCE_LABELS,
            'pipelineOrder' => Referral::PIPELINE_STATUSES,
        ]);
    }

    /**
     * Return raw referral list as JSON (for API calls from the frontend).
     *
     * GET /enrollment/referrals?format=json
     */
    public function store(StoreReferralRequest $request): JsonResponse
    {
        $this->authorizeEnrollmentDept($request->user());

        $referral = Referral::create([
            ...$request->validated(),
            'tenant_id'           => $request->user()->tenant_id,
            'created_by_user_id'  => $request->user()->id,
            'status'              => 'new',
        ]);

        AuditLog::record(
            action: 'enrollment.referral.created',
            tenantId: $referral->tenant_id,
            userId: $request->user()->id,
            resourceType: 'referral',
            resourceId: $referral->id,
            description: "Referral created for '{$referral->referred_by_name}' via {$referral->referral_source}",
        );

        // Seed initial status history entry for 'new' state
        ReferralStatusHistory::create([
            'referral_id'             => $referral->id,
            'tenant_id'               => $referral->tenant_id,
            'from_status'             => null,
            'to_status'               => 'new',
            'transitioned_by_user_id' => $request->user()->id,
        ]);

        return response()->json($referral->load(['assignedTo:id,first_name,last_name']), 201);
    }

    /**
     * Return a single referral with full details.
     *
     * GET /enrollment/referrals/{referral}
     */
    public function show(Request $request, Referral $referral): Response
    {
        $this->authorizeTenant($referral, $request->user());

        $referral->load(['assignedTo:id,first_name,last_name', 'participant:id,mrn,first_name,last_name', 'createdBy:id,first_name,last_name', 'site:id,name']);

        $statusHistory = ReferralStatusHistory::where('referral_id', $referral->id)
            ->with('transitionedBy:id,first_name,last_name')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($h) => [
                'id'           => $h->id,
                'from_status'  => $h->from_status,
                'to_status'    => $h->to_status,
                'transitioned_by' => $h->transitionedBy
                    ? ['first_name' => $h->transitionedBy->first_name, 'last_name' => $h->transitionedBy->last_name]
                    : null,
                'created_at'   => $h->created_at?->toIso8601String(),
            ]);

        $currentStatus    = $referral->status;
        $validTransitions = EnrollmentService::VALID_TRANSITIONS[$currentStatus] ?? [];

        return Inertia::render('Enrollment/Show', [
            'referral' => [
                'id'               => $referral->id,
                'referred_by_name' => $referral->referred_by_name,
                'referral_source'  => $referral->referral_source,
                'source_label'     => $referral->sourceLabel(),
                'referral_date'    => $referral->referral_date?->toDateString(),
                'status'           => $referral->status,
                'status_label'     => $referral->statusLabel(),
                'priority'         => $referral->priority,
                'notes'            => $referral->notes,
                'decline_reason'   => $referral->decline_reason,
                'withdrawn_reason' => $referral->withdrawn_reason,
                'assigned_to'      => $referral->assignedTo
                    ? ['id' => $referral->assignedTo->id, 'first_name' => $referral->assignedTo->first_name, 'last_name' => $referral->assignedTo->last_name]
                    : null,
                'created_by'       => $referral->createdBy
                    ? ['id' => $referral->createdBy->id, 'first_name' => $referral->createdBy->first_name, 'last_name' => $referral->createdBy->last_name]
                    : null,
                'participant'      => $referral->participant
                    ? ['id' => $referral->participant->id, 'mrn' => $referral->participant->mrn, 'first_name' => $referral->participant->first_name, 'last_name' => $referral->participant->last_name]
                    : null,
                'site'             => $referral->site
                    ? ['id' => $referral->site->id, 'name' => $referral->site->name]
                    : null,
                'created_at'       => $referral->created_at?->toIso8601String(),
                'updated_at'       => $referral->updated_at?->toIso8601String(),
            ],
            'validTransitions' => $validTransitions,
            'statusLabels'     => Referral::STATUS_LABELS,
            'pipelineSteps'    => ['new', 'intake_scheduled', 'intake_in_progress', 'intake_complete', 'eligibility_pending', 'pending_enrollment', 'enrolled'],
            'statusHistory'    => $statusHistory,
        ]);
    }

    /**
     * Update non-status fields on a referral.
     * Status changes must use the transition() endpoint.
     *
     * PUT /enrollment/referrals/{referral}
     */
    public function update(UpdateReferralRequest $request, Referral $referral): JsonResponse
    {
        $this->authorizeTenant($referral, $request->user());
        $this->authorizeEnrollmentDept($request->user());

        if ($referral->isTerminal()) {
            return response()->json(['message' => 'Cannot update a terminal (enrolled/declined/withdrawn) referral.'], 409);
        }

        $referral->update($request->validated());

        return response()->json($referral->fresh()->load(['assignedTo:id,first_name,last_name']), 200);
    }

    // ── State machine ─────────────────────────────────────────────────────────

    /**
     * Transition the referral to a new status via EnrollmentService.
     * Invalid transitions return 422.
     *
     * POST /enrollment/referrals/{referral}/transition
     */
    public function transition(TransitionReferralRequest $request, Referral $referral): JsonResponse
    {
        $this->authorizeTenant($referral, $request->user());
        $this->authorizeEnrollmentDept($request->user());

        try {
            $this->enrollmentService->transition(
                referral:  $referral,
                newStatus: $request->validated('new_status'),
                user:      $request->user(),
                extra:     array_filter([
                    'notes'            => $request->validated('notes'),
                    'decline_reason'   => $request->validated('decline_reason'),
                    'withdrawn_reason' => $request->validated('withdrawn_reason'),
                ]),
            );
        } catch (InvalidStateTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($referral->fresh()->load(['assignedTo:id,first_name,last_name', 'participant:id,mrn,first_name,last_name']), 200);
    }

    // ── Disenrollment ─────────────────────────────────────────────────────────

    /**
     * Disenroll a currently-enrolled participant.
     * Authorization enforced in DisenrollParticipantRequest (enrollment admin / it_admin only).
     *
     * POST /participants/{participant}/disenroll
     */
    public function disenroll(DisenrollParticipantRequest $request, Participant $participant): JsonResponse
    {
        $this->authorizeTenant($participant, $request->user());

        if ($participant->enrollment_status !== 'enrolled') {
            return response()->json(['message' => 'Participant is not currently enrolled.'], 409);
        }

        $this->enrollmentService->disenroll(
            participant:              $participant,
            reason:                   $request->validated('reason'),
            effectiveDate:            $request->validated('effective_date'),
            notes:                    $request->validated('notes'),
            cmsNotificationRequired:  (bool) $request->validated('cms_notification_required'),
            user:                     $request->user(),
        );

        return response()->json($participant->fresh(), 200);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Abort 403 if the model belongs to a different tenant. Works with Referral and Participant. */
    private function authorizeTenant(Referral|Participant $model, $user): void
    {
        abort_if(
            $model->tenant_id !== $user->tenant_id,
            403,
            'Access denied: resource belongs to a different organization.',
        );
    }

    /** Abort 403 if user is not in enrollment or it_admin department. */
    private function authorizeEnrollmentDept($user): void
    {
        abort_if(
            !in_array($user->department, ['enrollment', 'it_admin'], true),
            403,
            'Only Enrollment or IT Admin staff may manage referrals.',
        );
    }

    /**
     * POST /participants/{participant}/reenroll
     * Re-enrolls a previously disenrolled participant.
     * Only enrollment, it_admin, and super_admin may re-enroll.
     */
    public function reenroll(Request $request, Participant $participant): JsonResponse
    {
        $user = $request->user();
        $this->authorizeTenant($participant, $user);

        abort_unless(
            in_array($user->department, ['enrollment', 'it_admin'], true) || $user->isSuperAdmin(),
            403,
            'Only Enrollment or IT Admin may re-enroll participants.',
        );

        abort_unless(
            $participant->enrollment_status === 'disenrolled',
            422,
            'Only disenrolled participants can be re-enrolled.',
        );

        $participant->update([
            'enrollment_status'    => 'enrolled',
            'disenrollment_date'   => null,
            'disenrollment_reason' => null,
            'is_active'            => true,
        ]);

        AuditLog::record(
            action:       'participant.reenrolled',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'participant',
            resourceId:   $participant->id,
            description:  "Participant {$participant->mrn} re-enrolled by {$user->first_name} {$user->last_name}.",
        );

        return response()->json(['message' => 'Participant re-enrolled successfully.']);
    }

}
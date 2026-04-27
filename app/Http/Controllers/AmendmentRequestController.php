<?php

// ─── AmendmentRequestController : Phase P3 ──────────────────────────────────
// HIPAA §164.526 Right to Amend. Patient/proxy submits via portal; staff
// triages via /compliance/amendments queue.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AmendmentRequest;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Services\PhiDisclosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AmendmentRequestController extends Controller
{
    public function __construct(private PhiDisclosureService $disclosures) {}

    private function gateStaff(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['qa_compliance', 'it_admin', 'social_work', 'primary_care'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    /** GET /compliance/amendments : staff triage queue. */
    public function index(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gateStaff();
        $u = Auth::user();
        $rows = AmendmentRequest::forTenant($u->tenant_id)
            ->with(['participant:id,mrn,first_name,last_name', 'reviewer:id,first_name,last_name'])
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'under_review' THEN 1 ELSE 2 END")
            ->orderBy('deadline_at')
            ->get();

        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('Compliance/AmendmentRequests', [
                'requests' => $rows,
            ]);
        }
        return response()->json(['requests' => $rows]);
    }

    /** POST /participants/{p}/amendment-requests : staff-side create (portal also creates via portal flow). */
    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gateStaff();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'target_record_type'      => 'nullable|string|max:60',
            'target_record_id'        => 'nullable|integer',
            'target_field_or_section' => 'nullable|string|max:100',
            'requested_change'        => 'required|string|min:5|max:8000',
            'justification'           => 'nullable|string|max:4000',
            'requested_by_portal_user_id' => 'nullable|integer|exists:emr_participant_portal_users,id',
        ]);

        $row = AmendmentRequest::create(array_merge($validated, [
            'tenant_id'      => $participant->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'pending',
            'deadline_at'    => now()->addDays(AmendmentRequest::RESPONSE_DAYS),
        ]));

        AuditLog::record(
            action: 'amendment.requested',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'amendment_request',
            resourceId: $row->id,
            description: "Amendment request opened for participant #{$participant->id}.",
        );

        return response()->json(['request' => $row], 201);
    }

    /** POST /amendment-requests/{id}/decide : accept | deny | withdraw. */
    public function decide(Request $request, AmendmentRequest $amendmentRequest): JsonResponse
    {
        $this->gateStaff();
        $u = Auth::user();
        abort_if($amendmentRequest->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'status'             => 'required|in:under_review,accepted,denied,withdrawn',
            'decision_rationale' => 'nullable|string|max:4000',
            'patient_disagreement_statement' => 'nullable|string|max:4000',
            // Phase Q3 : §164.526(c)(3) downstream notification recipients.
            'share_with'                       => 'nullable|array|max:25',
            'share_with.*.recipient_type'      => 'required_with:share_with|in:insurer,public_health,lab,family,legal,patient_self,provider,other',
            'share_with.*.recipient_name'      => 'required_with:share_with|string|max:200',
            'share_with.*.recipient_contact'   => 'nullable|string|max:200',
        ]);

        // Denied requires rationale per §164.526(d).
        if ($validated['status'] === 'denied' && empty($validated['decision_rationale'])) {
            return response()->json([
                'error'   => 'rationale_required',
                'message' => 'Denial requires decision_rationale per §164.526(d)(1)(ii).',
            ], 422);
        }

        $shareWith = $validated['share_with'] ?? [];
        unset($validated['share_with']);

        // Phase X1 : Audit-12 H1: concurrency guard. Two reviewers POST'ing
        // simultaneously would each run the share_with loop and produce
        // duplicate immutable PhiDisclosure rows in the §164.528 accounting
        // log. Wrap the read/check/write in a transaction with row-level lock
        // so only the first decide wins; the second sees the closed status
        // and returns 409.
        try {
            DB::transaction(function () use ($amendmentRequest, $validated, $shareWith, $u) {
                $locked = AmendmentRequest::lockForUpdate()->findOrFail($amendmentRequest->id);
                if (! in_array($locked->status, AmendmentRequest::OPEN_STATUSES, true)) {
                    abort(409, "Amendment request already {$locked->status}; cannot re-decide.");
                }

                $locked->update(array_merge($validated, [
                    'reviewer_user_id'     => $u->id,
                    'reviewer_decision_at' => in_array($validated['status'], ['accepted', 'denied'], true) ? now() : null,
                ]));

                // Phase Q3 : On accept, log one PhiDisclosure per downstream
                // recipient identified per §164.526(c)(3). Inside the same
                // transaction so a partial failure rolls back disclosures too.
                if ($validated['status'] === 'accepted') {
                    foreach ($shareWith as $r) {
                        $this->disclosures->record(
                            tenantId: $u->tenant_id,
                            participantId: $locked->participant_id,
                            recipientType: $r['recipient_type'],
                            recipientName: $r['recipient_name'],
                            purpose: 'amendment_notification',
                            method: 'paper',
                            recordsDescribed: "Amendment notification for accepted amendment request #{$locked->id} per §164.526(c)(3).",
                            disclosedByUserId: $u->id,
                            recipientContact: $r['recipient_contact'] ?? null,
                            related: $locked,
                        );
                    }
                }

                // Refresh in-memory model so the audit-log block below sees the
                // updated state.
                $amendmentRequest->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Re-throw 409 / abort() unchanged; let other exceptions bubble.
            if ($e->getStatusCode() === 409) {
                throw $e;
            }
            throw $e;
        }

        AuditLog::record(
            action: 'amendment.' . $validated['status'],
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'amendment_request',
            resourceId: $amendmentRequest->id,
            description: "Amendment request #{$amendmentRequest->id} → {$validated['status']}.",
        );

        return response()->json(['request' => $amendmentRequest->fresh()]);
    }
}

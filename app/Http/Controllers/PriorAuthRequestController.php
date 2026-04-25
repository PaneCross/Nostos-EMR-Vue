<?php

// ─── PriorAuthRequestController — Phase P6 ──────────────────────────────────
namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\PriorAuthRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PriorAuthRequestController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['pharmacy', 'primary_care', 'qa_compliance', 'it_admin'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    /** GET /pharmacy/prior-auth — pharmacist + PCP queue. */
    public function queue(Request $request): JsonResponse|\Inertia\Response
    {
        $this->gate();
        $u = Auth::user();
        $rows = PriorAuthRequest::forTenant($u->tenant_id)
            ->with(['participant:id,mrn,first_name,last_name', 'requestedBy:id,first_name,last_name'])
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('submitted_at')
            ->get();
        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('Pharmacy/PriorAuthQueue', ['requests' => $rows]);
        }
        return response()->json(['requests' => $rows]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($participant->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'related_to_type'    => 'required|in:medication,clinical_order,procedure',
            'related_to_id'      => 'required|integer',
            'payer_type'         => 'required|in:' . implode(',', PriorAuthRequest::PAYER_TYPES),
            'justification_text' => 'required|string|min:10|max:8000',
            'urgency'            => 'required|in:standard,expedited',
        ]);

        $row = PriorAuthRequest::create(array_merge($validated, [
            'tenant_id'      => $u->tenant_id,
            'participant_id' => $participant->id,
            'status'         => 'draft',
            'requested_by_user_id' => $u->id,
        ]));

        AuditLog::record(
            action: 'prior_auth.created',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'prior_auth_request', resourceId: $row->id,
            description: "PA created for {$validated['related_to_type']} #{$validated['related_to_id']}",
        );

        return response()->json(['request' => $row], 201);
    }

    public function transition(Request $request, PriorAuthRequest $priorAuthRequest): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_if($priorAuthRequest->tenant_id !== $u->tenant_id, 403);

        $validated = $request->validate([
            'status'             => 'required|in:submitted,approved,denied,withdrawn,expired',
            'decision_rationale' => 'nullable|string|max:4000',
            'expiration_date'    => 'nullable|date|after:today',
            'approval_reference' => 'nullable|string|max:100',
        ]);

        $valid = [
            'draft'     => ['submitted', 'withdrawn'],
            'submitted' => ['approved', 'denied', 'withdrawn'],
            'approved'  => ['expired'],
        ];
        if (! isset($valid[$priorAuthRequest->status]) || ! in_array($validated['status'], $valid[$priorAuthRequest->status], true)) {
            return response()->json([
                'error' => 'invalid_transition',
                'message' => "Cannot move from {$priorAuthRequest->status} to {$validated['status']}.",
            ], 422);
        }

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'submitted') $updates['submitted_at'] = now();
        if (in_array($validated['status'], ['approved', 'denied'], true)) {
            $updates['decision_at'] = now();
            $updates['decided_by_user_id'] = $u->id;
        }
        if ($validated['status'] === 'denied' && empty($validated['decision_rationale'])) {
            return response()->json([
                'error' => 'rationale_required',
                'message' => 'Denial requires decision_rationale.',
            ], 422);
        }
        if (isset($validated['decision_rationale'])) $updates['decision_rationale'] = $validated['decision_rationale'];
        if (isset($validated['expiration_date']))    $updates['expiration_date'] = $validated['expiration_date'];
        if (isset($validated['approval_reference'])) $updates['approval_reference'] = $validated['approval_reference'];

        $priorAuthRequest->update($updates);

        AuditLog::record(
            action: "prior_auth.{$validated['status']}",
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'prior_auth_request', resourceId: $priorAuthRequest->id,
            description: "PA #{$priorAuthRequest->id} → {$validated['status']}.",
        );

        return response()->json(['request' => $priorAuthRequest->fresh()]);
    }
}

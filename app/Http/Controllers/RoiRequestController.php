<?php

// ─── RoiRequestController ────────────────────────────────────────────────────
// Phase B8b. Release of Information (records disclosure) queue.
// HIPAA §164.524 : 30-day response deadline. qa_compliance ("privacy officer")
// manages the queue; clinical staff can intake.
//
// Routes:
//   GET  /participants/{p}/roi-requests          index()
//   POST /participants/{p}/roi-requests          store()    (auto-sets due_by)
//   POST /roi-requests/{roi}/update-status       updateStatus()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\RoiRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RoiRequestController extends Controller
{
    private function gate(array $extra = []): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        $allow = array_merge(['qa_compliance', 'enrollment', 'it_admin', 'primary_care', 'social_work'], $extra);
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

        $rows = RoiRequest::forTenant($u->tenant_id)
            ->where('participant_id', $participant->id)
            ->with('fulfilledBy:id,first_name,last_name')
            ->orderByDesc('requested_at')->get();

        return response()->json(['requests' => $rows]);
    }

    public function store(Request $request, Participant $participant): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($participant, $u);

        $validated = $request->validate([
            'requestor_type'          => 'required|in:' . implode(',', RoiRequest::REQUESTOR_TYPES),
            'requestor_name'          => 'required|string|max:200',
            'requestor_contact'       => 'nullable|string|max:300',
            'records_requested_scope' => 'required|string|max:4000',
            'requested_at'            => 'nullable|date',
            'notes'                   => 'nullable|string|max:4000',
        ]);

        $requestedAt = isset($validated['requested_at'])
            ? Carbon::parse($validated['requested_at'])
            : now();

        $roi = RoiRequest::create(array_merge($validated, [
            'tenant_id'      => $u->tenant_id,
            'participant_id' => $participant->id,
            'requested_at'   => $requestedAt,
            'due_by'         => $requestedAt->copy()->addDays(RoiRequest::RESPONSE_DEADLINE_DAYS),
            'status'         => 'pending',
        ]));

        AuditLog::record(
            action:       'roi.requested',
            tenantId:     $u->tenant_id,
            userId:       $u->id,
            resourceType: 'roi_request',
            resourceId:   $roi->id,
            description:  "ROI request logged for participant #{$participant->id} by {$validated['requestor_name']} ({$validated['requestor_type']}). Due {$roi->due_by->toDateString()}.",
        );

        return response()->json(['request' => $roi], 201);
    }

    /**
     * Transition status. Valid moves:
     *   pending       → in_progress | denied | withdrawn
     *   in_progress   → fulfilled | denied | withdrawn
     *   (terminal states cannot transition further)
     */
    public function updateStatus(Request $request, RoiRequest $roi): JsonResponse
    {
        $this->gate();
        $u = Auth::user();
        $this->requireSameTenant($roi, $u);

        $validated = $request->validate([
            'status'        => 'required|in:' . implode(',', RoiRequest::STATUSES),
            'denial_reason' => 'nullable|string|max:2000',
            'notes'         => 'nullable|string|max:4000',
        ]);

        $valid = [
            'pending'     => ['in_progress', 'denied', 'withdrawn'],
            'in_progress' => ['fulfilled', 'denied', 'withdrawn'],
        ];
        if (! isset($valid[$roi->status]) || ! in_array($validated['status'], $valid[$roi->status], true)) {
            return response()->json([
                'error'   => 'invalid_transition',
                'message' => "Cannot move from {$roi->status} to {$validated['status']}.",
            ], 422);
        }

        // Denial requires reason
        if ($validated['status'] === 'denied' && empty($validated['denial_reason'])) {
            return response()->json([
                'error'   => 'denial_reason_required',
                'message' => 'denial_reason is required to deny an ROI request.',
            ], 422);
        }

        $updates = ['status' => $validated['status']];
        if ($validated['status'] === 'fulfilled') {
            $updates['fulfilled_at']         = now();
            $updates['fulfilled_by_user_id'] = $u->id;
        }
        if ($validated['status'] === 'denied') {
            $updates['denial_reason'] = $validated['denial_reason'];
        }
        if (isset($validated['notes'])) {
            $updates['notes'] = $validated['notes'];
        }

        $roi->update($updates);

        AuditLog::record(
            action:       'roi.status_changed',
            tenantId:     $u->tenant_id,
            userId:       $u->id,
            resourceType: 'roi_request',
            resourceId:   $roi->id,
            description:  "ROI request #{$roi->id} transitioned to {$validated['status']}.",
        );

        // Phase P2 : log to HIPAA Accounting of Disclosures when fulfilled.
        if ($validated['status'] === 'fulfilled') {
            $recipientType = match ($roi->requestor_type ?? 'self') {
                'self'        => 'patient_self',
                'legal_rep'   => 'legal',
                'provider'    => 'provider',
                'insurer'     => 'insurer',
                default       => 'other',
            };
            app(\App\Services\PhiDisclosureService::class)->record(
                tenantId:         $u->tenant_id,
                participantId:    $roi->participant_id,
                recipientType:    $recipientType,
                recipientName:    $roi->requestor_name ?? 'unknown',
                purpose:          'ROI request #' . $roi->id . ' fulfilled',
                method:           'paper',
                recordsDescribed: $roi->records_requested_scope ?? 'unspecified scope',
                disclosedByUserId: $u->id,
                recipientContact: $roi->requestor_contact ?? null,
                related:          $roi,
            );
        }

        return response()->json(['request' => $roi->fresh()]);
    }
}

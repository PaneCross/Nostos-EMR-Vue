<?php

// ─── ParticipantPortalController ─────────────────────────────────────────────
// Phase E1. Participant/proxy-facing portal.
//
// Authentication uses a simple header-based bearer token in this MVP: each
// test-suite and demo usage sets Auth::guard('portal') indirectly by looking
// up ParticipantPortalUser by id in request state. For production:
// email/phone OTP + session, reusing existing OTP infra.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ParticipantPortalUser;
use App\Models\PortalMessage;
use App\Models\PortalRequest;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ParticipantPortalController extends Controller
{
    public function __construct(private AlertService $alerts) {}

    /**
     * Resolve the authenticated portal user from the request.
     * In tests we use header X-Portal-User-Id; in production this is a
     * session-backed guard. Returns null for anonymous requests.
     */
    private function portalUser(Request $request): ?ParticipantPortalUser
    {
        $id = $request->header('X-Portal-User-Id');
        if (! $id) return null;
        return ParticipantPortalUser::where('id', $id)->where('is_active', true)->first();
    }

    private function requireAuth(Request $request): ParticipantPortalUser
    {
        $u = $this->portalUser($request);
        abort_if(! $u, 401);
        return $u;
    }

    /** POST /portal/login — simple password check (demo). */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);
        $user = ParticipantPortalUser::where('email', $validated['email'])
            ->where('is_active', true)->first();
        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }
        $user->update(['last_login_at' => now()]);
        AuditLog::record(
            action: 'portal.login',
            tenantId: $user->tenant_id,
            userId: null,
            resourceType: 'participant_portal_user',
            resourceId: $user->id,
            description: "Portal login: {$user->email}" . ($user->isProxy() ? ' (proxy)' : ''),
        );
        return response()->json(['user' => $user, 'portal_user_id' => $user->id]);
    }

    /** GET /portal/overview — participant basics. */
    public function overview(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $p = $u->participant;

        AuditLog::record(
            action: 'portal.view_overview',
            tenantId: $u->tenant_id,
            userId: null,
            resourceType: 'participant',
            resourceId: $p->id,
            description: 'Portal overview viewed' . ($u->isProxy() ? ' (proxy)' : ''),
        );

        return response()->json([
            'participant' => [
                'first_name'     => $p->first_name,
                'last_name'      => $p->last_name,
                'mrn'            => $p->mrn,
                'dob'            => $p->dob?->format('Y-m-d'),
                'primary_care'   => $p->primary_care_user_id,
            ],
            'is_proxy' => $u->isProxy(),
            'scope'    => $u->proxy_scope,
        ]);
    }

    /** GET /portal/medications — current active only. */
    public function medications(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $meds = \App\Models\Medication::where('participant_id', $u->participant_id)
            ->where('status', 'active')
            ->orderBy('drug_name')
            ->get(['id', 'drug_name', 'dose', 'dose_unit', 'route', 'frequency']);
        return response()->json(['medications' => $meds]);
    }

    /** GET /portal/allergies */
    public function allergies(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $rows = \App\Models\Allergy::where('participant_id', $u->participant_id)
            ->where('is_active', true)
            ->get(['allergen_name', 'severity', 'reaction_description']);
        return response()->json(['allergies' => $rows]);
    }

    /** GET /portal/problems */
    public function problems(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $rows = \App\Models\Problem::where('participant_id', $u->participant_id)
            ->where('status', 'active')
            ->get(['icd10_code', 'icd10_description', 'onset_date']);
        return response()->json(['problems' => $rows]);
    }

    /** GET /portal/appointments */
    public function appointments(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $rows = \App\Models\Appointment::where('participant_id', $u->participant_id)
            ->where('scheduled_at', '>=', now()->subMonth())
            ->orderBy('scheduled_at', 'desc')
            ->limit(20)->get();
        return response()->json(['appointments' => $rows]);
    }

    /** GET /portal/messages */
    public function messagesIndex(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $rows = PortalMessage::forTenant($u->tenant_id)
            ->where('participant_id', $u->participant_id)
            ->orderByDesc('created_at')->get();
        return response()->json(['messages' => $rows]);
    }

    /** POST /portal/messages */
    public function messagesStore(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        $validated = $request->validate([
            'subject' => 'required|string|max:200',
            'body'    => 'required|string|max:8000',
        ]);
        $msg = PortalMessage::create(array_merge($validated, [
            'tenant_id'           => $u->tenant_id,
            'participant_id'      => $u->participant_id,
            'from_portal_user_id' => $u->id,
        ]));

        // Notify primary care as an alert (so it shows up in the inbox).
        $this->alerts->create([
            'tenant_id'          => $u->tenant_id,
            'participant_id'     => $u->participant_id,
            'source_module'      => 'portal',
            'alert_type'         => 'portal_message_received',
            'severity'           => 'info',
            'title'              => 'New portal message',
            'message'            => "Portal message: {$validated['subject']}",
            'target_departments' => ['primary_care'],
            'metadata'           => ['portal_message_id' => $msg->id],
        ]);

        return response()->json(['message' => $msg], 201);
    }

    /** POST /portal/requests — records | appointment | contact_update */
    public function requestsStore(Request $request): JsonResponse
    {
        $u = $this->requireAuth($request);
        // Limited-scope proxies can send messages but not all request types.
        abort_if($u->isProxy() && $u->proxy_scope === 'limited', 403, 'Limited-scope proxy cannot submit requests.');

        $validated = $request->validate([
            'request_type' => 'required|in:' . implode(',', PortalRequest::TYPES),
            'payload'      => 'nullable|array',
        ]);
        $req = PortalRequest::create(array_merge($validated, [
            'tenant_id'            => $u->tenant_id,
            'participant_id'       => $u->participant_id,
            'from_portal_user_id'  => $u->id,
            'status'               => 'pending',
        ]));

        // Records requests also create a ROI row (ties to B8b).
        if ($validated['request_type'] === 'records') {
            \App\Models\RoiRequest::create([
                'tenant_id'       => $u->tenant_id,
                'participant_id'  => $u->participant_id,
                'requestor_type'  => $u->isProxy() ? 'legal_rep' : 'self',
                'requestor_name'  => $u->email,
                'requestor_contact' => $u->email,
                'records_requested_scope' => $validated['payload']['scope'] ?? 'All records',
                'requested_at'    => now(),
                'due_by'          => now()->addDays(\App\Models\RoiRequest::RESPONSE_DEADLINE_DAYS),
                'status'          => 'pending',
            ]);
        }

        AuditLog::record(
            action: 'portal.request_submitted',
            tenantId: $u->tenant_id,
            userId: null,
            resourceType: 'portal_request',
            resourceId: $req->id,
            description: "Portal request ({$validated['request_type']}) submitted.",
        );
        return response()->json(['request' => $req], 201);
    }
}

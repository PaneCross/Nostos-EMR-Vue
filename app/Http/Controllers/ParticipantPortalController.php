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
    /**
     * Resolve the authenticated portal user. Session takes precedence; header
     * kept as back-compat for the next 90 days (gated by config; see
     * services.portal.allow_header_auth).
     */
    private function portalUser(Request $request): ?ParticipantPortalUser
    {
        // Session path (primary, post-I4).
        $sid = $request->session()->get('portal_user_id');
        if ($sid) {
            $u = ParticipantPortalUser::where('id', $sid)->where('is_active', true)->first();
            if ($u) return $u;
            // Session stale — clear.
            $request->session()->forget('portal_user_id');
        }
        // Header back-compat.
        if (config('services.portal.allow_header_auth', true)) {
            $hid = $request->header('X-Portal-User-Id');
            if ($hid) {
                return ParticipantPortalUser::where('id', $hid)->where('is_active', true)->first();
            }
        }
        return null;
    }

    private function requireAuth(Request $request): ParticipantPortalUser
    {
        $u = $this->portalUser($request);
        abort_if(! $u, 401);
        return $u;
    }

    /**
     * Phase O3 — resolve auth and prefer redirect to the login page for HTML
     * browser requests (Inertia navigations). For JSON/axios, keep the 401.
     */
    private function requireAuthOrRedirect(Request $request): ParticipantPortalUser|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->portalUser($request);
        if ($u) return $u;
        if ($request->wantsJson()) abort(401);
        return redirect('/portal/login');
    }

    /** POST /portal/login — session-backed, rate-limited. */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        // Rate limiting: 5 attempts per email per 15 minutes.
        $rlKey = 'portal_login:' . strtolower($validated['email']);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rlKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($rlKey);
            return response()->json([
                'error'   => 'rate_limited',
                'message' => "Too many attempts. Try again in {$seconds} seconds.",
            ], 429);
        }

        $user = ParticipantPortalUser::where('email', $validated['email'])
            ->where('is_active', true)->first();
        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($rlKey, 900);
            // Audit the failure even when user lookup failed — security signal.
            AuditLog::record(
                action: 'portal.login_failed',
                tenantId: $user?->tenant_id ?? 0,
                userId: null,
                resourceType: 'participant_portal_user',
                resourceId: $user?->id ?? 0,
                description: "Failed portal login attempt: {$validated['email']}",
            );
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($rlKey);
        $user->update(['last_login_at' => now()]);

        // Session-bind
        $request->session()->regenerate();
        $request->session()->put('portal_user_id', $user->id);

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

    /**
     * Phase L1 — POST /portal/otp/send: request an OTP for email login.
     * Rate-limited identical to password login (5 per 15 min per email).
     */
    public function otpSend(Request $request, \App\Services\PortalOtpService $otp): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);
        $rlKey = 'portal_otp_send:' . strtolower($validated['email']);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rlKey, 5)) {
            $seconds = \Illuminate\Support\Facades\RateLimiter::availableIn($rlKey);
            return response()->json([
                'error' => 'rate_limited',
                'message' => "Too many requests. Try again in {$seconds} seconds.",
            ], 429);
        }
        \Illuminate\Support\Facades\RateLimiter::hit($rlKey, 900);
        $otp->sendOtp($validated['email'], (string) $request->ip());
        return response()->json(['ok' => true]);
    }

    /** Phase L1 — POST /portal/otp/verify: establish session with OTP. */
    public function otpVerify(Request $request, \App\Services\PortalOtpService $otp): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);
        $rlKey = 'portal_otp_verify:' . strtolower($validated['email']);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rlKey, 10)) {
            return response()->json(['error' => 'rate_limited'], 429);
        }
        $result = $otp->verifyOtp($validated['email'], $validated['code'], (string) $request->ip());
        if (! $result['success']) {
            \Illuminate\Support\Facades\RateLimiter::hit($rlKey, 900);
            return response()->json(['error' => 'invalid_otp', 'message' => $result['error']], 401);
        }
        $user = $result['user'];
        $request->session()->regenerate();
        $request->session()->put('portal_user_id', $user->id);
        return response()->json(['user' => $user, 'portal_user_id' => $user->id]);
    }

    /** POST /portal/logout — clears session. */
    public function logout(Request $request): JsonResponse
    {
        $u = $this->portalUser($request);
        if ($u) {
            AuditLog::record(
                action: 'portal.logout',
                tenantId: $u->tenant_id,
                userId: null,
                resourceType: 'participant_portal_user',
                resourceId: $u->id,
                description: "Portal logout: {$u->email}",
            );
        }
        $request->session()->forget('portal_user_id');
        $request->session()->regenerate();
        return response()->json(['ok' => true]);
    }

    /** GET /portal/login — Inertia login page. */
    public function loginPage(): \Inertia\Response
    {
        return \Inertia\Inertia::render('Portal/Login');
    }

    /**
     * GET /portal/overview — participant basics.
     * Phase O3: dual-serve — JSON for axios; Inertia for browser navigation.
     */
    public function overview(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        $p = $u->participant;

        AuditLog::record(
            action: 'portal.view_overview',
            tenantId: $u->tenant_id,
            userId: null,
            resourceType: 'participant',
            resourceId: $p->id,
            description: 'Portal overview viewed' . ($u->isProxy() ? ' (proxy)' : ''),
        );

        if (! $request->wantsJson()) {
            return \Inertia\Inertia::render('Portal/Overview');
        }

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
    public function medications(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Portal/Medications');

        $meds = \App\Models\Medication::where('participant_id', $u->participant_id)
            ->where('status', 'active')
            ->orderBy('drug_name')
            ->get(['id', 'drug_name', 'dose', 'dose_unit', 'route', 'frequency']);
        return response()->json(['medications' => $meds]);
    }

    /** GET /portal/allergies */
    public function allergies(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Portal/Allergies');

        $rows = \App\Models\Allergy::where('participant_id', $u->participant_id)
            ->where('is_active', true)
            ->get(['allergen_name', 'severity', 'reaction_description']);
        return response()->json(['allergies' => $rows]);
    }

    /** GET /portal/problems */
    public function problems(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Portal/Problems');

        $rows = \App\Models\Problem::where('participant_id', $u->participant_id)
            ->where('status', 'active')
            ->get(['icd10_code', 'icd10_description', 'onset_date']);
        return response()->json(['problems' => $rows]);
    }

    /** GET /portal/appointments */
    public function appointments(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Portal/Appointments');

        $rows = \App\Models\Appointment::where('participant_id', $u->participant_id)
            ->where('scheduled_at', '>=', now()->subMonth())
            ->orderBy('scheduled_at', 'desc')
            ->limit(20)->get();
        return response()->json(['appointments' => $rows]);
    }

    /** GET /portal/messages */
    public function messagesIndex(Request $request): JsonResponse|\Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        if (! $request->wantsJson()) return \Inertia\Inertia::render('Portal/Messages');

        $rows = PortalMessage::forTenant($u->tenant_id)
            ->where('participant_id', $u->participant_id)
            ->orderByDesc('created_at')->get();
        return response()->json(['messages' => $rows]);
    }

    /** GET /portal/requests — Inertia render of the portal request form. */
    public function requestsIndex(Request $request): \Inertia\Response|\Symfony\Component\HttpFoundation\RedirectResponse
    {
        $u = $this->requireAuthOrRedirect($request);
        if ($u instanceof \Symfony\Component\HttpFoundation\RedirectResponse) return $u;
        return \Inertia\Inertia::render('Portal/Requests');
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

        // Phase P3 — Amendment requests also create an AmendmentRequest row
        // with the §164.526(b)(2) 60-day deadline.
        if ($validated['request_type'] === 'amendment') {
            \App\Models\AmendmentRequest::create([
                'tenant_id'                   => $u->tenant_id,
                'participant_id'              => $u->participant_id,
                'requested_by_portal_user_id' => $u->id,
                'target_record_type'          => $validated['payload']['target_record_type'] ?? null,
                'target_record_id'            => $validated['payload']['target_record_id'] ?? null,
                'target_field_or_section'     => $validated['payload']['target_field_or_section'] ?? null,
                'requested_change'            => $validated['payload']['requested_change'] ?? 'No detail provided',
                'justification'               => $validated['payload']['justification'] ?? null,
                'status'                      => 'pending',
                'deadline_at'                 => now()->addDays(\App\Models\AmendmentRequest::RESPONSE_DAYS),
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

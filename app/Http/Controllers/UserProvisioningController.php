<?php

// ─── UserProvisioningController ───────────────────────────────────────────────
// IT Admin panel: user account lifecycle management.
// IT Admin is the only role that can create, deactivate, or modify user accounts :
// there is no self-registration. All changes are written to the audit log.
//
// Routes (all require department='it_admin'):
//   GET   /it-admin/users                       → users()
//   POST  /it-admin/users                       → provisionUser()
//   POST  /it-admin/users/{user}/deactivate     → deactivateUser()
//   POST  /it-admin/users/{user}/reactivate     → reactivateUser()
//   POST  /it-admin/users/{user}/reset-access   → resetAccess()
//   PATCH /it-admin/users/{user}/designations   → updateDesignations()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Mail\WelcomeEmail;
use App\Models\AuditLog;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserProvisioningController extends Controller
{
    /**
     * Audit-log action patterns to EXCLUDE from the per-user activity feed.
     *
     * The user-details modal shows "actions this user performed" and we filter
     * out pure-read events (page views, navigation, searches, FHIR API reads)
     * because they bury the signal : what we want is creates / updates /
     * deletes / completions / acknowledgments. SQL LIKE patterns; '%' wildcard.
     *
     * Anything matching one of these is hidden from the modal's activity feed
     * but remains in shared_audit_logs (the IT-admin audit page still sees them).
     */
    private const ACTIVITY_FEED_EXCLUDE_PATTERNS = [
        '%.viewed',                  // catches participant.profile.viewed, grievance.viewed, etc.
        'global.search',
        'participant.searched',
        'portal.view_%',             // portal.view_overview, etc.
        'fhir.read.%',               // FHIR R4 API reads
        'qapi_annual_evaluation.reviewed',
    ];

    /**
     * Department labels used by the Users page (search/filter dropdowns).
     * Mirror of the shared_users_department_check enum with display strings.
     */
    private const DEPT_LABELS = [
        'primary_care'      => 'Primary Care',
        'therapies'         => 'Therapies',
        'social_work'       => 'Social Work',
        'behavioral_health' => 'Behavioral Health',
        'dietary'           => 'Dietary',
        'activities'        => 'Activities',
        'home_care'         => 'Home Care',
        'transportation'    => 'Transportation',
        'pharmacy'          => 'Pharmacy',
        'idt'               => 'IDT',
        'enrollment'        => 'Enrollment',
        'finance'           => 'Finance',
        'qa_compliance'     => 'QA Compliance',
        'it_admin'          => 'IT Admin',
    ];

    /**
     * Render the user management page with all users for this tenant.
     */
    public function users(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        $users = User::where('tenant_id', $tenantId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department', 'is_active', 'created_at', 'designations']);

        return Inertia::render('ItAdmin/Users', [
            'users'              => $users,
            'designationLabels'  => User::DESIGNATION_LABELS,
            // designation_routing (per-designation permissions + filtered
            // notifications) is now lazy-loaded by the user-details modal so
            // it can apply the per-tenant + per-site preference cascade.
            'deptLabels'         => self::DEPT_LABELS,
        ]);
    }

    /**
     * Return rich detail JSON for the user-details modal on the Users page.
     *
     * Bundles everything an IT Admin needs to evaluate a user without
     * navigating to multiple pages: account info (login activity, lockout
     * state, provisioning lineage, site assignment), credentials + training
     * summary (with link to the full credentials page for editing), and the
     * per-user activity feed filtered to data-mutating actions only.
     *
     * Endpoint: GET /it-admin/users/{user}/details
     */
    public function userDetails(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();
        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        // ── Account info ──────────────────────────────────────────────────────
        $user->load(['site:id,name', 'tenant:id,name']);
        $provisionedBy = $user->provisioned_by_user_id
            ? User::where('id', $user->provisioned_by_user_id)
                ->value(DB::raw("first_name || ' ' || last_name"))
            : null;

        // ── Credentials summary ──────────────────────────────────────────────
        // Numbers only here : full editing UI lives at /it-admin/users/{user}/credentials.
        $credentials = StaffCredential::forTenant($tenantId)
            ->where('user_id', $user->id)
            ->get(['id', 'credential_type', 'title', 'expires_at', 'verified_at']);

        $now             = Carbon::now();
        $expiringWindow  = $now->copy()->addDays(60);
        $activeCount     = $credentials->filter(fn ($c) => ! $c->expires_at || $c->expires_at->gt($expiringWindow))->count();
        $expiringCount   = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->lte($expiringWindow) && $c->expires_at->gte($now))->count();
        $expiredCount    = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->lt($now))->count();

        // ── Training summary (last 12 months) ────────────────────────────────
        $hoursByCategory = StaffTrainingRecord::forTenant($tenantId)
            ->where('user_id', $user->id)
            ->where('completed_at', '>=', $now->copy()->subYear()->toDateString())
            ->selectRaw('category, SUM(training_hours) as total_hours')
            ->groupBy('category')
            ->pluck('total_hours', 'category')
            ->map(fn ($v) => (float) $v)
            ->toArray();
        $totalHours12mo = round((float) array_sum($hoursByCategory), 1);

        // ── Activity feed (data-mutating actions only) ───────────────────────
        $excluded = self::ACTIVITY_FEED_EXCLUDE_PATTERNS;
        $activityBase = AuditLog::where('tenant_id', $tenantId)
            ->where('user_id', $user->id);
        // Apply each LIKE pattern as a NOT LIKE : wrapped in a closure so the
        // chained `where` calls form a single AND group.
        $activityBase->where(function ($q) use ($excluded) {
            foreach ($excluded as $pattern) {
                $q->where('action', 'NOT LIKE', $pattern);
            }
        });

        $count30 = (clone $activityBase)->where('created_at', '>=', $now->copy()->subDays(30))->count();
        $count90 = (clone $activityBase)->where('created_at', '>=', $now->copy()->subDays(90))->count();

        $topActions = (clone $activityBase)
            ->where('created_at', '>=', $now->copy()->subDays(90))
            ->selectRaw('action, COUNT(*) as cnt')
            ->groupBy('action')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get()
            ->map(fn ($row) => ['action' => $row->action, 'count' => (int) $row->cnt]);

        $recent = (clone $activityBase)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'action', 'resource_type', 'resource_id', 'description', 'created_at'])
            ->map(fn ($row) => [
                'id'            => $row->id,
                'action'        => $row->action,
                'resource_type' => $row->resource_type,
                'resource_id'   => $row->resource_id,
                'description'   => $row->description,
                'created_at'    => $row->created_at?->toIso8601String(),
            ]);

        // ── Designation routing (filtered by current Org Settings prefs) ─────
        // For each designation in the catalog: permissions are static (RBAC-level
        // and don't depend on settings); notifications are filtered to ONLY the
        // ones currently enabled for this tenant + the user's site (cascade).
        // Required-status keys always show as enabled. Site-level overrides beat
        // org-level beats catalog default.
        /** @var NotificationPreferenceService $prefSvc */
        $prefSvc = app(NotificationPreferenceService::class);
        $designationRouting = [];
        foreach (User::DESIGNATION_DETAILS as $key => $entry) {
            $enabledNotifications = [];
            foreach ($entry['notifications'] ?? [] as $notification) {
                $prefKey = $notification['key'] ?? null;
                if (! $prefKey) continue;
                if ($prefSvc->shouldNotify($tenantId, $prefKey, $user->site_id)) {
                    $enabledNotifications[] = $notification;
                }
            }
            $designationRouting[$key] = [
                'label'         => $entry['label'],
                'summary'       => $entry['summary'],
                'permissions'   => $entry['permissions'] ?? [],
                'notifications' => $enabledNotifications,
                'total_notifications_in_catalog' => count($entry['notifications'] ?? []),
            ];
        }

        return response()->json([
            'user' => [
                'id'              => $user->id,
                'first_name'      => $user->first_name,
                'last_name'       => $user->last_name,
                'email'           => $user->email,
                'department'      => $user->department,
                'role'            => $user->role,
                'is_active'       => (bool) $user->is_active,
                'designations'    => $user->designations ?? [],
                'job_title'       => $user->job_title,
                'supervisor_user_id' => $user->supervisor_user_id,
                'supervisor_name' => $user->supervisor_user_id
                    ? User::where('id', $user->supervisor_user_id)->value(DB::raw("first_name || ' ' || last_name"))
                    : null,
                'site'            => $user->site ? ['id' => $user->site->id, 'name' => $user->site->name] : null,
                'last_login_at'   => $user->last_login_at?->toIso8601String(),
                'failed_login_attempts' => (int) ($user->failed_login_attempts ?? 0),
                'locked_until'    => $user->locked_until?->toIso8601String(),
                'provisioned_at'  => $user->provisioned_at?->toIso8601String() ?? $user->created_at?->toIso8601String(),
                'provisioned_by'  => $provisionedBy,
                'created_at'      => $user->created_at?->toIso8601String(),
            ],
            'credentials' => [
                'active_count'    => $activeCount,
                'expiring_count'  => $expiringCount,
                'expired_count'   => $expiredCount,
                'total_count'     => $credentials->count(),
            ],
            'training' => [
                'total_hours_12mo' => $totalHours12mo,
                'by_category'      => $hoursByCategory,
            ],
            'activity' => [
                'count_30_days' => $count30,
                'count_90_days' => $count90,
                'top_actions'   => $topActions,
                'recent'        => $recent,
            ],
            'designation_routing' => $designationRouting,
        ]);
    }

    /**
     * Create a new user account and send a welcome email with login instructions.
     * Returns the created user as JSON with HTTP 201.
     */
    public function provisionUser(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:shared_users,email'],
            'department' => ['required', 'string', 'in:primary_care,therapies,social_work,behavioral_health,dietary,activities,home_care,transportation,pharmacy,idt,enrollment,finance,qa_compliance,it_admin,executive'],
            'role'       => ['required', 'string', 'in:admin,standard'],
            // Credentials V1 wiring : job_title drives credential targeting,
            // supervisor_user_id drives the 14-day CC + escalation chain.
            'job_title'          => ['nullable', 'string', 'max:60',
                \Illuminate\Validation\Rule::exists('emr_job_titles', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'supervisor_user_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('shared_users', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('is_active', true))],
        ]);

        $user = User::create(array_merge($validated, [
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]));

        Mail::to($user->email)->send(new WelcomeEmail($user));

        AuditLog::record(
            action:       'it_admin.user.provisioned',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['email' => $user->email, 'department' => $user->department],
        );

        return response()->json([
            'user' => $user->only('id', 'first_name', 'last_name', 'email', 'department', 'role', 'is_active'),
        ], 201);
    }

    /**
     * Deactivate a user account and immediately invalidate all their sessions.
     * The user is set to is_active=false and cannot request new OTP codes.
     */
    public function deactivateUser(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');
        abort_if($user->id === $request->user()->id, 422, 'Cannot deactivate your own account');

        $user->update(['is_active' => false]);
        $this->invalidateSessions($user->id);

        AuditLog::record(
            action:       'it_admin.user.deactivated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['is_active' => false],
        );

        return response()->json(['deactivated' => true]);
    }

    /**
     * Reactivate a previously deactivated user account.
     * Sets is_active=true so they can request OTP codes again.
     */
    public function reactivateUser(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $user->update(['is_active' => true]);

        AuditLog::record(
            action:       'it_admin.user.reactivated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['is_active' => true],
        );

        return response()->json(['reactivated' => true]);
    }

    /**
     * Invalidate all active sessions for a user without deactivating their account.
     * Forces an immediate logout; the user can sign back in normally.
     */
    public function resetAccess(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $this->invalidateSessions($user->id);

        AuditLog::record(
            action:       'it_admin.user.access_reset',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
        );

        return response()->json(['reset' => true]);
    }

    /**
     * Update the functional accountability designations for a user.
     * Designations (e.g. 'medical_director', 'charge_nurse') are used for targeted
     * alerting only : they do not affect RBAC access or navigation.
     */
    public function updateDesignations(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $validated = $request->validate([
            'designations'   => ['present', 'array'],
            'designations.*' => ['string', 'in:' . implode(',', User::DESIGNATIONS)],
        ]);

        $user->update(['designations' => array_values(array_unique($validated['designations']))]);

        AuditLog::record(
            action:       'it_admin.user.designations_updated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            newValues:    ['designations' => $user->designations],
        );

        return response()->json([
            'user' => $user->only('id', 'first_name', 'last_name', 'department', 'designations'),
        ]);
    }

    /**
     * Returns the dropdown options the role-assignment form needs : the
     * tenant's active job-title catalog + active users (potential supervisors).
     */
    public function roleAssignmentOptions(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();

        return response()->json([
            'job_titles' => \App\Models\JobTitle::forTenant($tenantId)
                ->active()->orderBy('sort_order')->orderBy('label')
                ->get(['code', 'label']),
            'potential_supervisors' => User::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'department'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => "{$u->first_name} {$u->last_name}",
                    'department' => $u->department,
                ]),
        ]);
    }

    /**
     * Update the role-targeting fields on an existing user : job_title (drives
     * credential targeting) + supervisor_user_id (drives reminder escalation).
     * Both fields are nullable but constrained to valid catalog entries.
     */
    public function updateRoleAssignment(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->effectiveTenantId();
        abort_if($user->tenant_id !== $tenantId, 403, 'Access denied');

        $validated = $request->validate([
            'job_title' => ['nullable', 'string', 'max:60',
                \Illuminate\Validation\Rule::exists('emr_job_titles', 'code')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->whereNull('deleted_at'))],
            'supervisor_user_id' => ['nullable', 'integer',
                \Illuminate\Validation\Rule::exists('shared_users', 'id')
                    ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('is_active', true))],
        ]);

        // Block self-supervision (would create infinite escalation loops).
        if (($validated['supervisor_user_id'] ?? null) === $user->id) {
            return response()->json(['message' => 'A user cannot supervise themselves.'], 422);
        }

        // E2 : detect cyclic supervisor chains beyond direct self-reference.
        // Walk up the chain from the proposed supervisor : if we ever encounter
        // $user->id, this assignment would create a cycle.
        $proposedSupId = $validated['supervisor_user_id'] ?? null;
        if ($proposedSupId) {
            $seen = [$user->id];
            $cursor = $proposedSupId;
            $depth = 0;
            while ($cursor && $depth < 25) {
                if (in_array($cursor, $seen, true)) {
                    return response()->json([
                        'message' => 'This assignment would create a cyclic supervisor chain. Pick a different supervisor.',
                    ], 422);
                }
                $seen[] = $cursor;
                $cursor = User::where('id', $cursor)->value('supervisor_user_id');
                $depth++;
            }
        }

        $old = $user->only('job_title', 'supervisor_user_id');
        $user->update($validated);

        AuditLog::record(
            action:       'it_admin.user.role_assignment_updated',
            resourceType: 'User',
            resourceId:   $user->id,
            tenantId:     $tenantId,
            userId:       $request->user()->id,
            oldValues:    $old,
            newValues:    $validated,
        );

        return response()->json([
            'user' => $user->only('id', 'first_name', 'last_name', 'department', 'job_title', 'supervisor_user_id'),
        ]);
    }

    /** Delete all database sessions for a user, forcing immediate logout. */
    private function invalidateSessions(int $userId): void
    {
        DB::table('sessions')->where('user_id', $userId)->delete();
    }

    /** All routes in this controller require department='it_admin'. */
    private function requireItAdmin(Request $request): void
    {
        abort_if($request->user()->department !== 'it_admin', 403, 'IT Admin access required');
    }
}

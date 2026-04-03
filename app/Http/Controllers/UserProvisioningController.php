<?php

// ─── UserProvisioningController ───────────────────────────────────────────────
// IT Admin panel: user account lifecycle management.
// IT Admin is the only role that can create, deactivate, or modify user accounts —
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
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserProvisioningController extends Controller
{
    /**
     * Render the user management page with all users for this tenant.
     */
    public function users(Request $request): InertiaResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $users = User::where('tenant_id', $tenantId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'department', 'is_active', 'created_at', 'designations']);

        return Inertia::render('ItAdmin/Users', [
            'users'             => $users,
            'designationLabels' => User::DESIGNATION_LABELS,
        ]);
    }

    /**
     * Create a new user account and send a welcome email with login instructions.
     * Returns the created user as JSON with HTTP 201.
     */
    public function provisionUser(Request $request): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['required', 'string', 'max:100'],
            'email'      => ['required', 'email', 'unique:shared_users,email'],
            'department' => ['required', 'string', 'in:primary_care,therapies,social_work,behavioral_health,dietary,activities,home_care,transportation,pharmacy,idt,enrollment,finance,qa_compliance,it_admin'],
            'role'       => ['required', 'string', 'in:admin,standard'],
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
        $tenantId = $request->user()->tenant_id;

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
        $tenantId = $request->user()->tenant_id;

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
        $tenantId = $request->user()->tenant_id;

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
     * alerting only — they do not affect RBAC access or navigation.
     */
    public function updateDesignations(Request $request, User $user): JsonResponse
    {
        $this->requireItAdmin($request);
        $tenantId = $request->user()->tenant_id;

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

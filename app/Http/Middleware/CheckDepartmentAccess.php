<?php

// ─── CheckDepartmentAccess ────────────────────────────────────────────────────
// Route middleware that enforces Role-Based Access Control (RBAC) for staff.
//
// Every protected route declares a `module` (e.g. 'clinical_notes'). This
// middleware looks up the authenticated user's department + role in the
// RolePermission matrix and either passes the request through or returns 403.
// Unauthorized attempts are written to AuditLog for compliance review.
//
// Notable rules:
//  - 42 CFR §460.91 requires PACE (Programs of All-Inclusive Care for the
//    Elderly) staff access be limited by job function — this is the gate.
//  - Nostos super-admin department bypasses all checks (platform staff).
//  - When a role-level super-admin is impersonating a user, the impersonated
//    user's department/role permissions are enforced (the audit row still
//    records the real super-admin's user_id for non-repudiation).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\RolePermission;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CheckDepartmentAccess
{
    /**
     * Check that the authenticated user has access to the requested module.
     *
     * Routes protected by this middleware should have a 'module' parameter,
     * e.g.:  Route::get('/clinical/notes', ...)->middleware('department.access:clinical_notes')
     *
     * Impersonation behaviour:
     *   - Super-admin NOT impersonating → bypass all checks (full access, unchanged).
     *   - Super-admin impersonating a user → enforce that user's dept/role permissions.
     *     (Audit log still uses the real super-admin's user ID, not the impersonated user.)
     */
    public function handle(Request $request, Closure $next, string $module = ''): Response
    {
        $user = Auth::user();

        if (! $user) {
            return redirect()->route('login');
        }

        $impersonation = app(ImpersonationService::class);

        // Nostos SA dept (department='super_admin') always bypasses RBAC — no impersonation system.
        // This is distinct from role='super_admin' which has the impersonation UI.
        if ($user->isDeptSuperAdmin()) {
            return $next($request);
        }

        if ($user->isSuperAdmin()) {
            // Not impersonating: unrestricted access to everything
            if (! $impersonation->isImpersonating()) {
                return $next($request);
            }

            // Impersonating: apply the impersonated user's permissions
            $effective = $impersonation->getImpersonatedUser();

            if ($effective && $module && ! RolePermission::check($effective->department, $effective->role, $module, 'can_view')) {
                // Audit log uses real super-admin's ID (not the impersonated user's)
                AuditLog::record(
                    action:      'unauthorized_access',
                    tenantId:    $user->tenant_id,
                    userId:      $user->id,
                    description: "Impersonation: {$effective->department}/{$effective->role} cannot access module: {$module}",
                );

                if ($request->wantsJson() || $request->header('X-Inertia')) {
                    return response()->json(['message' => 'Access denied.'], 403);
                }

                return Inertia::render('Errors/403', [
                    'module' => $module,
                ])->toResponse($request)->setStatusCode(403);
            }

            return $next($request);
        }

        // Regular users: standard RBAC check
        if ($module && ! RolePermission::check($user->department, $user->role, $module, 'can_view')) {
            AuditLog::record(
                action:      'unauthorized_access',
                tenantId:    $user->tenant_id,
                userId:      $user->id,
                description: "Unauthorized access attempt to module: {$module}",
            );

            if ($request->wantsJson() || $request->header('X-Inertia')) {
                return response()->json(['message' => 'Access denied.'], 403);
            }

            return Inertia::render('Errors/403', [
                'module' => $module,
            ])->toResponse($request)->setStatusCode(403);
        }

        return $next($request);
    }
}

<?php

// ─── TenantContextController ─────────────────────────────────────────────────
// Tenant context switcher for super-admins (role='super_admin' or dept='super_admin').
//
// Lets a super-admin act inside another organisation's data scope without
// logging out / impersonating. Mirrors SiteContextController : a session key
// (`active_tenant_id`) overrides the user's home tenant_id for any controller
// that calls Auth::user()->effectiveTenantId(). Audit-log / security paths
// that read $user->tenant_id directly still see the SA's HOME tenant, which
// is the correct behaviour for an honest audit trail.
//
// When a SA switches tenant, the session's `active_site_id` is also cleared
// so the existing site switcher won't keep them pinned to a site that
// doesn't belong to the new tenant.
//
// Routes:
//   POST   /tenant-context/switch  : set active_tenant_id (JSON)
//   DELETE /tenant-context         : clear active_tenant_id, revert to home (JSON)
//
// Access control: super_admin role OR super_admin dept only. Everyone else 403.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantContextController extends Controller
{
    /**
     * Switch the session's active tenant context. Super-admin only.
     */
    public function switch(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user->isSuperAdmin() && ! $user->isDeptSuperAdmin()) {
            return response()->json(['message' => 'Tenant switching is not available for your role.'], 403);
        }

        $data = $request->validate([
            'tenant_id' => 'required|integer|exists:shared_tenants,id',
        ]);

        $tenant = Tenant::findOrFail($data['tenant_id']);

        session(['active_tenant_id' => $tenant->id]);
        // Drop site context, it would dangle on the wrong tenant otherwise.
        session()->forget('active_site_id');

        AuditLog::record(
            action:       'tenant_context.switched',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'tenant',
            resourceId:   $tenant->id,
            description:  "Super-admin switched tenant context to {$tenant->name} (home tenant: {$user->tenant_id}).",
        );

        return response()->json([
            'tenant' => [
                'id'   => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
            ],
        ]);
    }

    /**
     * Clear active tenant context, reverting to the SA's own home tenant.
     */
    public function clear(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->isSuperAdmin() && ! $user->isDeptSuperAdmin()) {
            return response()->json(['message' => 'Tenant switching is not available for your role.'], 403);
        }

        $previous = session('active_tenant_id');
        session()->forget('active_tenant_id');
        session()->forget('active_site_id');

        if ($previous) {
            AuditLog::record(
                action:       'tenant_context.cleared',
                tenantId:     $user->tenant_id,
                userId:       $user->id,
                resourceType: 'tenant',
                resourceId:   (int) $previous,
                description:  "Super-admin cleared tenant context (home tenant: {$user->tenant_id}).",
            );
        }

        return response()->json(['tenant' => null]);
    }
}

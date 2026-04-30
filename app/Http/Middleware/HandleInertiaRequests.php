<?php

// ─── HandleInertiaRequests ────────────────────────────────────────────────────
// Inertia.js bridge middleware (Laravel/Inertia plumbing).
//
// On every page request this builds a "shared props" payload that is injected
// into the Vue frontend so client-side components can render without extra
// API calls. Included: authenticated user, tenant, active site, permission
// map, nav groups, impersonation banner state, flash success/error messages,
// and Ziggy route table.
//
// Notable rules:
//  - When a super-admin is impersonating, `auth.user` is the IMPERSONATED user
//    (so the UI renders their department/role) while `auth.real_user` carries
//    the super-admin identity for the header banner and audit context.
//  - Theme preference always follows the real user, not the impersonated one.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Middleware;

use App\Models\Site;
use App\Models\Tenant;
use App\Services\ImpersonationService;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Shared data available in every Inertia page component.
     *
     * Impersonation: when a super-admin is impersonating a user, `auth.user` reflects
     * the IMPERSONATED user (so frontend renders their dept/role/permissions).
     * `auth.real_user` always carries the super-admin's identity for header display.
     * `impersonation.active` lets AppShell know to show the amber banner.
     */
    public function share(Request $request): array
    {
        $realUser          = Auth::user();
        $permissionService = app(PermissionService::class);
        $impersonation     = app(ImpersonationService::class);

        // Resolve effective user: impersonated user if active, otherwise real user
        $impersonatedUser  = $realUser?->isSuperAdmin() ? $impersonation->getImpersonatedUser() : null;
        $effectiveUser     = $impersonatedUser ?? $realUser;

        // Build auth.user data from the effective user (impersonated user's context for UI)
        $authUser = $effectiveUser ? [
            'id'               => $effectiveUser->id,
            'first_name'       => $effectiveUser->first_name,
            'last_name'        => $effectiveUser->last_name,
            'email'            => $effectiveUser->email,
            'department'       => $effectiveUser->department,
            'department_label' => $effectiveUser->departmentLabel(),
            'role'             => $effectiveUser->role,
            'is_admin'         => $effectiveUser->isAdmin(),
            // is_super_admin: always false for the effective user when impersonating
            // (so impersonated user doesn't accidentally see super-admin controls)
            'is_super_admin'   => $impersonatedUser ? false : ($realUser?->isSuperAdmin() ?? false),
            // Theme preference belongs to the REAL user, not the impersonated user.
            // This keeps the SA's display mode consistent during impersonation sessions.
            'theme_preference' => $realUser?->theme_preference ?? 'light',
            // D4 : count of credentials expiring within 30 days for the user
            // dropdown badge. Lazy closure so we don't query on every request
            // unless the front-end uses it.
            'credentials_expiring_30d' => fn () => \App\Models\StaffCredential::where('user_id', $effectiveUser->id)
                ->whereNull('deleted_at')
                ->whereNull('replaced_by_credential_id')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now()->addDays(30)->toDateString())
                ->where('expires_at', '>=', now()->toDateString())
                ->count(),
            'has_direct_reports' => fn () => \App\Models\User::where('supervisor_user_id', $effectiveUser->id)
                ->where('is_active', true)->exists(),
            'tenant'           => $effectiveUser->tenant ? [
                'id'                  => $effectiveUser->tenant->id,
                'name'                => $effectiveUser->tenant->name,
                'transport_mode'      => $effectiveUser->tenant->transport_mode,
                'auto_logout_minutes' => $effectiveUser->tenant->auto_logout_minutes,
            ] : null,
            'site' => $effectiveUser->site ? [
                'id'   => $effectiveUser->site->id,
                'name' => $effectiveUser->site->name,
            ] : null,
        ] : null;

        // real_user: always the authenticated super-admin (used to show SA controls in header)
        $realUserData = ($realUser && $realUser->isSuperAdmin() && $impersonatedUser) ? [
            'id'             => $realUser->id,
            'first_name'     => $realUser->first_name,
            'last_name'      => $realUser->last_name,
            'is_super_admin' => true,
        ] : null;

        // Permissions and nav use the effective user (impersonated dept/role when active)
        $permissions = $effectiveUser ? $permissionService->permissionMap($effectiveUser) : [];
        $navGroups   = $effectiveUser ? $permissionService->navGroups($effectiveUser) : [];

        // Impersonation state for AppShell UI (banner, dropdowns)
        $impersonationState = [
            'active'          => (bool) $impersonatedUser,
            'user'            => $impersonatedUser ? [
                'id'               => $impersonatedUser->id,
                'first_name'       => $impersonatedUser->first_name,
                'last_name'        => $impersonatedUser->last_name,
                'department'       => $impersonatedUser->department,
                'department_label' => $impersonatedUser->departmentLabel(),
                'role'             => $impersonatedUser->role,
            ] : null,
            // viewing_as_dept: dashboard context when NOT impersonating a specific user
            'viewing_as_dept' => ($realUser?->isSuperAdmin() && ! $impersonatedUser)
                ? ($impersonation->hasViewAs() ? $impersonation->getViewAsDepartment() : null)
                : null,
        ];

        // ── Site context: active site for executive + SA dept users (Phase 10B) ──
        // Regular users: always their own site. Executive/SA: session-selected site.
        $canSwitchSite = $realUser && (
            $realUser->isSuperAdmin() || $realUser->isDeptSuperAdmin() || $realUser->isExecutive()
        );
        $activeSiteId   = $canSwitchSite ? (session('active_site_id') ?? $realUser?->site_id) : $realUser?->site_id;
        $activeSite     = $activeSiteId ? Site::find($activeSiteId) : null;
        $siteContext    = $activeSite ? ['id' => $activeSite->id, 'name' => $activeSite->name] : null;

        // Available sites for the site switcher dropdown (own tenant for executive; all for SA)
        $availableSites = [];
        if ($realUser && $canSwitchSite) {
            $query = Site::where('is_active', true)->orderBy('name');
            if ($realUser->isExecutive()) {
                // Executives can only see their own tenant's sites
                $query->where('tenant_id', $realUser->tenant_id);
            } elseif ($realUser->isSuperAdmin() || $realUser->isDeptSuperAdmin()) {
                // SAs : narrow to the tenant they're currently acting inside,
                // so the site dropdown doesn't dangle on a foreign tenant.
                $effectiveTenantId = session('active_tenant_id') ?? $realUser->tenant_id;
                if ($effectiveTenantId) {
                    $query->where('tenant_id', $effectiveTenantId);
                }
            }
            $availableSites = $query->get(['id', 'name'])->toArray();
        }

        // ── Tenant context: super-admin only (role or dept). ─────────────────────
        // Mirrors site_context. session('active_tenant_id') is the override; the
        // SA's home tenant is the fallback. available_tenants populates the header
        // dropdown — only super-admins ever see it.
        $canSwitchTenant = $realUser && ($realUser->isSuperAdmin() || $realUser->isDeptSuperAdmin());
        $activeTenantId  = $canSwitchTenant ? (session('active_tenant_id') ?? $realUser?->tenant_id) : $realUser?->tenant_id;
        $activeTenant    = $activeTenantId ? Tenant::find($activeTenantId) : null;
        $tenantContext   = ($canSwitchTenant && $activeTenant) ? [
            'id'   => $activeTenant->id,
            'name' => $activeTenant->name,
            'slug' => $activeTenant->slug,
            'is_home' => $activeTenant->id === $realUser->tenant_id,
        ] : null;
        $availableTenants = $canSwitchTenant
            ? Tenant::where('is_active', true)->orderBy('name')->get(['id', 'name', 'slug'])->toArray()
            : [];

        return array_merge(parent::share($request), [
            'auth' => [
                'user'      => $authUser,
                'real_user' => $realUserData, // non-null only when super-admin is impersonating
            ],
            'permissions'    => $permissions,
            'nav_groups'     => $navGroups,
            'impersonation'  => $impersonationState,
            // Phase 10B: site context switcher for executive + SA dept users
            'site_context'   => $siteContext,
            'available_sites' => $availableSites,
            // Super-admin tenant switcher (mirror of site_context).
            'tenant_context'   => $tenantContext,
            'available_tenants' => $availableTenants,
            'flash' => [
                'success' => $request->session()->get('success'),
                'error'   => $request->session()->get('error'),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
        ]);
    }
}

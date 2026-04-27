<?php

// ─── OrgSettingsController ───────────────────────────────────────────────────
// Renders + saves the executive-level Org Settings page where PACE
// organizations toggle OPTIONAL notification + workflow preferences. The full
// preference catalog and routing model is documented in
// docs/internal/org-settings-design.md and the
// NotificationPreferenceService class.
//
// Auth gate: super_admin role OR department=executive AND role=admin.
// Tenant-scoped: every request reads/writes only the calling user's tenant.
//
// Routes (defined under the auth middleware group in routes/web.php):
//   GET  /executive/org-settings   → index() — Inertia render
//   POST /executive/org-settings   → update() — bulk save
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Services\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class OrgSettingsController extends Controller
{
    /** Auth gate. */
    private function requireExecutiveAccess(Request $request): void
    {
        $u = $request->user();
        abort_if(! $u, 401);
        $allowed = $u->isSuperAdmin()
            || ($u->department === 'executive' && $u->role === 'admin');
        abort_unless($allowed, 403, 'Org Settings is restricted to executive leadership.');
    }

    public function index(Request $request): InertiaResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);
        $effective = $svc->effectiveSettingsForTenant($tenantId);

        // Group by the catalog `group` field for UI rendering.
        $grouped = [];
        foreach ($effective as $key => $entry) {
            $g = $entry['group'];
            if (! isset($grouped[$g])) {
                $grouped[$g] = [];
            }
            $grouped[$g][] = array_merge(['key' => $key], $entry);
        }

        return Inertia::render('Executive/OrgSettings', [
            'grouped'    => $grouped,
            'tenantName' => $request->user()->tenant?->name,
            'updatedAt'  => now()->toIso8601String(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;

        // Accept a flat map of {preference_key: enabled} pairs. Unknown or
        // Required keys are silently ignored by the service.
        $validated = $request->validate([
            'preferences'   => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);
        $changed = $svc->bulkSet($tenantId, $validated['preferences'], $request->user()->id);

        return response()->json([
            'changed' => $changed,
            'message' => $changed === 0
                ? 'No changes (everything was already in the requested state).'
                : "Saved {$changed} preference change" . ($changed === 1 ? '' : 's') . '.',
        ]);
    }
}

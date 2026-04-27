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
// OS3 — Per-site override capability:
//   The page is tabbed. The default "Org Defaults" tab edits the tenant-wide
//   row (site_id NULL). Optional per-site tabs edit site-specific overrides.
//   Save bodies include an explicit `site_id` (null or numeric); the service
//   routes the bulkSet to the right cascade level.
//
// Routes:
//   GET    /executive/org-settings                                 → index()
//   GET    /executive/org-settings/site/{site}                     → siteEffective()
//   POST   /executive/org-settings                                 → update()
//   DELETE /executive/org-settings/site/{site}/key/{key}           → clearOverride()
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Site;
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

    /** Build the grouped catalog payload for one cascade level (org or site). */
    private function groupedFor(int $tenantId, ?int $siteId, NotificationPreferenceService $svc): array
    {
        $effective = $svc->effectiveSettingsForTenant($tenantId, $siteId);

        $grouped = [];
        foreach ($effective as $key => $entry) {
            $g = $entry['group'];
            if (! isset($grouped[$g])) $grouped[$g] = [];
            $grouped[$g][] = array_merge(['key' => $key], $entry);
        }
        return $grouped;
    }

    public function index(Request $request): InertiaResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);

        // All sites in the tenant (drives the "Add site override" picker).
        $sites = Site::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'address', 'city', 'state']);

        // Per-site override counts so the picker cards can show "3 overrides"
        // chips. Computed once at index time; cheap (one query per tenant).
        $overrideCounts = \App\Models\NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('site_id')
            ->selectRaw('site_id, COUNT(*) as cnt')
            ->groupBy('site_id')
            ->pluck('cnt', 'site_id')
            ->map(fn ($c) => (int) $c)
            ->toArray();

        // Decorate sites with override count for the picker
        $sites = $sites->map(fn ($s) => [
            'id'             => $s->id,
            'name'           => $s->name,
            'address'        => $s->address,
            'city'           => $s->city,
            'state'          => $s->state,
            'override_count' => $overrideCounts[$s->id] ?? 0,
        ]);

        // Sites that already have at least one override row — these get tabs.
        $sitesWithOverrides = array_keys($overrideCounts);

        return Inertia::render('Executive/OrgSettings', [
            'orgGrouped'         => $this->groupedFor($tenantId, null, $svc),
            'sites'              => $sites,
            'sitesWithOverrides' => $sitesWithOverrides,
            'tenantName'         => $request->user()->tenant?->name,
            'updatedAt'          => now()->toIso8601String(),
        ]);
    }

    /** JSON: effective state for a specific site tab (lazy-loaded by the UI). */
    public function siteEffective(Request $request, Site $site): JsonResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;
        abort_if($site->tenant_id !== $tenantId, 403);

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);
        return response()->json([
            'siteId'   => $site->id,
            'siteName' => $site->name,
            'grouped'  => $this->groupedFor($tenantId, $site->id, $svc),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;

        // Accept site_id (null = org-level) + flat map of {key: bool|{enabled,value}}.
        $validated = $request->validate([
            'site_id'       => ['nullable', 'integer', 'exists:shared_sites,id'],
            'preferences'   => ['required', 'array'],
            // Each entry can be a bool or an object {enabled, value}; the service
            // normalizes both shapes and ignores unknown keys.
            'preferences.*' => ['nullable'],
        ]);

        if (! empty($validated['site_id'])) {
            $site = Site::find($validated['site_id']);
            abort_if(! $site || $site->tenant_id !== $tenantId, 403);
        }

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);
        $changed = $svc->bulkSet(
            $tenantId,
            $validated['preferences'],
            $request->user()->id,
            $validated['site_id'] ?? null,
        );

        return response()->json([
            'changed' => $changed,
            'message' => $changed === 0
                ? 'No changes (everything was already in the requested state).'
                : "Saved {$changed} preference change" . ($changed === 1 ? '' : 's') . '.',
        ]);
    }

    /** Remove a single site-level override row so it falls back to inheriting org. */
    public function clearOverride(Request $request, Site $site, string $key): JsonResponse
    {
        $this->requireExecutiveAccess($request);
        $tenantId = $request->user()->tenant_id;
        abort_if($site->tenant_id !== $tenantId, 403);

        /** @var NotificationPreferenceService $svc */
        $svc = app(NotificationPreferenceService::class);
        $cleared = $svc->clearSiteOverride($tenantId, $site->id, $key, $request->user()->id);

        return response()->json(['cleared' => $cleared]);
    }
}

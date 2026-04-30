<?php

// ─── LocationController ───────────────────────────────────────────────────────
// Manages external service locations used in appointment scheduling.
//
// Routes (all behind auth middleware):
//   GET    /locations           → index()   JSON: all locations for tenant
//   POST   /locations           → store()   Create new location
//   GET    /locations/{id}      → show()    JSON: single location
//   PUT    /locations/{id}      → update()  Update location details
//   DELETE /locations/{id}      → destroy() Soft-delete (deactivate)
//
// Permission model:
//   Write access (create/update/delete) restricted to Transportation Team.
//   Read access (index/show) available to all authenticated users in tenant.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\AuditLog;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class LocationController extends Controller
{
    /**
     * GET /admin/locations  (Inertia page)
     * Management UI: lists all locations (active + inactive) for the tenant.
     * Transportation team sees write actions; all other depts see read-only.
     */
    public function managePage(Request $request): InertiaResponse
    {
        $user      = $request->user();
        $locations = Location::forTenant($user->effectiveTenantId())
            ->withTrashed()   // show archived so admins can see full history
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (Location $l) => [
                'id'            => $l->id,
                'name'          => $l->name,
                'label'         => $l->label,
                'location_type' => $l->location_type,
                'type_label'    => $l->typeLabel(),
                'site_id'       => $l->site_id,
                'street'        => $l->street,
                'apartment'     => $l->apartment,
                'suite'         => $l->suite,
                'building'      => $l->building,
                'floor'         => $l->floor,
                'unit'          => $l->unit,
                'city'          => $l->city,
                'state'         => $l->state,
                'zip'           => $l->zip,
                'phone'         => $l->phone,
                'contact_name'  => $l->contact_name,
                'notes'         => $l->notes,
                'access_notes'  => $l->access_notes,
                'is_active'     => $l->is_active,
                'deleted_at'    => $l->deleted_at?->toIso8601String(),
            ]);

        $sites = \App\Models\Site::where('tenant_id', $user->effectiveTenantId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Locations/Index', [
            'locations'      => $locations,
            'location_types' => Location::TYPE_LABELS,
            'sites'          => $sites,
            // Any authenticated user can create + update locations (e.g. home
            // care staff adding a new patient's apartment). Deactivation is
            // transport-only (see `can_deactivate`).
            'can_write'      => true,
            'can_deactivate' => $user->department === 'transportation' || $user->isSuperAdmin() || $user->isDeptSuperAdmin(),
        ]);
    }

    /**
     * GET /locations
     * Returns all active locations for the current tenant.
     * Optionally filtered by location_type.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Location::forTenant($user->effectiveTenantId())
            ->orderBy('name');

        if ($type = $request->input('type')) {
            $query->ofType($type);
        }

        // Include inactive only if explicitly requested (admin view)
        if (! $request->boolean('include_inactive')) {
            $query->active();
        }

        return response()->json($query->get());
    }

    /**
     * POST /locations
     * Creates a new location. Open to any authenticated user (home care staff,
     * primary care, etc. all add addresses). Only deactivation is restricted.
     */
    public function store(StoreLocationRequest $request): JsonResponse
    {
        $user = $request->user();

        $location = Location::create(array_merge($request->validated(), [
            'tenant_id' => $user->effectiveTenantId(),
            'is_active' => $request->boolean('is_active', true),
        ]));

        AuditLog::record(
            action:       'location.created',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'location',
            resourceId:   $location->id,
            description:  "Location '{$location->name}' ({$location->location_type}) created",
            newValues:    $request->validated(),
        );

        return response()->json($location, 201);
    }

    /**
     * GET /locations/{location}
     * Returns a single location with appointment count for the past 30 days.
     */
    public function show(Request $request, Location $location): JsonResponse
    {
        $user = $request->user();
        abort_if($location->tenant_id !== $user->effectiveTenantId(), 403);

        return response()->json($location);
    }

    /**
     * PUT /locations/{location}
     * Updates location details. Open to any authenticated user.
     */
    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        $user = $request->user();
        abort_if($location->tenant_id !== $user->effectiveTenantId(), 403);

        $old = $location->only(array_keys($request->validated()));
        $location->update($request->validated());

        AuditLog::record(
            action:       'location.updated',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'location',
            resourceId:   $location->id,
            description:  "Location '{$location->name}' updated",
            oldValues:    $old,
            newValues:    $request->validated(),
        );

        return response()->json($location->fresh());
    }

    /**
     * DELETE /locations/{location}
     * Soft-deletes (deactivates) a location. Transportation Team only : other
     * staff can add addresses but should not be able to remove records they
     * didn't originate. Past appointments referencing this location are preserved.
     */
    public function destroy(Request $request, Location $location): JsonResponse
    {
        $user = $request->user();
        abort_if($location->tenant_id !== $user->effectiveTenantId(), 403);
        $this->authorizeDeactivate($user);

        $location->delete();

        AuditLog::record(
            action:       'location.deleted',
            tenantId:     $user->tenant_id,
            userId:       $user->id,
            resourceType: 'location',
            resourceId:   $location->id,
            description:  "Location '{$location->name}' soft-deleted",
        );

        return response()->json(['message' => 'Location deactivated.']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Deactivating a location is restricted to the Transportation Team (plus
     * super-admins). Other staff can create + update, but not deactivate.
     */
    private function authorizeDeactivate($user): void
    {
        abort_unless(
            $user->department === 'transportation'
                || $user->isSuperAdmin()
                || $user->isDeptSuperAdmin(),
            403,
            'Only the Transportation Team can deactivate locations.'
        );
    }
}

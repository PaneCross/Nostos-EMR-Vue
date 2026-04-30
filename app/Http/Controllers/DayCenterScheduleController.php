<?php

// ─── DayCenterScheduleController ──────────────────────────────────────────────
// Admin bulk management for participant day-center recurring schedules.
//
// Routes:
//   GET  /scheduling/day-center/manage : Inertia page listing all enrolled
//        participants with their day_center_days, filterable by site, inline
//        edit pills.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DayCenterScheduleController extends Controller
{
    /**
     * GET /scheduling/day-center/manage
     * List all enrolled participants with their day_center_days for bulk editing.
     * Only activities/it_admin/super_admin can access.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless(
            in_array($user->department, ['activities', 'it_admin', 'super_admin'])
            || $user->role === 'super_admin',
            403,
            'Only activities staff can manage day-center schedules.'
        );

        $siteFilter = $request->query('site_id');

        $query = Participant::where('tenant_id', $user->effectiveTenantId())
            ->where('enrollment_status', 'enrolled')
            ->where('is_active', true)
            ->with('site:id,name')
            ->orderBy('last_name');

        if ($siteFilter) {
            $query->where('site_id', (int) $siteFilter);
        }

        $participants = $query->get()->map(fn (Participant $p) => [
            'id'              => $p->id,
            'mrn'             => $p->mrn,
            'name'            => "{$p->last_name}, {$p->first_name}",
            'preferred_name'  => $p->preferred_name,
            'site_id'         => $p->site_id,
            'site_name'       => $p->site?->name,
            'day_center_days' => is_array($p->day_center_days) ? $p->day_center_days : [],
        ]);

        $sites = Site::where('tenant_id', $user->effectiveTenantId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Scheduling/DayCenterSchedule', [
            'participants' => $participants,
            'sites'        => $sites,
            'selectedSite' => $siteFilter ? (int) $siteFilter : null,
        ]);
    }

    /**
     * POST /scheduling/day-center/manage/bulk
     * Apply many day_center_days changes in one request.
     * Body: { updates: [{ participant_id: int, day_center_days: string[] | null }, ...] }
     * Returns: { updated: int, failed: [{ participant_id, reason }] }
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            in_array($user->department, ['activities', 'it_admin', 'super_admin'])
            || $user->role === 'super_admin',
            403,
            'Only activities staff can manage day-center schedules.'
        );

        $validated = $request->validate([
            'updates'                     => ['required', 'array', 'min:1', 'max:500'],
            'updates.*.participant_id'    => ['required', 'integer', 'exists:emr_participants,id'],
            'updates.*.day_center_days'   => ['nullable', 'array'],
            'updates.*.day_center_days.*' => ['string', 'in:mon,tue,wed,thu,fri,sat,sun'],
        ]);

        $updated = 0;
        $failed  = [];

        foreach ($validated['updates'] as $u) {
            $p = Participant::where('id', $u['participant_id'])
                ->where('tenant_id', $user->effectiveTenantId())
                ->first();

            if (! $p) {
                $failed[] = ['participant_id' => $u['participant_id'], 'reason' => 'not_found_or_cross_tenant'];
                continue;
            }

            $old = $p->day_center_days;
            $new = $u['day_center_days'] ?? null;

            try {
                $p->day_center_days = (is_array($new) && count($new) > 0) ? $new : null;
                $p->save();

                AuditLog::record(
                    action:       'participant.day_center_schedule_updated',
                    tenantId:     $user->tenant_id,
                    userId:       $user->id,
                    resourceType: 'participant',
                    resourceId:   $p->id,
                    description:  "Day center schedule updated for {$p->mrn}",
                    oldValues:    ['day_center_days' => $old],
                    newValues:    ['day_center_days' => $p->day_center_days],
                );

                $updated++;
            } catch (\Throwable $e) {
                $failed[] = ['participant_id' => $p->id, 'reason' => $e->getMessage()];
            }
        }

        return response()->json([
            'updated' => $updated,
            'failed'  => $failed,
        ]);
    }
}

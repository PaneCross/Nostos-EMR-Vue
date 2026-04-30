<?php

// ─── PanelController ─────────────────────────────────────────────────────────
// Phase D1. PCP caseload views + reassignment.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PanelController extends Controller
{
    private function gate(): void
    {
        $u = Auth::user();
        abort_if(!$u, 401);
        abort_unless($u->isSuperAdmin() || in_array($u->department, [
            'primary_care', 'qa_compliance', 'executive', 'it_admin',
        ], true), 403);
    }

    /**
     * "My panel" view for the logged-in PCP.
     * GET /panel/my
     */
    public function mine(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $participants = Participant::where('tenant_id', $u->effectiveTenantId())
            ->where('primary_care_user_id', $u->id)
            ->where('enrollment_status', 'enrolled')
            ->select(['id','mrn','first_name','last_name','dob','gender','site_id'])
            ->get();

        // Overdue visits: no visit in 90 days. Visits proxied by ClinicalNote count.
        $overdueCount = 0;
        foreach ($participants as $p) {
            $hasRecent = \App\Models\ClinicalNote::where('participant_id', $p->id)
                ->where('visit_date', '>=', now()->subDays(90)->toDateString())
                ->exists();
            if (! $hasRecent) $overdueCount++;
        }

        // Recent hospitalizations (last 90 days).
        $participantIds = $participants->pluck('id');
        $hospCount = \App\Models\Incident::whereIn('participant_id', $participantIds)
            ->where('incident_type', 'hospitalization')
            ->where('occurred_at', '>=', now()->subDays(90))->count();

        return response()->json([
            'panel_size'              => $participants->count(),
            'overdue_visit_count'     => $overdueCount,
            'recent_hospitalizations' => $hospCount,
            'participants'            => $participants,
        ]);
    }

    /**
     * QAPI panel-size widget : panel sizes across all PCPs in tenant.
     * GET /panel/sizes
     */
    public function sizes(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $rows = DB::table('emr_participants')
            ->join('shared_users', 'shared_users.id', '=', 'emr_participants.primary_care_user_id')
            ->where('emr_participants.tenant_id', $u->effectiveTenantId())
            ->where('emr_participants.enrollment_status', 'enrolled')
            ->groupBy('shared_users.id', 'shared_users.first_name', 'shared_users.last_name')
            ->select([
                'shared_users.id as pcp_id',
                'shared_users.first_name',
                'shared_users.last_name',
                DB::raw('count(*) as panel_size'),
            ])
            ->orderByDesc('panel_size')
            ->get();

        return response()->json(['rows' => $rows]);
    }

    /**
     * Assign PCP to a single participant.
     * POST /panel/assign
     */
    public function assign(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'participant_id' => 'required|integer|exists:emr_participants,id',
            'pcp_user_id'    => 'nullable|integer|exists:shared_users,id',
        ]);

        $participant = Participant::findOrFail($validated['participant_id']);
        abort_if($participant->tenant_id !== $u->effectiveTenantId(), 403);

        if (! empty($validated['pcp_user_id'])) {
            $pcp = User::find($validated['pcp_user_id']);
            abort_if(! $pcp || $pcp->tenant_id !== $u->effectiveTenantId(), 422);
        }

        $oldPcp = $participant->primary_care_user_id;
        $participant->update(['primary_care_user_id' => $validated['pcp_user_id'] ?? null]);

        AuditLog::record(
            action: 'panel.assigned',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'participant',
            resourceId: $participant->id,
            description: "Panel assignment changed: PCP {$oldPcp} → {$participant->primary_care_user_id}.",
        );

        return response()->json(['participant' => $participant->fresh()]);
    }

    /**
     * Bulk transfer all participants from one PCP to another.
     * POST /panel/transfer
     */
    public function transfer(Request $request): JsonResponse
    {
        $this->gate();
        $u = Auth::user();

        $validated = $request->validate([
            'from_pcp_user_id' => 'required|integer|exists:shared_users,id',
            'to_pcp_user_id'   => 'required|integer|exists:shared_users,id|different:from_pcp_user_id',
        ]);

        $from = User::findOrFail($validated['from_pcp_user_id']);
        $to   = User::findOrFail($validated['to_pcp_user_id']);
        abort_if($from->tenant_id !== $u->effectiveTenantId() || $to->tenant_id !== $u->effectiveTenantId(), 403);

        $count = Participant::where('tenant_id', $u->effectiveTenantId())
            ->where('primary_care_user_id', $from->id)
            ->where('enrollment_status', 'enrolled')
            ->update(['primary_care_user_id' => $to->id]);

        AuditLog::record(
            action: 'panel.transferred',
            tenantId: $u->tenant_id,
            userId: $u->id,
            resourceType: 'user',
            resourceId: $to->id,
            description: "Panel transfer: {$count} participants moved from PCP #{$from->id} to PCP #{$to->id}.",
        );

        return response()->json([
            'transferred'       => $count,
            'from_pcp_user_id'  => $from->id,
            'to_pcp_user_id'    => $to->id,
        ]);
    }
}

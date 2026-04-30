<?php

// ─── DietaryDashboardController ───────────────────────────────────────────────
// JSON widget endpoints for the Dietary / Nutrition dashboard.
// All endpoints are tenant-scoped and require the dietary department
// (or super_admin).
//
// Routes (GET, all under /dashboards/dietary/):
//   assessments   : Nutritional assessments overdue or due within 14 days
//   goals         : Active dietary care plan goals
//   restrictions  : Allergy and dietary restriction summary across participants
//   sdrs          : Open/overdue SDRs assigned to dietary
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Allergy;
use App\Models\Assessment;
use App\Models\CarePlanGoal;
use App\Models\Sdr;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DietaryDashboardController extends Controller
{
    // ── Department guard ──────────────────────────────────────────────────────

    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'dietary') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * Nutritional assessments that are overdue or due within 14 days.
     */
    public function assessments(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        $overdue = Assessment::where('tenant_id', $tenantId)
            ->forType('nutritional')
            ->overdue()
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('next_due_date', 'asc')
            ->limit(10)
            ->get();

        $dueSoon = Assessment::where('tenant_id', $tenantId)
            ->forType('nutritional')
            ->dueSoon(14)
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('next_due_date', 'asc')
            ->limit(10)
            ->get();

        $map = fn (Assessment $a) => [
            'id'            => $a->id,
            'participant'   => $a->participant ? [
                'id'   => $a->participant->id,
                'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
            ] : null,
            'score'         => $a->score,
            'next_due_date' => $a->next_due_date?->toDateString(),
            'days_overdue'  => $a->next_due_date
                ? abs((int) now()->diffInDays($a->next_due_date))
                : null,
            'href'          => $a->participant
                ? "/participants/{$a->participant->id}?tab=assessments"
                : '/participants',
        ];

        return response()->json([
            'overdue'        => $overdue->map($map),
            'due_soon'       => $dueSoon->map($map),
            'overdue_count'  => Assessment::where('tenant_id', $tenantId)->forType('nutritional')->overdue()->count(),
            'due_soon_count' => Assessment::where('tenant_id', $tenantId)->forType('nutritional')->dueSoon(14)->count(),
        ]);
    }

    /**
     * Active dietary care plan goals across the tenant.
     */
    public function goals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        $goals = CarePlanGoal::whereHas('carePlan', fn ($q) => $q
                ->where('tenant_id', $tenantId)
                ->where('status', '!=', 'archived')
            )
            ->where('domain', 'dietary')
            ->active()
            ->with(['carePlan.participant:id,first_name,last_name'])
            ->orderBy('target_date', 'asc')
            ->limit(20)
            ->get()
            ->map(fn (CarePlanGoal $g) => [
                'id'               => $g->id,
                'goal_description' => $g->goal_description,
                'target_date'      => $g->target_date?->toDateString(),
                'status'           => $g->status,
                'participant'      => $g->carePlan?->participant ? [
                    'id'   => $g->carePlan->participant->id,
                    'name' => $g->carePlan->participant->first_name . ' ' . $g->carePlan->participant->last_name,
                ] : null,
                'href'             => $g->carePlan?->participant?->id
                    ? "/participants/{$g->carePlan->participant->id}?tab=careplan"
                    : '/participants',
            ]);

        return response()->json(['goals' => $goals]);
    }

    /**
     * Allergy and dietary restriction summary.
     * Counts by allergy_type and lists life-threatening food allergies.
     * Dietary staff need this for meal planning and food service safety.
     */
    public function restrictions(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        // Count by allergy type across all tenant participants
        $counts = DB::table('emr_allergies')
            ->join('emr_participants', 'emr_allergies.participant_id', '=', 'emr_participants.id')
            ->where('emr_participants.tenant_id', $tenantId)
            ->whereNull('emr_allergies.deleted_at')
            ->select('emr_allergies.allergy_type', DB::raw('count(*) as total'))
            ->groupBy('emr_allergies.allergy_type')
            ->get()
            ->pluck('total', 'allergy_type')
            ->toArray();

        // Life-threatening food allergies that affect meal service
        $criticalFoodAllergies = Allergy::whereHas(
                'participant',
                fn ($q) => $q->where('tenant_id', $tenantId)
            )
            ->where('allergy_type', 'food')
            ->where('severity', 'life_threatening')
            ->with(['participant:id,first_name,last_name'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(fn ($a) => [
                'id'           => $a->id,
                'allergen'     => $a->allergen_name,
                'reaction'     => $a->reaction_description,
                'participant'  => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'href'         => $a->participant
                    ? "/participants/{$a->participant->id}"
                    : '/participants',
            ]);

        return response()->json([
            'counts_by_type'          => $counts,
            'critical_food_allergies' => $criticalFoodAllergies,
        ]);
    }

    /**
     * Open and overdue SDRs assigned to the dietary department.
     */
    public function sdrs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        $sdrs = Sdr::where('tenant_id', $tenantId)
            ->forDepartment('dietary')
            ->open()
            ->with(['participant:id,first_name,last_name'])
            ->orderByRaw('due_at ASC')
            ->limit(15)
            ->get()
            ->map(fn (Sdr $s) => [
                'id'              => $s->id,
                'participant'     => $s->participant ? [
                    'id'   => $s->participant->id,
                    'name' => $s->participant->first_name . ' ' . $s->participant->last_name,
                ] : null,
                'request_type'    => $s->request_type,
                'type_label'      => $s->typeLabel(),
                'priority'        => $s->priority,
                'status'          => $s->status,
                'is_overdue'      => $s->isOverdue(),
                'hours_remaining' => round($s->hoursRemaining(), 1),
                'due_at'          => $s->due_at?->toDateTimeString(),
                'href'            => '/sdrs',
            ]);

        return response()->json([
            'sdrs'          => $sdrs,
            'overdue_count' => Sdr::where('tenant_id', $tenantId)->forDepartment('dietary')->overdue()->count(),
            'open_count'    => Sdr::where('tenant_id', $tenantId)->forDepartment('dietary')->open()->count(),
        ]);
    }

    /**
     * Phase I7 : Active dietary orders grouped by diet type.
     */
    public function ordersByDietType(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        $rows = \App\Models\DietaryOrder::forTenant($tenantId)->active()
            ->selectRaw('diet_type, COUNT(*) AS count')
            ->groupBy('diet_type')
            ->orderByDesc('count')
            ->get();
        return response()->json([
            'rows'  => $rows,
            'total' => (int) $rows->sum('count'),
            'href'  => '/dietary/roster',
        ]);
    }

    /**
     * Phase I7 : IADL food-prep impaired → dietary consult candidates.
     */
    public function iadlFoodPrepCandidates(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->effectiveTenantId();

        $latest = \Illuminate\Support\Facades\DB::table('emr_iadl_records as i1')
            ->select('i1.*')
            ->whereRaw('i1.recorded_at = (
                SELECT MAX(i2.recorded_at)
                FROM emr_iadl_records i2
                WHERE i2.participant_id = i1.participant_id
            )')
            ->where('i1.tenant_id', $tenantId)
            ->where('i1.food_preparation', 0)
            ->orderByDesc('i1.recorded_at')
            ->limit(20)
            ->get();

        $rows = collect($latest)->map(function ($r) {
            $p = \App\Models\Participant::find($r->participant_id);
            return [
                'id' => $r->id,
                'participant' => $p ? [
                    'id' => $p->id,
                    'name' => $p->first_name . ' ' . $p->last_name,
                    'mrn' => $p->mrn,
                ] : null,
                'recorded_at' => $r->recorded_at,
                'interpretation' => $r->interpretation ?? null,
                'href' => $p ? "/participants/{$p->id}?tab=assessments" : null,
            ];
        });
        return response()->json(['rows' => $rows->values(), 'total' => $rows->count()]);
    }
}

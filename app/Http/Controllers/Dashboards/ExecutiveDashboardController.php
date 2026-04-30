<?php

// ─── ExecutiveDashboardController ────────────────────────────────────────────
// Phase 10B : JSON widget endpoints for the Executive dashboard.
//
// All 4 endpoints return pure JSON (not Inertia). They are loaded in parallel
// by ExecutiveDashboard.tsx via Promise.all on component mount.
//
// Access control:
//   - executive department: own tenant data only
//   - role='super_admin' or department='super_admin': all tenants (cross-tenant)
//
// Routes (all GET, under /dashboards/executive/):
//   GET /dashboards/executive/org-overview      : org-wide participant + enrollment stats
//   GET /dashboards/executive/site-comparison   : per-site participant + care-plan counts
//   GET /dashboards/executive/financial-overview : capitation totals per site
//   GET /dashboards/executive/sites-list        : all tenant sites for the site switcher
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Models\CapitationRecord;
use App\Models\Participant;
use App\Models\Referral;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExecutiveDashboardController
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Restrict to same tenant unless user is a global admin (role or dept SA). */
    private function tenantId(): int
    {
        return Auth::user()->effectiveTenantId();
    }

    private function requireAccess(): void
    {
        $user = Auth::user();
        $allowed = $user->isExecutive() || $user->isSuperAdmin() || $user->isDeptSuperAdmin();
        if (! $allowed) {
            abort(403, 'Executive dashboard access required.');
        }
    }

    // ── Widgets ───────────────────────────────────────────────────────────────

    /**
     * Org overview: total enrolled participants, pending enrollment, recent discharges.
     * Scoped to the user's tenant (executives) or all tenants (SA).
     */
    public function orgOverview(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();

        $enrolled = Participant::where('tenant_id', $tenantId)
            ->where('enrollment_status', 'enrolled')
            ->count();

        $pending = Referral::where('tenant_id', $tenantId)
            ->whereIn('status', ['intake_scheduled', 'intake_in_progress', 'intake_complete',
                'eligibility_pending', 'pending_enrollment'])
            ->count();

        $recentReferrals = Referral::where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $sites = Site::where('tenant_id', $tenantId)->where('is_active', true)->count();

        return response()->json([
            'enrolled'          => $enrolled,
            'pending_enrollment' => $pending,
            'new_referrals_30d' => $recentReferrals,
            'active_sites'      => $sites,
        ]);
    }

    /**
     * Site comparison: per-site participant count, enrollment ratio, and open care plans.
     */
    public function siteComparison(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();

        $sites = Site::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get(['id', 'name']);

        $data = $sites->map(function (Site $site) {
            $enrolled = Participant::where('site_id', $site->id)
                ->where('enrollment_status', 'enrolled')
                ->count();

            // Care plans don't carry site_id directly — they're scoped to the
            // participant. Join through emr_participants to count per-site
            // active care plans (excluding archived).
            $carePlans = DB::table('emr_care_plans as cp')
                ->join('emr_participants as p', 'p.id', '=', 'cp.participant_id')
                ->where('p.site_id', $site->id)
                ->whereNull('p.deleted_at')
                ->whereIn('cp.status', ['draft', 'under_review', 'active'])
                ->count();

            return [
                'site_id'        => $site->id,
                'site_name'      => $site->name,
                'enrolled'       => $enrolled,
                'active_care_plans' => $carePlans,
                'href'           => '/participants',
            ];
        });

        return response()->json(['sites' => $data]);
    }

    /**
     * Financial overview: current month capitation totals per site.
     */
    public function financialOverview(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();
        $monthYear = now()->format('Y-m');

        // Capitation totals by participant's site
        $records = DB::table('emr_capitation_records as cr')
            ->join('emr_participants as p', 'p.id', '=', 'cr.participant_id')
            ->join('shared_sites as s', 's.id', '=', 'p.site_id')
            ->where('cr.tenant_id', $tenantId)
            ->where('cr.month_year', $monthYear)
            ->whereNull('cr.deleted_at')
            ->groupBy('s.id', 's.name')
            ->selectRaw('s.id as site_id, s.name as site_name, COUNT(*) as participant_count, SUM(cr.total_capitation) as total_capitation')
            ->get();

        $grandTotal = $records->sum('total_capitation');

        return response()->json([
            'month_year'  => $monthYear,
            'grand_total' => round((float) $grandTotal, 2),
            'by_site'     => $records->map(fn ($r) => [
                'site_id'           => $r->site_id,
                'site_name'         => $r->site_name,
                'participant_count' => $r->participant_count,
                'total_capitation'  => round((float) $r->total_capitation, 2),
                'href'              => '/finance/capitation',
            ]),
        ]);
    }

    /**
     * Sites list: all tenant sites for the site-switcher cards widget.
     * Returns participant counts and whether a site is the current active site.
     */
    public function sitesList(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();
        $activeSiteId = session('active_site_id') ?? Auth::user()->site_id;

        $sites = Site::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $data = $sites->map(function (Site $site) use ($activeSiteId) {
            $enrolled = Participant::where('site_id', $site->id)
                ->where('enrollment_status', 'enrolled')
                ->count();

            return [
                'id'        => $site->id,
                'name'      => $site->name,
                'city'      => $site->city,
                'state'     => $site->state,
                'enrolled'  => $enrolled,
                'is_active' => $site->id === $activeSiteId,
                'href'      => '/participants',
            ];
        });

        return response()->json(['sites' => $data]);
    }

    /**
     * Department compliance roll-up : 8 org-wide KPI counts plus a per-department
     * row of operational backlog (overdue SDRs, unsigned notes, overdue
     * assessments, pending orders, STAT orders). Powers the bottom half of
     * the executive dashboard — the Org Compliance Overview KPI strip and
     * the Department Operations table.
     *
     * Score band per department :
     *   critical : any overdue SDR or any STAT order outstanding
     *   warning  : unsigned_notes > 3 OR any overdue assessment OR pending_orders > 5
     *   good     : otherwise
     */
    public function deptCompliance(): JsonResponse
    {
        $this->requireAccess();
        $tenantId = $this->tenantId();

        $now           = now();
        $startOfMonth  = $now->copy()->startOfMonth();

        // ── Org-wide totals (KPI strip) ────────────────────────────────────────
        $overdueSdrs = DB::table('emr_sdrs')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'cancelled', 'denied'])
            ->where('due_at', '<', $now)
            ->count();

        $unsignedNotes = DB::table('emr_clinical_notes')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'draft')
            ->whereNull('signed_at')
            ->count();

        $openIncidents = DB::table('emr_incidents')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['open', 'under_review', 'rca_in_progress'])
            ->count();

        $overdueCarePlans = DB::table('emr_care_plans')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereNotNull('review_due_date')
            ->where('review_due_date', '<', $now->toDateString())
            ->count();

        // IDT reviews not yet held for participants flagged in a past meeting.
        $overdueIdtReviews = DB::table('emr_idt_participant_reviews as r')
            ->join('emr_idt_meetings as m', 'm.id', '=', 'r.meeting_id')
            ->where('m.tenant_id', $tenantId)
            ->whereNull('r.reviewed_at')
            ->where('m.meeting_date', '<', $now->toDateString())
            ->count();

        $criticalWounds = DB::table('emr_wound_records')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'deteriorating')
            ->count();

        // Hospital discharges (proxy for hospitalizations this month — we record
        // the discharge back to PACE, not the admission itself).
        $hospitalizationsThisMonth = DB::table('emr_discharge_events')
            ->where('tenant_id', $tenantId)
            ->where('discharged_on', '>=', $startOfMonth->toDateString())
            ->count();

        $unackedInteractions = DB::table('emr_drug_interaction_alerts')
            ->where('tenant_id', $tenantId)
            ->where('is_acknowledged', false)
            ->whereIn('severity', ['contraindicated', 'major'])
            ->count();

        // ── Per-department roll-up ─────────────────────────────────────────────
        $deptList = [
            'primary_care'      => 'Primary Care / Nursing',
            'therapies'         => 'Therapies (PT/OT/ST)',
            'social_work'       => 'Social Work',
            'behavioral_health' => 'Behavioral Health',
            'dietary'           => 'Dietary / Nutrition',
            'activities'        => 'Activities / Recreation',
            'home_care'         => 'Home Care',
            'transportation'    => 'Transportation',
            'pharmacy'          => 'Pharmacy',
            'idt'               => 'IDT / Care Coordination',
            'enrollment'        => 'Enrollment / Intake',
            'finance'           => 'Finance / Billing',
            'qa_compliance'     => 'QA / Compliance',
            'it_admin'          => 'IT / Administration',
        ];

        // Single grouped queries — much cheaper than 14 × 5 round-trips.
        $sdrByDept = DB::table('emr_sdrs')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['completed', 'cancelled', 'denied'])
            ->where('due_at', '<', $now)
            ->select('assigned_department', DB::raw('count(*) as c'))
            ->groupBy('assigned_department')
            ->pluck('c', 'assigned_department');

        $notesByDept = DB::table('emr_clinical_notes')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', 'draft')
            ->whereNull('signed_at')
            ->select('department', DB::raw('count(*) as c'))
            ->groupBy('department')
            ->pluck('c', 'department');

        $assessmentByDept = DB::table('emr_assessments')
            ->where('tenant_id', $tenantId)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', $now->toDateString())
            ->select('department', DB::raw('count(*) as c'))
            ->groupBy('department')
            ->pluck('c', 'department');

        $pendingOrdersByDept = DB::table('emr_clinical_orders')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->select('target_department', DB::raw('count(*) as c'))
            ->groupBy('target_department')
            ->pluck('c', 'target_department');

        $statOrdersByDept = DB::table('emr_clinical_orders')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'acknowledged'])
            ->where('priority', 'stat')
            ->select('target_department', DB::raw('count(*) as c'))
            ->groupBy('target_department')
            ->pluck('c', 'target_department');

        $departments = [];
        foreach ($deptList as $key => $label) {
            $row = [
                'department'          => $key,
                'label'               => $label,
                'overdue_sdrs'        => (int) ($sdrByDept[$key] ?? 0),
                'unsigned_notes'      => (int) ($notesByDept[$key] ?? 0),
                'overdue_assessments' => (int) ($assessmentByDept[$key] ?? 0),
                'pending_orders'      => (int) ($pendingOrdersByDept[$key] ?? 0),
                'stat_orders'         => (int) ($statOrdersByDept[$key] ?? 0),
            ];

            // Score band — keep in sync with method docblock if the rules change.
            if ($row['overdue_sdrs'] > 0 || $row['stat_orders'] > 0) {
                $row['score'] = 'critical';
            } elseif ($row['unsigned_notes'] > 3 || $row['overdue_assessments'] > 0 || $row['pending_orders'] > 5) {
                $row['score'] = 'warning';
            } else {
                $row['score'] = 'good';
            }
            $departments[] = $row;
        }

        return response()->json([
            'org_totals' => [
                'overdue_sdrs'              => $overdueSdrs,
                'unsigned_notes'            => $unsignedNotes,
                'open_incidents'            => $openIncidents,
                'overdue_care_plans'        => $overdueCarePlans,
                'overdue_idt_reviews'       => $overdueIdtReviews,
                'critical_wounds'           => $criticalWounds,
                'hospitalizations_this_month' => $hospitalizationsThisMonth,
                'unacked_interactions'      => $unackedInteractions,
            ],
            'departments' => $departments,
        ]);
    }
}

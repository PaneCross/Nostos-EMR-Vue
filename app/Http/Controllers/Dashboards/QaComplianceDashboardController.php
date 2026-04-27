<?php

// ─── QaComplianceDashboardController ──────────────────────────────────────────
// JSON widget endpoints for the QA / Compliance department live dashboard.
// Delegates to QaMetricsService for all KPI computations.
// All endpoints require qa_compliance department (or super_admin).
// This is distinct from QaDashboardController (which serves the full Inertia
// QA dashboard at /qa/dashboard with tabs and CSV export).
//
// Routes (GET, all under /dashboards/qa-compliance/):
//   metrics     : all 6 QaMetricsService KPIs in a single response
//   incidents   : open incidents list (non-closed, ordered by severity)
//   docs        : unsigned notes >24h + overdue assessments summary
//   care-plans  : overdue care plans list
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers\Dashboards;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\Incident;
use App\Models\WoundRecord;
use App\Services\QaMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class QaComplianceDashboardController extends Controller
{
    public function __construct(private readonly QaMetricsService $qaMetrics) {}

    // ── Department guard ──────────────────────────────────────────────────────

    /** Abort 403 if the authenticated user is not qa_compliance or super_admin. */
    private function requireDept(): void
    {
        $user = Auth::user();
        if (! $user->isSuperAdmin() && $user->department !== 'qa_compliance') {
            abort(403);
        }
    }

    // ── Widget endpoints ──────────────────────────────────────────────────────

    /**
     * All 6 QA KPI metrics in a single widget response.
     * Delegates to QaMetricsService for consistency with the full QA dashboard.
     * Used to populate the KPI card row on the dept dashboard landing page.
     */
    public function metrics(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        return response()->json([
            'sdr_compliance_rate'                    => $this->qaMetrics->getSdrComplianceRate($tenantId),
            'overdue_assessments_count'              => $this->qaMetrics->getOverdueAssessments($tenantId)->count(),
            'unsigned_notes_count'                   => $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)->count(),
            'open_incidents_count'                   => $this->qaMetrics->getOpenIncidents($tenantId)->count(),
            'overdue_care_plans_count'               => $this->qaMetrics->getCarePlansOverdue($tenantId)->count(),
            'hospitalizations_count'                 => $this->qaMetrics->getHospitalizationsThisMonth($tenantId),
            // W5-1: CMS QAPI pressure injury tracking (Stage 3+, unstageable, DTI = reportable wounds)
            'active_pressure_injuries_stage3_plus'   => WoundRecord::forTenant($tenantId)->open()->criticalStage()->count(),
        ]);
    }

    /**
     * Open incidents (all non-closed statuses) ordered by severity.
     * Returns up to 15 incidents for the QA dashboard incident queue widget.
     */
    public function incidents(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $incidents = Incident::forTenant($tenantId)
            ->open()
            ->with(['participant:id,first_name,last_name,mrn', 'reportedBy:id,first_name,last_name'])
            ->orderByRaw("CASE status
                WHEN 'rca_in_progress' THEN 0
                WHEN 'under_review' THEN 1
                ELSE 2 END")
            ->orderBy('occurred_at', 'desc')
            ->limit(15)
            ->get()
            ->map(fn (Incident $i) => [
                'id'              => $i->id,
                'participant'     => $i->participant ? [
                    'id'   => $i->participant->id,
                    'name' => $i->participant->first_name . ' ' . $i->participant->last_name,
                    'mrn'  => $i->participant->mrn,
                ] : null,
                'incident_type'   => $i->incident_type,
                'status'          => $i->status,
                'rca_required'    => $i->rca_required,
                'rca_completed'   => $i->rca_completed,
                'occurred_at'     => $i->occurred_at?->toDateString(),
                'reported_by'     => $i->reportedBy
                    ? $i->reportedBy->first_name . ' ' . $i->reportedBy->last_name
                    : null,
                'href'            => '/qa/dashboard',
            ]);

        return response()->json([
            'incidents'         => $incidents,
            'open_count'        => Incident::forTenant($tenantId)->open()->count(),
            'rca_pending_count' => Incident::forTenant($tenantId)
                ->open()
                ->where('rca_required', true)
                ->where('rca_completed', false)
                ->count(),
        ]);
    }

    /**
     * Documentation compliance summary:
     *   - Unsigned clinical notes older than 24 hours (by department)
     *   - Overdue assessments (past next_due_date)
     * Quick-link to the full compliance detail on /qa/dashboard.
     */
    public function docs(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $unsignedNotes = $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)
            ->take(10)
            ->map(fn (ClinicalNote $n) => [
                'id'          => $n->id,
                'participant' => $n->participant ? [
                    'id'   => $n->participant->id,
                    'name' => $n->participant->first_name . ' ' . $n->participant->last_name,
                ] : null,
                'department'  => $n->department,
                'note_type'   => $n->note_type,
                'hours_old'   => abs((int) now()->diffInHours($n->created_at)),
                'href'        => $n->participant
                    ? "/participants/{$n->participant->id}?tab=chart"
                    : '/clinical/notes',
            ]);

        // Group unsigned notes by department for QA team follow-up
        $byDepartment = $unsignedNotes->groupBy('department')
            ->map(fn ($items) => $items->count());

        $overdueAssessments = $this->qaMetrics->getOverdueAssessments($tenantId)
            ->take(10)
            ->map(fn (Assessment $a) => [
                'id'              => $a->id,
                'participant'     => $a->participant ? [
                    'id'   => $a->participant->id,
                    'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                ] : null,
                'assessment_type' => $a->assessment_type,
                'next_due_date'   => $a->next_due_date?->toDateString(),
                'days_overdue'    => abs((int) now()->diffInDays($a->next_due_date)),
                'href'            => $a->participant
                    ? "/participants/{$a->participant->id}?tab=assessments"
                    : '/participants',
            ]);

        return response()->json([
            'unsigned_notes'       => $unsignedNotes->values(),
            'unsigned_count'       => $this->qaMetrics->getUnsignedNotesOlderThan($tenantId)->count(),
            'notes_by_department'  => $byDepartment,
            'overdue_assessments'  => $overdueAssessments->values(),
            'overdue_assess_count' => $this->qaMetrics->getOverdueAssessments($tenantId)->count(),
        ]);
    }

    /**
     * Overdue care plans (review_due_date in the past, status not archived).
     * QA monitors these for CMS compliance : care plans must be reviewed per schedule.
     */
    public function carePlans(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $plans = $this->qaMetrics->getCarePlansOverdue($tenantId)
            ->take(15)
            ->map(fn (CarePlan $p) => [
                'id'              => $p->id,
                'participant'     => $p->participant ? [
                    'id'   => $p->participant->id,
                    'name' => $p->participant->first_name . ' ' . $p->participant->last_name,
                    'mrn'  => $p->participant->mrn,
                ] : null,
                'status'          => $p->status,
                'review_due_date' => $p->review_due_date?->toDateString(),
                'days_overdue'    => abs((int) now()->diffInDays($p->review_due_date)),
                'href'            => $p->participant
                    ? "/participants/{$p->participant->id}?tab=careplan"
                    : '/participants',
            ]);

        return response()->json([
            'care_plans'    => $plans->values(),
            'overdue_count' => $this->qaMetrics->getCarePlansOverdue($tenantId)->count(),
        ]);
    }

    /**
     * Phase 1 (MVP roadmap). Open §460.122 appeals sorted by decision-window
     * consumption (most-aged first). Drives the qa_compliance.appeals widget.
     */
    public function appeals(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $appeals = \App\Models\Appeal::forTenant($tenantId)
            ->open()
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('internal_decision_due_at')
            ->take(15)
            ->get()
            ->map(function (\App\Models\Appeal $a) {
                return [
                    'id'              => $a->id,
                    'participant'     => $a->participant ? [
                        'id'   => $a->participant->id,
                        'name' => $a->participant->first_name . ' ' . $a->participant->last_name,
                        'mrn'  => $a->participant->mrn,
                    ] : null,
                    'type'            => $a->type,
                    'status'          => $a->status,
                    'due_at'          => $a->internal_decision_due_at?->toIso8601String(),
                    'window_pct'      => $a->windowElapsedPercent(),
                    'overdue'         => $a->isOverdue(),
                    'continuation_of_benefits' => $a->continuation_of_benefits,
                    'href'            => "/appeals/{$a->id}",
                ];
            });

        return response()->json([
            'appeals'       => $appeals->values(),
            'open_count'    => \App\Models\Appeal::forTenant($tenantId)->open()->count(),
            'overdue_count' => \App\Models\Appeal::forTenant($tenantId)->overdue()->count(),
        ]);
    }

    /**
     * Phase I7 : Sentinel events rollup (last 30 days). Lists classified
     * sentinel incidents with CMS-5d / RCA-30d deadline status.
     */
    public function sentinelRollup(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $rows = \App\Models\Incident::forTenant($tenantId)
            ->where('is_sentinel', true)
            ->where('sentinel_classified_at', '>=', now()->subDays(30))
            ->with('participant:id,mrn,first_name,last_name')
            ->orderByDesc('sentinel_classified_at')
            ->limit(20)
            ->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'participant' => $i->participant ? [
                    'id' => $i->participant->id,
                    'name' => $i->participant->first_name . ' ' . $i->participant->last_name,
                    'mrn' => $i->participant->mrn,
                ] : null,
                'classified_at' => $i->sentinel_classified_at?->toIso8601String(),
                'cms_deadline' => $i->sentinel_cms_5day_deadline?->toIso8601String(),
                'cms_overdue' => $i->sentinel_cms_5day_deadline && $i->sentinel_cms_5day_deadline->isPast(),
                'rca_deadline' => $i->sentinel_rca_30day_deadline?->toIso8601String(),
                'rca_overdue' => $i->sentinel_rca_30day_deadline && $i->sentinel_rca_30day_deadline->isPast(),
                'href' => "/compliance/sentinel-events",
            ]);
        return response()->json(['rows' => $rows, 'total' => $rows->count()]);
    }

    /**
     * Phase I7 : Critical-value escalations still pending (overdue).
     */
    public function criticalValuesPending(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $pending = \App\Models\CriticalValueAcknowledgment::forTenant($tenantId)->pending()
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('deadline_at')
            ->limit(20)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'participant' => $c->participant ? [
                    'id' => $c->participant->id,
                    'name' => $c->participant->first_name . ' ' . $c->participant->last_name,
                    'mrn' => $c->participant->mrn,
                ] : null,
                'field_name' => $c->field_name,
                'value' => $c->value,
                'severity' => $c->severity,
                'deadline_at' => $c->deadline_at?->toIso8601String(),
                'overdue' => $c->deadline_at && $c->deadline_at->isPast(),
                'href' => $c->participant_id ? "/participants/{$c->participant_id}" : null,
            ]);
        return response()->json(['rows' => $pending, 'total' => $pending->count()]);
    }

    /**
     * Phase I7 : ROI requests due within 5 days (or overdue).
     */
    public function roiDueSoon(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $rows = \App\Models\RoiRequest::forTenant($tenantId)->open()
            ->where('due_by', '<=', now()->addDays(5))
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('due_by')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'participant' => $r->participant ? [
                    'id' => $r->participant->id,
                    'name' => $r->participant->first_name . ' ' . $r->participant->last_name,
                    'mrn' => $r->participant->mrn,
                ] : null,
                'due_by' => $r->due_by?->toIso8601String(),
                'days_remaining' => $r->daysRemaining(),
                'overdue' => $r->isOverdue(),
                'status' => $r->status,
                'href' => '/compliance/roi',
            ]);
        return response()->json(['rows' => $rows, 'total' => $rows->count()]);
    }

    /**
     * Phase I7 : TB screening overdue count (annual §460.71).
     */
    public function tbOverdue(): JsonResponse
    {
        $this->requireDept();
        $tenantId = Auth::user()->tenant_id;

        $overdueCount = \App\Models\TbScreening::forTenant($tenantId)
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '<', now()->toDateString())
            ->count();
        $dueSoonCount = \App\Models\TbScreening::forTenant($tenantId)
            ->whereNotNull('next_due_date')
            ->whereBetween('next_due_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
            ->count();

        return response()->json([
            'overdue_count'   => $overdueCount,
            'due_soon_count'  => $dueSoonCount,
            'href'            => '/compliance/tb-screening',
        ]);
    }
}

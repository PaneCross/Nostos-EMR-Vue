<?php

// ─── ComplianceController ─────────────────────────────────────────────────────
// Audit-pull endpoints. These are flat JSON universes CMS auditors (or state
// surveyors) expect to produce on demand.
//
// PLAIN-ENGLISH PURPOSE: When CMS shows up to audit our PACE program, they
// hand us a list and say "give me every grievance for the last 12 months in
// this format." These endpoints are the canned exports that satisfy those
// asks. The endpoints are intentionally flat (one row per record), unpaginated,
// and tenant-scoped : auditors paste them into their workpapers.
//
// Acronym glossary used in this file:
//   PACE   = Programs of All-Inclusive Care for the Elderly.
//   CMS    = Centers for Medicare & Medicaid Services (federal regulator).
//   NF-LOC = Nursing-Facility Level of Care : the state-administered clinical
//            determination that someone is sick enough to qualify for a nursing
//            home. PACE eligibility requires NF-LOC. Must be re-certified annually
//            per 42 CFR §460.160(b)(2).
//   SDR    = Service Delivery Request (an internal handoff between PACE depts).
//   ROI    = Release of Information (HIPAA right-to-access requests).
//   ADE    = Adverse Drug Event (a harmful medication reaction).
//   QAPI   = Quality Assurance / Performance Improvement (the PACE quality program).
//
// Each endpoint:
//   - requires auth + qa_compliance / enrollment / it_admin / super_admin
//   - scopes to tenant_id
//   - returns unpaginated flat rows (auditor imports into their workpapers)
//   - is safe to hit repeatedly
//
// Built in Phase 2 (MVP roadmap). Will gain more endpoints in future phases
// (SDR SLA, Level I/II reporting, appeals universe, etc.).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AdverseDrugEvent;
use App\Models\Appeal;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\RoiRequest;
use App\Models\TbScreening;
use App\Models\Sdr;
use App\Models\ServiceDenialNotice;
use App\Models\StaffCredential;
use App\Models\StaffTrainingRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ComplianceController extends Controller
{
    /** Shared gate for audit-pull endpoints. */
    private function gate(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(
            $user->isSuperAdmin()
                || in_array($user->department, ['qa_compliance', 'enrollment', 'it_admin'], true),
            403,
            'Access to audit-pull endpoints restricted to compliance / enrollment / IT admin.'
        );
    }

    /**
     * NF-LOC recertification status for every enrolled participant.
     * §460.160(b)(2). Returns JSON or an Inertia page listing.
     */
    public function nfLocStatus(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $rows = Participant::forTenant($tenantId)
            ->where('enrollment_status', 'enrolled')
            ->orderBy('nf_certification_expires_at')
            ->get()
            ->map(function (Participant $p) {
                $days = $p->nfLocRecertDaysRemaining();
                return [
                    'id'                     => $p->id,
                    'mrn'                    => $p->mrn,
                    'name'                   => $p->fullName(),
                    'nursing_facility_eligible' => (bool) $p->nursing_facility_eligible,
                    'nf_certification_date'  => $p->nf_certification_date?->toDateString(),
                    'nf_expires_at'          => $p->nf_certification_expires_at?->toDateString(),
                    'days_remaining'         => $days,
                    'status'                 => $this->statusFor($p, $days),
                    'recert_waived'          => (bool) $p->nf_recert_waived,
                    'recert_waived_reason'   => $p->nf_recert_waived_reason,
                    'href'                   => "/participants/{$p->id}",
                ];
            });

        $summary = [
            'count_total'    => $rows->count(),
            'count_overdue'  => $rows->where('status', 'overdue')->count(),
            'count_due_60d'  => $rows->whereIn('status', ['due_60', 'due_30', 'due_15', 'due_today', 'overdue'])->count(),
            'count_waived'   => $rows->where('recert_waived', true)->count(),
            'count_current'  => $rows->where('status', 'current')->count(),
            'count_missing'  => $rows->where('status', 'missing')->count(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $rows->values(), 'summary' => $summary]);
        }

        return Inertia::render('Compliance/NfLocStatus', [
            'rows'    => $rows->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Service Denial Notices universe : every notice issued, for §460.122 audit.
     * Phase 1 closed out denial-notice creation; this exposes the audit listing.
     */
    public function denialNotices(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $rows = ServiceDenialNotice::where('tenant_id', $tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'sdr:id,request_type,description,status',
                'issuedBy:id,first_name,last_name',
                'pdfDocument:id,file_name',
                'appeals:id,service_denial_notice_id,type,status,filed_at',
            ])
            ->orderByDesc('issued_at')
            ->get();

        return response()->json([
            'rows'  => $rows,
            'count' => $rows->count(),
        ]);
    }

    /**
     * Appeals universe : every §460.122 appeal with status, clocks, decision.
     */
    public function appeals(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $rows = Appeal::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'denialNotice:id,reason_code,issued_at',
                'decidedBy:id,first_name,last_name',
            ])
            ->orderByDesc('filed_at')
            ->get();

        return response()->json([
            'rows'          => $rows,
            'count'         => $rows->count(),
            'count_open'    => $rows->whereIn('status', Appeal::OPEN_STATUSES)->count(),
            'count_overdue' => $rows
                ->filter(fn (Appeal $a) => $a->isOverdue())
                ->count(),
        ]);
    }

    /**
     * SDR SLA universe : every SDR with type (standard/expedited), due clock,
     * and decision time. Feeds the CMS "SDDR" audit protocol.
     * Phase 2 (MVP roadmap) §460.121.
     */
    public function sdrSla(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $rows = Sdr::where('tenant_id', $tenantId)
            ->orderByDesc('submitted_at')
            ->get(['id', 'participant_id', 'request_type', 'priority', 'sdr_type',
                   'status', 'submitted_at', 'due_at', 'completed_at', 'escalated']);

        return response()->json([
            'rows'          => $rows,
            'count'         => $rows->count(),
            'count_expedited' => $rows->where('sdr_type', 'expedited')->count(),
            'count_overdue' => $rows->filter(fn (Sdr $s) => $s->isOverdue())->count(),
        ]);
    }

    /**
     * Phase 4 (MVP roadmap) §460.64-71.
     * Personnel credentials + training-hours universe for the CMS Personnel
     * Audit Protocol. Flat JSON meant for auditor workpaper import.
     */
    public function personnelCredentials(Request $request): JsonResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $credentials = StaffCredential::forTenant($tenantId)
            ->with('user:id,first_name,last_name,department,role,is_active')
            ->orderBy('expires_at')
            ->get()
            ->map(function (StaffCredential $c) {
                return [
                    'id'              => $c->id,
                    'user'            => $c->user ? [
                        'id'         => $c->user->id,
                        'name'       => $c->user->first_name . ' ' . $c->user->last_name,
                        'department' => $c->user->department,
                        'role'       => $c->user->role,
                        'is_active'  => (bool) $c->user->is_active,
                    ] : null,
                    'credential_type' => $c->credential_type,
                    'title'           => $c->title,
                    'license_state'   => $c->license_state,
                    'license_number'  => $c->license_number,
                    'issued_at'       => $c->issued_at?->toDateString(),
                    'expires_at'      => $c->expires_at?->toDateString(),
                    'days_remaining'  => $c->daysUntilExpiration(),
                    'status'          => $c->status(),
                    'verified_at'     => $c->verified_at?->toIso8601String(),
                ];
            });

        // Training hours totals per user over the past 12 months.
        $since = now()->subYear()->toDateString();
        $hoursByUser = StaffTrainingRecord::forTenant($tenantId)
            ->where('completed_at', '>=', $since)
            ->selectRaw('user_id, category, SUM(training_hours) as hrs')
            ->groupBy('user_id', 'category')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->category => (float) $r->hrs]))
            ->toArray();

        $staff = User::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('department')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'department', 'role'])
            ->map(function ($u) use ($hoursByUser) {
                $hours = $hoursByUser[$u->id] ?? [];
                return [
                    'id'            => $u->id,
                    'name'          => $u->first_name . ' ' . $u->last_name,
                    'department'    => $u->department,
                    'role'          => $u->role,
                    'training_hours_12mo_by_category' => $hours,
                    'training_hours_12mo_total'       => array_sum($hours),
                ];
            });

        $summary = [
            'credentials_total'    => $credentials->count(),
            'credentials_expired'  => $credentials->where('status', 'expired')->count(),
            'credentials_due_60'   => $credentials->whereIn('status', ['due_60', 'due_30', 'due_14', 'due_today', 'expired'])->count(),
            'active_staff_count'   => $staff->count(),
        ];

        return response()->json([
            'credentials' => $credentials,
            'staff'       => $staff,
            'summary'     => $summary,
        ]);
    }

    /**
     * Phase B1 (MVP completion roadmap) : Restraints universe.
     * 42 CFR §460 + CMS PACE Audit Protocol.
     * Last 12 months of physical + chemical restraint episodes with
     * monitoring observation count, IDT review status, and aggregate
     * summary counters. Flat JSON for surveyor workpaper import.
     */
    public function restraints(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $since = now()->subYear();
        $episodes = \App\Models\RestraintEpisode::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'initiatedBy:id,first_name,last_name,department',
                'orderedBy:id,first_name,last_name',
                'idtReviewer:id,first_name,last_name',
            ])
            ->withCount('observations')
            ->where('initiated_at', '>=', $since)
            ->orderByDesc('initiated_at')
            ->get()
            ->map(function (\App\Models\RestraintEpisode $e) {
                return [
                    'id'                  => $e->id,
                    'participant' => [
                        'id'   => $e->participant?->id,
                        'mrn'  => $e->participant?->mrn,
                        'name' => $e->participant ? ($e->participant->first_name . ' ' . $e->participant->last_name) : null,
                    ],
                    'restraint_type'      => $e->restraint_type,
                    'initiated_at'        => $e->initiated_at?->toIso8601String(),
                    'initiated_by'        => $e->initiatedBy
                        ? ($e->initiatedBy->first_name . ' ' . $e->initiatedBy->last_name) : null,
                    'ordering_provider'   => $e->orderedBy
                        ? ($e->orderedBy->first_name . ' ' . $e->orderedBy->last_name) : null,
                    'medication_text'     => $e->medication_text,
                    'reason_text'         => $e->reason_text,
                    'alternatives_tried_text' => $e->alternatives_tried_text,
                    'status'              => $e->status,
                    'discontinued_at'     => $e->discontinued_at?->toIso8601String(),
                    'discontinuation_reason' => $e->discontinuation_reason,
                    'idt_review_date'     => $e->idt_review_date?->toDateString(),
                    'idt_reviewer'        => $e->idtReviewer
                        ? ($e->idtReviewer->first_name . ' ' . $e->idtReviewer->last_name) : null,
                    'outcome_text'        => $e->outcome_text,
                    'observations_count'  => $e->observations_count ?? 0,
                    'idt_review_overdue'  => $e->idtReviewOverdue(),
                    'monitoring_overdue'  => $e->monitoringOverdue(),
                    'duration_minutes'    => $e->discontinued_at
                        ? (int) $e->initiated_at->diffInMinutes($e->discontinued_at)
                        : (int) $e->initiated_at->diffInMinutes(now()),
                ];
            });

        $summary = [
            'count_total'         => $episodes->count(),
            'count_active'        => $episodes->where('status', 'active')->count(),
            'count_physical'      => $episodes->where('restraint_type', 'physical')->count(),
            'count_chemical'      => $episodes->whereIn('restraint_type', ['chemical', 'both'])->count(),
            'count_idt_overdue'   => $episodes->where('idt_review_overdue', true)->count(),
            'count_monitoring_overdue' => $episodes->where('monitoring_overdue', true)->count(),
            'window_start'        => $since->toIso8601String(),
            'window_end'          => now()->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $episodes->values(), 'summary' => $summary]);
        }

        return \Inertia\Inertia::render('Compliance/Restraints', [
            'rows'    => $episodes->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Phase B2 (MVP completion roadmap) : Infection surveillance universe.
     * 42 CFR §460 + CMS PACE Audit Protocol + CDC LTC surveillance.
     * Last 12 months of infection cases + declared outbreaks with
     * per-organism / per-site summary counters. Flat JSON + Inertia.
     */
    public function infections(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;
        $since = now()->subYear();

        $cases = \App\Models\InfectionCase::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'site:id,name',
                'outbreak:id,organism_type,status,started_at,site_id',
                'reportedBy:id,first_name,last_name',
            ])
            ->where('onset_date', '>=', $since->toDateString())
            ->orderByDesc('onset_date')
            ->get()
            ->map(function (\App\Models\InfectionCase $c) {
                return [
                    'id'                       => $c->id,
                    'participant' => [
                        'id'   => $c->participant?->id,
                        'mrn'  => $c->participant?->mrn,
                        'name' => $c->participant ? ($c->participant->first_name . ' ' . $c->participant->last_name) : null,
                    ],
                    'site_id'                  => $c->site_id,
                    'site_name'                => $c->site?->name,
                    'organism_type'            => $c->organism_type,
                    'organism_detail'          => $c->organism_detail,
                    'onset_date'               => $c->onset_date?->toDateString(),
                    'resolution_date'          => $c->resolution_date?->toDateString(),
                    'severity'                 => $c->severity,
                    'source'                   => $c->source,
                    'hospitalization_required' => (bool) $c->hospitalization_required,
                    'isolation_started_at'     => $c->isolation_started_at?->toIso8601String(),
                    'isolation_ended_at'       => $c->isolation_ended_at?->toIso8601String(),
                    'outbreak_id'              => $c->outbreak_id,
                    'outbreak_status'          => $c->outbreak?->status,
                    'reported_by'              => $c->reportedBy
                        ? ($c->reportedBy->first_name . ' ' . $c->reportedBy->last_name) : null,
                    'href'                     => "/participants/{$c->participant_id}",
                ];
            });

        $outbreaks = \App\Models\InfectionOutbreak::forTenant($tenantId)
            ->with(['site:id,name', 'declaredBy:id,first_name,last_name'])
            ->withCount('cases')
            ->where('started_at', '>=', $since)
            ->orderByDesc('started_at')
            ->get()
            ->map(function (\App\Models\InfectionOutbreak $o) {
                return [
                    'id'                        => $o->id,
                    'site_id'                   => $o->site_id,
                    'site_name'                 => $o->site?->name,
                    'organism_type'             => $o->organism_type,
                    'organism_detail'           => $o->organism_detail,
                    'status'                    => $o->status,
                    'started_at'                => $o->started_at?->toIso8601String(),
                    'declared_ended_at'         => $o->declared_ended_at?->toIso8601String(),
                    'attack_rate_pct'           => $o->attack_rate_pct,
                    'containment_measures_text' => $o->containment_measures_text,
                    'reported_to_state_at'      => $o->reported_to_state_at?->toIso8601String(),
                    'declared_by'               => $o->declaredBy
                        ? ($o->declaredBy->first_name . ' ' . $o->declaredBy->last_name) : null,
                    'cases_count'               => $o->cases_count ?? 0,
                ];
            });

        $summary = [
            'count_cases'              => $cases->count(),
            'count_cases_hospitalized' => $cases->where('hospitalization_required', true)->count(),
            'count_cases_severe'       => $cases->whereIn('severity', ['severe', 'fatal'])->count(),
            'count_cases_unresolved'   => $cases->whereNull('resolution_date')->count(),
            'count_outbreaks'          => $outbreaks->count(),
            'count_outbreaks_active'   => $outbreaks->where('status', 'active')->count(),
            'count_outbreaks_unreported' => $outbreaks->whereNull('reported_to_state_at')->count(),
            'window_start'             => $since->toIso8601String(),
            'window_end'               => now()->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json([
                'cases'     => $cases->values(),
                'outbreaks' => $outbreaks->values(),
                'summary'   => $summary,
            ]);
        }

        return Inertia::render('Compliance/Infections', [
            'cases'     => $cases->values(),
            'outbreaks' => $outbreaks->values(),
            'summary'   => $summary,
        ]);
    }

    /**
     * Phase B3 (MVP completion roadmap) : Sentinel events universe.
     * 42 CFR §460.136. Last 12 months of sentinel-classified incidents with
     * dual-deadline status (CMS 5-day + RCA 30-day) and RCA completion tracking.
     */
    public function sentinelEvents(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;
        $since = now()->subYear();

        $rows = Incident::forTenant($tenantId)
            ->sentinels()
            ->with([
                'participant:id,mrn,first_name,last_name',
                'reportedBy:id,first_name,last_name',
                'sentinelClassifiedBy:id,first_name,last_name',
                'rcaCompletedBy:id,first_name,last_name',
            ])
            ->where('sentinel_classified_at', '>=', $since)
            ->orderByDesc('sentinel_classified_at')
            ->get()
            ->map(function (Incident $i) {
                return [
                    'id'                    => $i->id,
                    'participant' => [
                        'id'   => $i->participant?->id,
                        'mrn'  => $i->participant?->mrn,
                        'name' => $i->participant ? ($i->participant->first_name . ' ' . $i->participant->last_name) : null,
                    ],
                    'incident_type'         => $i->incident_type,
                    'incident_type_label'   => $i->typeLabel(),
                    'occurred_at'           => $i->occurred_at?->toIso8601String(),
                    'description'           => $i->description,
                    'status'                => $i->status,
                    'classified_at'         => $i->sentinel_classified_at?->toIso8601String(),
                    'classified_by'         => $i->sentinelClassifiedBy
                        ? ($i->sentinelClassifiedBy->first_name . ' ' . $i->sentinelClassifiedBy->last_name) : null,
                    'classification_reason' => $i->sentinel_classification_reason,
                    'cms_deadline'          => $i->sentinel_cms_5day_deadline?->toIso8601String(),
                    'cms_notification_sent_at' => $i->cms_notification_sent_at?->toIso8601String(),
                    'cms_deadline_missed'   => $i->isSentinelCmsDeadlineMissed(),
                    'rca_deadline'          => $i->sentinel_rca_30day_deadline?->toIso8601String(),
                    'rca_completed_at'      => $i->rca_completed_at?->toIso8601String(),
                    'rca_completed_by'      => $i->rcaCompletedBy
                        ? ($i->rcaCompletedBy->first_name . ' ' . $i->rcaCompletedBy->last_name) : null,
                    'rca_deadline_missed'   => $i->isSentinelRcaDeadlineMissed(),
                    'href'                  => "/qa/incidents/{$i->id}",
                ];
            });

        $summary = [
            'count_total'               => $rows->count(),
            'count_cms_missed'          => $rows->where('cms_deadline_missed', true)->count(),
            'count_rca_missed'          => $rows->where('rca_deadline_missed', true)->count(),
            'count_rca_pending'         => $rows->whereNull('rca_completed_at')->count(),
            'count_cms_satisfied'       => $rows->whereNotNull('cms_notification_sent_at')->count(),
            'window_start'              => $since->toIso8601String(),
            'window_end'                => now()->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $rows->values(), 'summary' => $summary]);
        }

        return Inertia::render('Compliance/SentinelEvents', [
            'rows'    => $rows->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Phase B8b : ROI requests universe (HIPAA §164.524 records-disclosure audit).
     * 12-month window with open + closed requests.
     */
    public function roi(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;
        $since = now()->subYear();

        $rows = RoiRequest::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'fulfilledBy:id,first_name,last_name',
            ])
            ->where('requested_at', '>=', $since)
            ->orderByDesc('requested_at')
            ->get()
            ->map(function (RoiRequest $r) {
                return [
                    'id'                      => $r->id,
                    'participant'             => [
                        'id'   => $r->participant?->id,
                        'mrn'  => $r->participant?->mrn,
                        'name' => $r->participant ? ($r->participant->first_name . ' ' . $r->participant->last_name) : null,
                    ],
                    'requestor_type'          => $r->requestor_type,
                    'requestor_name'          => $r->requestor_name,
                    'requestor_contact'       => $r->requestor_contact,
                    'records_requested_scope' => $r->records_requested_scope,
                    'requested_at'            => $r->requested_at?->toIso8601String(),
                    'due_by'                  => $r->due_by?->toIso8601String(),
                    'status'                  => $r->status,
                    'fulfilled_at'            => $r->fulfilled_at?->toIso8601String(),
                    'fulfilled_by'            => $r->fulfilledBy
                        ? ($r->fulfilledBy->first_name . ' ' . $r->fulfilledBy->last_name) : null,
                    'denial_reason'           => $r->denial_reason,
                    'is_overdue'              => $r->isOverdue(),
                    'days_until_due'          => $r->daysUntilDue(),
                ];
            });

        $summary = [
            'count_total'       => $rows->count(),
            'count_open'        => $rows->whereIn('status', RoiRequest::OPEN_STATUSES)->count(),
            'count_overdue'     => $rows->where('is_overdue', true)->count(),
            'count_fulfilled'   => $rows->where('status', 'fulfilled')->count(),
            'count_denied'      => $rows->where('status', 'denied')->count(),
            'window_start'      => $since->toIso8601String(),
            'window_end'        => now()->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $rows->values(), 'summary' => $summary]);
        }
        return Inertia::render('Compliance/Roi', [
            'rows'    => $rows->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Phase C2a : TB screening universe. §460.71 annual TB screening audit pull.
     * For each enrolled participant: latest screening + days to due + status band.
     */
    public function tbScreening(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;

        $participants = Participant::forTenant($tenantId)
            ->where('enrollment_status', 'enrolled')
            ->get(['id', 'mrn', 'first_name', 'last_name']);

        $rows = $participants->map(function (Participant $p) {
            $latest = TbScreening::where('participant_id', $p->id)
                ->orderByDesc('performed_date')->first();
            $days = $latest?->daysUntilDue();

            $status = match (true) {
                ! $latest       => 'missing',
                $days === null  => 'missing',
                $days < 0       => 'overdue',
                $days === 0     => 'due_today',
                $days <= 30     => 'due_30',
                $days <= 60     => 'due_60',
                default         => 'current',
            };

            return [
                'id'                => $p->id,
                'mrn'               => $p->mrn,
                'name'              => $p->first_name . ' ' . $p->last_name,
                'latest_type'       => $latest?->screening_type,
                'latest_result'     => $latest?->result,
                'performed_date'    => $latest?->performed_date?->toDateString(),
                'next_due_date'     => $latest?->next_due_date?->toDateString(),
                'days_until_due'    => $days,
                'status'            => $status,
                'href'              => "/participants/{$p->id}",
            ];
        });

        $summary = [
            'count_total'   => $rows->count(),
            'count_current' => $rows->where('status', 'current')->count(),
            'count_due_60'  => $rows->whereIn('status', ['due_60','due_30','due_today'])->count(),
            'count_overdue' => $rows->where('status', 'overdue')->count(),
            'count_missing' => $rows->where('status', 'missing')->count(),
            'count_positive'=> TbScreening::forTenant($tenantId)->where('result','positive')->count(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $rows->values(), 'summary' => $summary]);
        }
        return Inertia::render('Compliance/TbScreening', [
            'rows'    => $rows->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Phase I1 : ADE reporting universe (closes Phase C5 scope miss).
     * 12-month Adverse Drug Event pull with MedWatch reporting status.
     */
    public function ade(Request $request): JsonResponse|InertiaResponse
    {
        $this->gate($request);
        $tenantId = $request->user()->tenant_id;
        $since = now()->subYear();

        $rows = AdverseDrugEvent::forTenant($tenantId)
            ->with([
                'participant:id,mrn,first_name,last_name',
                'medication:id,drug_name',
                'reporter:id,first_name,last_name',
            ])
            ->where('onset_date', '>=', $since->toDateString())
            ->orderByDesc('onset_date')
            ->get()
            ->map(function (AdverseDrugEvent $a) {
                return [
                    'id'                      => $a->id,
                    'participant'             => [
                        'id'   => $a->participant?->id,
                        'mrn'  => $a->participant?->mrn,
                        'name' => $a->participant ? ($a->participant->first_name . ' ' . $a->participant->last_name) : null,
                    ],
                    'medication'              => $a->medication?->drug_name,
                    'onset_date'              => $a->onset_date?->toDateString(),
                    'severity'                => $a->severity,
                    'causality'               => $a->causality,
                    'reaction_description'    => $a->reaction_description,
                    'reporter'                => $a->reporter
                        ? ($a->reporter->first_name . ' ' . $a->reporter->last_name) : null,
                    'reported_to_medwatch_at' => $a->reported_to_medwatch_at?->toIso8601String(),
                    'medwatch_tracking_number'=> $a->medwatch_tracking_number,
                    'auto_allergy_created'    => (bool) $a->auto_allergy_created,
                    'requires_medwatch'       => $a->requiresMedwatch(),
                    'medwatch_overdue'        => $a->medwatchOverdue(),
                    'outcome_text'            => $a->outcome_text,
                    'href'                    => "/participants/{$a->participant_id}",
                ];
            });

        $summary = [
            'count_total'             => $rows->count(),
            'count_severe_plus'       => $rows->whereIn('severity', ['severe', 'life_threatening', 'fatal'])->count(),
            'count_requires_medwatch' => $rows->where('requires_medwatch', true)->count(),
            'count_medwatch_reported' => $rows->whereNotNull('reported_to_medwatch_at')->count(),
            'count_medwatch_overdue'  => $rows->where('medwatch_overdue', true)->count(),
            'count_auto_allergy'      => $rows->where('auto_allergy_created', true)->count(),
            'window_start'            => $since->toIso8601String(),
            'window_end'              => now()->toIso8601String(),
        ];

        if ($request->wantsJson()) {
            return response()->json(['rows' => $rows->values(), 'summary' => $summary]);
        }
        return Inertia::render('Compliance/AdeReporting', [
            'rows'    => $rows->values(),
            'summary' => $summary,
        ]);
    }

    /**
     * Phase P11 : Reportable infectious disease CSV export.
     * Each state has its own DPH portal + format; this is a manual upload bridge.
     * GET /compliance/reportable-infections.csv
     */
    public function reportableInfectionsCsv(Request $request)
    {
        $this->gate($request);
        $u = $request->user();
        // Hard-coded notifiable set per CDC/state DPH common reporting list.
        $notifiable = ['mrsa', 'tuberculosis', 'covid_19', 'influenza', 'norovirus', 'c_diff'];
        $rows = \App\Models\InfectionCase::forTenant($u->tenant_id)
            ->whereIn('organism_type', $notifiable)
            ->orderBy('onset_date')->get();
        $out = "case_id,participant_id,organism,onset_date,severity,reported_to_state_at\n";
        foreach ($rows as $r) {
            $out .= sprintf(
                "%d,%d,%s,%s,%s,%s\n",
                $r->id, $r->participant_id, $r->organism_type,
                $r->onset_date?->toDateString() ?? '',
                $r->severity ?? '',
                $r->reported_to_state_at?->toIso8601String() ?? '',
            );
        }
        return response($out, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="reportable-infections-' . now()->toDateString() . '.csv"',
        ]);
    }

    private function statusFor(Participant $p, ?int $days): string
    {
        if (! $p->nf_certification_date && ! $p->nf_recert_waived) return 'missing';
        if ($p->nf_recert_waived) return 'waived';
        if ($days === null) return 'missing';
        if ($days < 0)     return 'overdue';
        if ($days === 0)   return 'due_today';
        if ($days <= 15)   return 'due_15';
        if ($days <= 30)   return 'due_30';
        if ($days <= 60)   return 'due_60';
        return 'current';
    }
}

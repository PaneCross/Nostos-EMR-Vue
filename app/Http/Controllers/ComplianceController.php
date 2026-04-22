<?php

// ─── ComplianceController ─────────────────────────────────────────────────────
// Audit-pull endpoints. These are flat JSON universes CMS auditors (or state
// surveyors) expect to produce on demand. Each endpoint:
//   - requires auth + qa_compliance / enrollment / it_admin / super_admin
//   - scopes to tenant_id
//   - returns unpaginated flat rows (auditor imports into their workpapers)
//   - is safe to hit repeatedly
//
// Built in Phase 2 (MVP roadmap). Will gain more endpoints in future phases
// (SDR SLA, Level I/II reporting, appeals universe, etc.).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\Participant;
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
     * Service Denial Notices universe — every notice issued, for §460.122 audit.
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
     * Appeals universe — every §460.122 appeal with status, clocks, decision.
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
     * SDR SLA universe — every SDR with type (standard/expedited), due clock,
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

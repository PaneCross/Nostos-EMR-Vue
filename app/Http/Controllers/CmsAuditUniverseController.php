<?php

// ─── CmsAuditUniverseController — Phase R11 ─────────────────────────────────
// CMS PACE Audit Protocol 2.0 universe pulls. Four universes:
//   - sdr:            Service Determination Requests (42 CFR §460.121)
//   - grievances:     Grievance log
//   - disenrollments: Disenrollment events with transition-plan status
//   - appeals:        Appeals + denial notices
//
// Hardening:
//   - Pre-validation flags rows missing CMS-required fields
//   - Each export increments an attempt counter per (audit_id, universe).
//     CMS allows 3 attempts; the 4th is rejected with an explanatory error
//     and logged as non-compliance for operator visibility.
//   - Honest-labeled — file is downloaded, operator submits via HPMS.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\Appeal;
use App\Models\AuditLog;
use App\Models\CmsAuditUniverseAttempt;
use App\Models\DisenrollmentRecord;
use App\Models\Grievance;
use App\Models\Sdr;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CmsAuditUniverseController extends Controller
{
    public const UNIVERSES = ['sdr', 'grievances', 'disenrollments', 'appeals'];

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['qa_compliance', 'it_admin', 'executive', 'finance'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $auditId = $request->query('audit_id', 'PACE-' . now()->format('Y') . '-Q' . now()->quarter);
        $attempts = CmsAuditUniverseAttempt::forTenant($u->tenant_id)
            ->forAudit($auditId)
            ->orderBy('created_at')
            ->get();

        $byUniverse = [];
        foreach (self::UNIVERSES as $uv) {
            $latest = $attempts->where('universe', $uv)->last();
            $byUniverse[$uv] = [
                'universe'           => $uv,
                'attempts_used'      => $attempts->where('universe', $uv)->count(),
                'max_attempts'       => CmsAuditUniverseAttempt::MAX_ATTEMPTS,
                'last_passed'        => $latest?->passed_validation,
                'last_attempt_at'    => $latest?->created_at?->toIso8601String(),
                'last_row_count'     => $latest?->row_count,
            ];
        }

        return \Inertia\Inertia::render('Compliance/CmsAuditUniverses', [
            'audit_id'  => $auditId,
            'universes' => $byUniverse,
            'honest_label' => 'NostosEMR generates the universe file. Operator must submit via HPMS within the audit window. CMS rejects after the 3rd unsuccessful attempt.',
        ]);
    }

    public function export(Request $request, string $universe): StreamedResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless(in_array($universe, self::UNIVERSES, true), 404, "Unknown universe: {$universe}");

        $auditId = $request->query('audit_id', 'PACE-' . now()->format('Y') . '-Q' . now()->quarter);
        $from    = $request->query('from', Carbon::now()->subQuarter()->startOfQuarter()->toDateString());
        $to      = $request->query('to',   Carbon::now()->subQuarter()->endOfQuarter()->toDateString());

        // 4th attempt → block + log non-compliance.
        $priorAttempts = CmsAuditUniverseAttempt::forTenant($u->tenant_id)
            ->forAudit($auditId)
            ->forUniverse($universe)
            ->count();
        if ($priorAttempts >= CmsAuditUniverseAttempt::MAX_ATTEMPTS) {
            AuditLog::record(
                action: 'cms_audit.universe_max_attempts_exceeded',
                tenantId: $u->tenant_id, userId: $u->id,
                resourceType: 'cms_audit',
                resourceId: 0,
                description: "Universe {$universe} for audit {$auditId} blocked — already at {$priorAttempts} attempts (CMS max 3).",
            );
            abort(409, "Maximum 3 universe submission attempts have been used for {$auditId}/{$universe}. Logged as non-compliance — escalate to compliance officer.");
        }

        [$rows, $errors] = match ($universe) {
            'sdr'            => $this->buildSdrUniverse($u->tenant_id, $from, $to),
            'grievances'     => $this->buildGrievancesUniverse($u->tenant_id, $from, $to),
            'disenrollments' => $this->buildDisenrollmentsUniverse($u->tenant_id, $from, $to),
            'appeals'        => $this->buildAppealsUniverse($u->tenant_id, $from, $to),
        };

        $attempt = CmsAuditUniverseAttempt::create([
            'tenant_id'           => $u->tenant_id,
            'audit_id'            => $auditId,
            'universe'            => $universe,
            'attempt_number'      => $priorAttempts + 1,
            'passed_validation'   => empty($errors),
            'validation_errors'   => $errors ?: null,
            'row_count'           => count($rows),
            'period_start'        => $from,
            'period_end'          => $to,
            'exported_by_user_id' => $u->id,
        ]);

        AuditLog::record(
            action: 'cms_audit.universe_exported',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'cms_audit_universe_attempt',
            resourceId: $attempt->id,
            description: "Universe {$universe} attempt {$attempt->attempt_number}/3 ({$attempt->row_count} rows; " . (empty($errors) ? 'PASSED' : count($errors) . ' validation errors') . ").",
        );

        $filename = "cms-universe-{$universe}-{$auditId}-attempt{$attempt->attempt_number}.csv";
        return new StreamedResponse(function () use ($rows) {
            $h = fopen('php://output', 'w');
            if (! empty($rows)) {
                fputcsv($h, array_keys($rows[0]));
                foreach ($rows as $r) fputcsv($h, array_values($r));
            } else {
                fputcsv($h, ['(empty universe)']);
            }
            fclose($h);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Universe-Attempts-Remaining' => (string) (CmsAuditUniverseAttempt::MAX_ATTEMPTS - $attempt->attempt_number),
            'X-Universe-Validation' => empty($errors) ? 'passed' : 'failed',
        ]);
    }

    // ── Universe builders. Each returns [rows[], validationErrors[]]. ─────────

    private function buildSdrUniverse(int $tenantId, string $from, string $to): array
    {
        $rows = []; $errors = [];
        Sdr::where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('created_at')
            ->chunkById(500, function ($chunk) use (&$rows, &$errors) {
                foreach ($chunk as $s) {
                    $row = [
                        'sdr_id'         => $s->id,
                        'created_at'     => optional($s->created_at)->toDateString(),
                        'mrn'            => $s->participant?->mrn,
                        'request_type'   => $s->request_type,
                        'priority'       => $s->priority,
                        'status'         => $s->status,
                        'requesting_dept'=> $s->requesting_department,
                        'assigned_dept'  => $s->assigned_department,
                        'description'    => $s->description,
                        'closed_at'      => optional($s->closed_at)?->toDateString(),
                    ];
                    if (empty($row['mrn']))           $errors[] = ['sdr_id' => $s->id, 'error' => 'missing_mrn'];
                    if (empty($row['request_type'])) $errors[] = ['sdr_id' => $s->id, 'error' => 'missing_request_type'];
                    $rows[] = $row;
                }
            });
        return [$rows, $errors];
    }

    private function buildGrievancesUniverse(int $tenantId, string $from, string $to): array
    {
        $rows = []; $errors = [];
        Grievance::where('tenant_id', $tenantId)
            ->whereBetween('filed_at', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('filed_at')
            ->chunkById(500, function ($chunk) use (&$rows, &$errors) {
                foreach ($chunk as $g) {
                    $row = [
                        'grievance_id'   => $g->id,
                        'filed_at'       => optional($g->filed_at)?->toDateString(),
                        'mrn'            => $g->participant?->mrn,
                        'category'       => $g->category,
                        'severity'       => $g->severity,
                        'status'         => $g->status,
                        'description'    => $g->description,
                        'resolved_at'    => optional($g->resolved_at)?->toDateString(),
                    ];
                    if (empty($row['mrn']))      $errors[] = ['grievance_id' => $g->id, 'error' => 'missing_mrn'];
                    if (empty($row['category'])) $errors[] = ['grievance_id' => $g->id, 'error' => 'missing_category'];
                    $rows[] = $row;
                }
            });
        return [$rows, $errors];
    }

    private function buildDisenrollmentsUniverse(int $tenantId, string $from, string $to): array
    {
        $rows = []; $errors = [];
        DisenrollmentRecord::where('tenant_id', $tenantId)
            ->whereBetween('effective_date', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('effective_date')
            ->chunkById(500, function ($chunk) use (&$rows, &$errors) {
                foreach ($chunk as $d) {
                    $row = [
                        'disenrollment_id'      => $d->id,
                        'effective_date'        => optional($d->effective_date)?->toDateString(),
                        'mrn'                   => $d->participant?->mrn,
                        'reason'                => $d->reason,
                        'disenrollment_type'    => $d->disenrollment_type,
                        'transition_plan_status'=> $d->transition_plan_status,
                        'transition_plan_due_date' => optional($d->transition_plan_due_date)?->toDateString(),
                        'cms_notification_required' => $d->cms_notification_required ? 'Y' : 'N',
                    ];
                    if (empty($row['mrn']))    $errors[] = ['disenrollment_id' => $d->id, 'error' => 'missing_mrn'];
                    if (empty($row['reason']))$errors[] = ['disenrollment_id' => $d->id, 'error' => 'missing_reason'];
                    $rows[] = $row;
                }
            });
        return [$rows, $errors];
    }

    private function buildAppealsUniverse(int $tenantId, string $from, string $to): array
    {
        $rows = []; $errors = [];
        Appeal::where('tenant_id', $tenantId)
            ->whereBetween('filed_at', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name')
            ->orderBy('filed_at')
            ->chunkById(500, function ($chunk) use (&$rows, &$errors) {
                foreach ($chunk as $a) {
                    $row = [
                        'appeal_id'      => $a->id,
                        'filed_at'       => optional($a->filed_at)?->toDateString(),
                        'mrn'            => $a->participant?->mrn,
                        'category'       => $a->category ?? null,
                        'expedited'      => $a->is_expedited ? 'Y' : 'N',
                        'status'         => $a->status,
                        'decision'       => $a->decision ?? null,
                        'decided_at'     => optional($a->decided_at)?->toDateString(),
                        'deadline_at'    => optional($a->deadline_at)?->toDateString(),
                    ];
                    if (empty($row['mrn']))    $errors[] = ['appeal_id' => $a->id, 'error' => 'missing_mrn'];
                    if (empty($row['status']))$errors[] = ['appeal_id' => $a->id, 'error' => 'missing_status'];
                    $rows[] = $row;
                }
            });
        return [$rows, $errors];
    }
}

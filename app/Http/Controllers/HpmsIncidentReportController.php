<?php

// ─── HpmsIncidentReportController — Phase R8 ────────────────────────────────
// Five HPMS-aligned incident report exports for CMS PACE submissions:
//   - falls
//   - medication_errors
//   - abuse_neglect
//   - unexpected_deaths
//   - elopements
// Each export is a CSV with stable column ordering. All five share a date-range
// filter (?from=YYYY-MM-DD&to=YYYY-MM-DD). Default range = current quarter.
// Output is honest-labeled: this generates the file. Operator submits via HPMS.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Participant;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HpmsIncidentReportController extends Controller
{
    public const REPORTS = [
        'falls'               => ['fall'],
        'medication_errors'   => ['medication_error'],
        'abuse_neglect'       => ['abuse_neglect'],
        'unexpected_deaths'   => ['unexpected_death'],
        'elopements'          => ['elopement'],
    ];

    private function gate(): void
    {
        $u = Auth::user();
        abort_if(! $u, 401);
        $allow = ['qa_compliance', 'it_admin', 'executive'];
        abort_unless($u->isSuperAdmin() || in_array($u->department, $allow, true), 403);
    }

    public function index(Request $request)
    {
        $this->gate();
        $u = Auth::user();
        $from = $request->query('from', Carbon::now()->startOfQuarter()->toDateString());
        $to   = $request->query('to',   Carbon::now()->endOfQuarter()->toDateString());

        $summary = [];
        foreach (self::REPORTS as $key => $types) {
            $summary[$key] = Incident::forTenant($u->tenant_id)
                ->whereIn('incident_type', $types)
                ->whereBetween('occurred_at', [$from, $to])
                ->count();
        }

        return \Inertia\Inertia::render('Compliance/HpmsIncidentReports', [
            'summary' => $summary,
            'from'    => $from,
            'to'      => $to,
            'reports' => array_keys(self::REPORTS),
            'honest_label' => 'These exports satisfy the HPMS incident-report file format. After download, submit via the HPMS portal — NostosEMR does not transmit to CMS.',
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        $this->gate();
        $u = Auth::user();
        abort_unless(isset(self::REPORTS[$report]), 404, "Unknown report: {$report}");

        $from = $request->query('from', Carbon::now()->startOfQuarter()->toDateString());
        $to   = $request->query('to',   Carbon::now()->endOfQuarter()->toDateString());

        $rows = Incident::forTenant($u->tenant_id)
            ->whereIn('incident_type', self::REPORTS[$report])
            ->whereBetween('occurred_at', [$from, $to])
            ->with('participant:id,mrn,first_name,last_name,medicare_id,dob')
            ->orderBy('occurred_at')
            ->get();

        AuditLog::record(
            action: 'hpms.incident_report_exported',
            tenantId: $u->tenant_id, userId: $u->id,
            resourceType: 'incident_report',
            resourceId: 0,
            description: "HPMS export {$report} ({$from} → {$to}, {$rows->count()} rows)",
        );

        $filename = "hpms-{$report}-{$from}-to-{$to}.csv";
        return new StreamedResponse(function () use ($rows, $report) {
            $h = fopen('php://output', 'w');
            // Stable HPMS column order
            fputcsv($h, [
                'incident_id',
                'occurred_at',
                'reported_at',
                'incident_type',
                'mrn',
                'medicare_id',
                'participant_last',
                'participant_first',
                'participant_dob',
                'location',
                'description',
                'immediate_actions',
                'injuries_sustained',
                'cms_reportable',
                'cms_notified_at',
                'rca_required',
                'rca_completed_at',
                'sentinel',
                'status',
            ]);
            foreach ($rows as $r) {
                fputcsv($h, [
                    $r->id,
                    optional($r->occurred_at)->toIso8601String(),
                    optional($r->reported_at)->toIso8601String(),
                    $r->incident_type,
                    $r->participant?->mrn,
                    $r->participant?->medicare_id,
                    $r->participant?->last_name,
                    $r->participant?->first_name,
                    optional($r->participant?->dob)?->toDateString(),
                    $r->location_of_incident,
                    $r->description,
                    $r->immediate_actions_taken,
                    $r->injuries_sustained,
                    $r->cms_reportable ? 'Y' : 'N',
                    optional($r->cms_notified_at)?->toIso8601String(),
                    $r->rca_required ? 'Y' : 'N',
                    optional($r->rca_completed_at)?->toIso8601String(),
                    ($r->is_sentinel ?? false) ? 'Y' : 'N',
                    $r->status,
                ]);
            }
            fclose($h);
        }, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}

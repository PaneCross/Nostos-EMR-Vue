<?php

// ─── QapiAnnualEvaluationService ──────────────────────────────────────────────
// Compiles a tenant's QAPI activity for a calendar year into a PDF artifact
// for governing body review per 42 CFR §460.200.
//
// Idempotent per (tenant_id, year): regenerating replaces the PDF reference.
// The governing body review stamp (reviewer + date + notes) is preserved
// across regenerations — we only regenerate the PDF + summary snapshot.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Appeal;
use App\Models\Grievance;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\QapiAnnualEvaluation;
use App\Models\QapiProject;
use App\Models\Tenant;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class QapiAnnualEvaluationService
{
    /**
     * Generate (or regenerate) the annual evaluation PDF for a tenant + year.
     */
    public function generate(Tenant $tenant, int $year, User $actor): QapiAnnualEvaluation
    {
        return DB::transaction(function () use ($tenant, $year, $actor) {
            $summary  = $this->compileSummary($tenant, $year);
            $projects = $this->compileProjects($tenant, $year);

            // Upsert the evaluation row — preserve governing body review if already set.
            $evaluation = QapiAnnualEvaluation::firstOrNew([
                'tenant_id' => $tenant->id,
                'year'      => $year,
            ]);
            $evaluation->generated_at         = now();
            $evaluation->generated_by_user_id = $actor->id;
            $evaluation->summary_snapshot     = $summary;
            $evaluation->save();

            [$path, $size] = $this->renderPdf($evaluation, $tenant, $year, $summary, $projects, $actor);
            $evaluation->update([
                'pdf_path'       => $path,
                'pdf_size_bytes' => $size,
            ]);

            AuditLog::record(
                action:       'qapi_annual_evaluation.generated',
                tenantId:     $tenant->id,
                userId:       $actor->id,
                resourceType: 'qapi_annual_evaluation',
                resourceId:   $evaluation->id,
                description:  "QAPI Annual Evaluation generated for {$year}",
            );

            return $evaluation->fresh();
        });
    }

    /**
     * Stamp the governing body review on an existing evaluation.
     */
    public function recordGoverningBodyReview(
        QapiAnnualEvaluation $evaluation,
        User $reviewer,
        ?string $notes = null,
    ): QapiAnnualEvaluation {
        $evaluation->update([
            'governing_body_reviewed_at'         => now(),
            'governing_body_reviewed_by_user_id' => $reviewer->id,
            'governing_body_notes'               => $notes,
        ]);

        AuditLog::record(
            action:       'qapi_annual_evaluation.reviewed',
            tenantId:     $evaluation->tenant_id,
            userId:       $reviewer->id,
            resourceType: 'qapi_annual_evaluation',
            resourceId:   $evaluation->id,
            description:  "Annual QAPI evaluation for {$evaluation->year} reviewed by governing body",
        );

        return $evaluation->fresh();
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function compileSummary(Tenant $tenant, int $year): array
    {
        $start = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $end   = Carbon::createFromDate($year, 12, 31)->endOfYear();

        $allProjects = QapiProject::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->orWhereBetween('start_date', [$start, $end])
                  ->orWhereBetween('actual_completion_date', [$start, $end]);
            })
            ->get();

        $incidentCount  = Incident::where('tenant_id', $tenant->id)
            ->whereBetween('occurred_at', [$start, $end])->count();
        $grievanceCount = Grievance::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$start, $end])->count();
        $appealCount    = Appeal::where('tenant_id', $tenant->id)
            ->whereBetween('filed_at', [$start, $end])->count();
        $appealsOverturned = Appeal::where('tenant_id', $tenant->id)
            ->whereBetween('filed_at', [$start, $end])
            ->whereIn('status', [Appeal::STATUS_DECIDED_OVERTURNED, Appeal::STATUS_DECIDED_PARTIALLY_OVERTURNED])
            ->count();

        $mortality = Participant::where('tenant_id', $tenant->id)
            ->where('disenrollment_type', 'death')
            ->whereBetween('disenrollment_date', [$start->toDateString(), $end->toDateString()])
            ->count();

        // Avg grievance resolution time (days) — rough indicator, uses updated_at as resolution proxy.
        $grievanceAvg = Grievance::where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('updated_at')
            ->get()
            ->avg(fn ($g) => $g->created_at?->diffInDays($g->updated_at ?? now()));

        $incidentTypes = Incident::where('tenant_id', $tenant->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->selectRaw('incident_type, COUNT(*) as c')
            ->groupBy('incident_type')
            ->pluck('c', 'incident_type')
            ->toArray();

        return [
            'total_projects'                  => $allProjects->count(),
            'active_count'                    => $allProjects->where('status', 'active')->count(),
            'completed_count'                 => $allProjects->where('status', 'completed')->count(),
            'remeasuring_count'               => $allProjects->where('status', 'remeasuring')->count(),
            'suspended_count'                 => $allProjects->where('status', 'suspended')->count(),
            'incident_count'                  => $incidentCount,
            'grievance_count'                 => $grievanceCount,
            'grievance_avg_resolution_days'   => $grievanceAvg ? round($grievanceAvg, 1) : null,
            'appeal_count'                    => $appealCount,
            'appeals_overturned'              => $appealsOverturned,
            'mortality_count'                 => $mortality,
            'incident_types_summary'          => $incidentTypes
                ? implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($incidentTypes), $incidentTypes))
                : '—',
        ];
    }

    private function compileProjects(Tenant $tenant, int $year): array
    {
        $start = Carbon::createFromDate($year, 1, 1)->startOfYear();
        $end   = Carbon::createFromDate($year, 12, 31)->endOfYear();

        return QapiProject::where('tenant_id', $tenant->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('created_at', [$start, $end])
                  ->orWhereBetween('start_date', [$start, $end])
                  ->orWhereBetween('actual_completion_date', [$start, $end]);
            })
            ->orderBy('status')
            ->orderBy('title')
            ->get()
            ->map(fn (QapiProject $p) => [
                'title'                  => $p->title,
                'domain'                 => $p->domain,
                'domain_label'           => QapiProject::DOMAIN_LABELS[$p->domain] ?? $p->domain,
                'status'                 => $p->status,
                'aim_statement'          => $p->aim_statement,
                'baseline_metric'        => $p->baseline_metric,
                'current_metric'         => $p->current_metric,
                'target_metric'          => $p->target_metric,
                'findings'               => $p->findings,
            ])
            ->toArray();
    }

    /**
     * @return array{0: string, 1: int} [filePath, sizeBytes]
     */
    private function renderPdf(
        QapiAnnualEvaluation $evaluation,
        Tenant $tenant,
        int $year,
        array $summary,
        array $projects,
        User $generatedBy,
    ): array {
        $html = view('pdf.qapi_annual_evaluation', [
            'year'         => $year,
            'tenantName'   => $tenant->name,
            'generatedAt'  => $evaluation->generated_at ?? now(),
            'generatedBy'  => $generatedBy,
            'summary'      => $summary,
            'projects'     => $projects,
            'minRequired'  => QapiProject::MIN_ACTIVE_PROJECTS,
            'documentId'   => $evaluation->id,
        ])->render();

        $pdfBinary = Pdf::loadHTML($html)->output();

        $filename = sprintf(
            'qapi-annual/%d/TENANT-%d-QAPI-%d-%s.pdf',
            $tenant->id,
            $tenant->id,
            $year,
            now()->format('Ymd-His'),
        );
        Storage::disk('local')->put($filename, $pdfBinary);

        return [$filename, strlen($pdfBinary)];
    }
}

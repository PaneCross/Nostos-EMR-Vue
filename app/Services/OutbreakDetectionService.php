<?php

// ─── OutbreakDetectionService ────────────────────────────────────────────────
// Phase B2. Scans recent infection cases per tenant and auto-declares an
// outbreak when ≥3 cases of the same organism occur at the same site within
// the detection window (7 days).
//
// Idempotent — won't create duplicate outbreaks for an already-active
// (organism, site) pair. Back-links qualifying unlinked cases to the
// outbreak when a new one is declared.
//
// Runs from OutbreakDetectionJob (daily schedule) and is also invoked on
// InfectionCase creation so a single new case that pushes a cluster over
// the threshold declares immediately.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Alert;
use App\Models\InfectionCase;
use App\Models\InfectionOutbreak;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OutbreakDetectionService
{
    public function __construct(private AlertService $alerts) {}

    /**
     * Evaluate a single tenant. Returns an array of outbreak rows
     * created (or already-active ones that had cases linked) in this run.
     *
     * @return array<int, InfectionOutbreak>
     */
    public function evaluateTenant(int $tenantId): array
    {
        $since = Carbon::now()->subDays(InfectionOutbreak::DETECTION_WINDOW_DAYS);
        $declared = [];

        // Group recent cases by (site_id, organism_type). Treat null-site
        // as its own bucket — cases without a site don't cluster into an
        // auto-detected outbreak (they need manual review).
        $clusters = InfectionCase::forTenant($tenantId)
            ->where('onset_date', '>=', $since->toDateString())
            ->whereNotNull('site_id')
            ->select('site_id', 'organism_type', DB::raw('COUNT(*) AS case_count'))
            ->groupBy('site_id', 'organism_type')
            ->havingRaw('COUNT(*) >= ?', [InfectionOutbreak::DETECTION_MIN_CASES])
            ->get();

        foreach ($clusters as $cluster) {
            $outbreak = $this->declareIfNeeded(
                tenantId: $tenantId,
                siteId: (int) $cluster->site_id,
                organismType: $cluster->organism_type,
                caseCount: (int) $cluster->case_count,
                since: $since,
            );
            if ($outbreak) $declared[] = $outbreak;
        }

        return $declared;
    }

    /**
     * Evaluate a single newly-recorded case's cluster. Used as an inline
     * trigger from the controller so the operator doesn't have to wait
     * for the daily job when a case pushes a cluster over the threshold.
     */
    public function evaluateCase(InfectionCase $case): ?InfectionOutbreak
    {
        if (! $case->site_id) return null;

        $since = Carbon::now()->subDays(InfectionOutbreak::DETECTION_WINDOW_DAYS);
        $count = InfectionCase::forTenant($case->tenant_id)
            ->where('site_id', $case->site_id)
            ->where('organism_type', $case->organism_type)
            ->where('onset_date', '>=', $since->toDateString())
            ->count();

        if ($count < InfectionOutbreak::DETECTION_MIN_CASES) return null;

        return $this->declareIfNeeded(
            tenantId: $case->tenant_id,
            siteId: $case->site_id,
            organismType: $case->organism_type,
            caseCount: $count,
            since: $since,
        );
    }

    private function declareIfNeeded(
        int $tenantId,
        int $siteId,
        string $organismType,
        int $caseCount,
        Carbon $since,
    ): ?InfectionOutbreak {
        // Idempotency: one active outbreak per (tenant, site, organism).
        $existing = InfectionOutbreak::forTenant($tenantId)
            ->active()
            ->where('site_id', $siteId)
            ->where('organism_type', $organismType)
            ->first();

        if ($existing) {
            // Back-link any unlinked cases from the window.
            $linked = $this->linkCases($existing, $since);
            if ($linked > 0) {
                $existing->touch(); // bumps updated_at for visibility
            }
            return null;
        }

        $outbreak = InfectionOutbreak::create([
            'tenant_id'     => $tenantId,
            'site_id'       => $siteId,
            'organism_type' => $organismType,
            'started_at'    => $since->copy()->startOfDay(), // 7-day window start
            'status'        => 'active',
            'notes'         => sprintf(
                '[auto-detected] %d cases of %s at site #%d within %d-day window.',
                $caseCount, $organismType, $siteId, InfectionOutbreak::DETECTION_WINDOW_DAYS
            ),
        ]);

        $this->linkCases($outbreak, $since);
        $this->emitAlert($outbreak, $caseCount);

        return $outbreak;
    }

    private function linkCases(InfectionOutbreak $outbreak, Carbon $since): int
    {
        return InfectionCase::forTenant($outbreak->tenant_id)
            ->where('site_id', $outbreak->site_id)
            ->where('organism_type', $outbreak->organism_type)
            ->where('onset_date', '>=', $since->toDateString())
            ->whereNull('outbreak_id')
            ->update(['outbreak_id' => $outbreak->id]);
    }

    private function emitAlert(InfectionOutbreak $outbreak, int $caseCount): void
    {
        $siteLabel = $outbreak->site?->name ?? ('site #' . $outbreak->site_id);
        $this->alerts->create([
            'tenant_id'          => $outbreak->tenant_id,
            'source_module'      => 'infection',
            'alert_type'         => 'infection_outbreak_declared',
            'severity'           => 'critical',
            'title'              => 'Infection outbreak auto-declared',
            'message'            => sprintf(
                "%d cases of %s detected at %s within %d days. Implement containment + notify state health dept per policy.",
                $caseCount,
                $outbreak->organism_type,
                $siteLabel,
                InfectionOutbreak::DETECTION_WINDOW_DAYS
            ),
            'target_departments' => ['qa_compliance', 'primary_care', 'it_admin'],
            'metadata'           => [
                'outbreak_id'   => $outbreak->id,
                'site_id'       => $outbreak->site_id,
                'organism_type' => $outbreak->organism_type,
                'case_count'    => $caseCount,
            ],
        ]);
    }
}

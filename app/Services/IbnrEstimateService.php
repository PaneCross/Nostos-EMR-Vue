<?php

// ─── IbnrEstimateService : Phase S5 ─────────────────────────────────────────
// Lag-based IBNR (Incurred But Not Reported) estimation. For each prior
// service month, we observe how many encounters were ultimately billed/
// submitted within (1, 2, 3, 6, 12) months of the service date. Completion
// factors derived from the trailing 12 months are applied to the current
// month's incomplete encounters to estimate pending claim liability.
//
// Free-tier methodology : this is a directional estimator suitable for
// finance-team monthly close, not actuarial-grade. A real actuarial IBNR
// model would use development triangles, exposure-base smoothing, and
// payer-specific lag patterns. Mark this as "directional" in UI.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\EncounterLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class IbnrEstimateService
{
    public const LAG_BUCKETS = [1, 2, 3, 6, 12]; // months

    /**
     * Estimate IBNR for the trailing N service months.
     *
     * @return array{
     *   service_months: array<int, array{
     *     month:string, total_encounters:int, submitted:int, completion_pct:float,
     *     estimated_ibnr_count:float, estimated_ibnr_dollars:float,
     *   }>,
     *   total_estimated_ibnr_count: float,
     *   total_estimated_ibnr_dollars: float,
     *   completion_factors: array<int, float>,
     * }
     */
    public function estimate(int $tenantId, int $monthsBack = 6): array
    {
        $today = Carbon::today();
        $serviceMonths = [];

        // Pull trailing 12 months of encounters for completion-factor derivation.
        $factorWindowStart = $today->copy()->subMonths(12)->startOfMonth();
        $factorEncounters = EncounterLog::where('tenant_id', $tenantId)
            ->where('service_date', '>=', $factorWindowStart->toDateString())
            ->select(['service_date', 'submitted_at', 'submission_status', 'charge_amount'])
            ->get();

        $completionFactors = $this->buildCompletionFactors($factorEncounters, $today);

        // For each of the trailing N service months, estimate IBNR.
        for ($m = 0; $m < $monthsBack; $m++) {
            $monthStart = $today->copy()->subMonths($m)->startOfMonth();
            $monthEnd   = $monthStart->copy()->endOfMonth();
            $monthEncounters = $factorEncounters->filter(
                fn ($e) => $e->service_date >= $monthStart && $e->service_date <= $monthEnd
            );
            $total = $monthEncounters->count();
            $submitted = $monthEncounters->filter(fn ($e) => $e->submitted_at !== null
                || in_array($e->submission_status, ['submitted', 'acknowledged'], true))->count();
            $totalDollars = (float) $monthEncounters->sum('charge_amount');
            $submittedDollars = (float) $monthEncounters
                ->filter(fn ($e) => $e->submitted_at !== null
                    || in_array($e->submission_status, ['submitted', 'acknowledged'], true))
                ->sum('charge_amount');

            // Months elapsed since this service month (used to pick a completion factor).
            $monthsElapsed = max(1, (int) $today->diffInMonths($monthEnd));
            $factor = $this->factorForLag($completionFactors, $monthsElapsed);

            // If we observed factor=0.85 (85% of encounters submitted by now), then
            // total_encounters = submitted / 0.85. IBNR = total - submitted.
            $estimatedTotal = $factor > 0 ? $submitted / $factor : $submitted;
            $estimatedIbnrCount = max(0, $estimatedTotal - $submitted);

            $estimatedTotalDollars = $factor > 0 ? $submittedDollars / $factor : $submittedDollars;
            $estimatedIbnrDollars  = max(0, $estimatedTotalDollars - $submittedDollars);

            $serviceMonths[] = [
                'month'                 => $monthStart->format('Y-m'),
                'total_encounters'      => $total,
                'submitted'             => $submitted,
                'completion_pct'        => $total > 0 ? round(100 * $submitted / $total, 1) : 0,
                'completion_factor_used'=> round($factor, 4),
                'estimated_ibnr_count'  => round($estimatedIbnrCount, 1),
                'estimated_ibnr_dollars'=> round($estimatedIbnrDollars, 2),
            ];
        }

        return [
            'service_months'             => $serviceMonths,
            'total_estimated_ibnr_count' => array_sum(array_column($serviceMonths, 'estimated_ibnr_count')),
            'total_estimated_ibnr_dollars' => array_sum(array_column($serviceMonths, 'estimated_ibnr_dollars')),
            'completion_factors'         => $completionFactors,
        ];
    }

    /**
     * For each lag bucket (1, 2, 3, 6, 12 months), what fraction of encounters
     * with service_date that-many months ago have been submitted by today?
     */
    private function buildCompletionFactors($encounters, Carbon $today): array
    {
        $out = [];
        foreach (self::LAG_BUCKETS as $lag) {
            $cutoffEnd   = $today->copy()->subMonths($lag)->endOfMonth();
            $cutoffStart = $cutoffEnd->copy()->startOfMonth();
            $bucket = $encounters->filter(fn ($e) => $e->service_date >= $cutoffStart && $e->service_date <= $cutoffEnd);
            $total = $bucket->count();
            if ($total === 0) {
                $out[$lag] = 1.0; // no data, assume completion
                continue;
            }
            $submitted = $bucket->filter(fn ($e) => $e->submitted_at !== null
                || in_array($e->submission_status, ['submitted', 'acknowledged'], true))->count();
            $out[$lag] = round($submitted / $total, 4);
        }
        return $out;
    }

    /**
     * Pick the completion factor whose lag bucket most-closely matches
     * the months-elapsed since the service month.
     */
    private function factorForLag(array $factors, int $monthsElapsed): float
    {
        $closest = null;
        $best = PHP_INT_MAX;
        foreach ($factors as $lag => $f) {
            $d = abs($lag - $monthsElapsed);
            if ($d < $best) { $best = $d; $closest = $f; }
        }
        return $closest ?? 1.0;
    }
}

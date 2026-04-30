<?php

// ─── QualityMeasureSnapshotsDemoSeeder ───────────────────────────────────────
// Drives the actual QualityMeasureService against the demo tenant so the
// "today" point on every quality-measure trendline is REAL, derived from
// the real seeded participants, immunizations, clinical notes, problems,
// incidents, and consent records. Then back-fills 12 prior weekly points by
// perturbing each measure's true numerator (±10% with smooth drift) so the
// chart has a believable trend leading up to today's truth.
//
// Why fake the back-history at all : the production scheduled job runs
// nightly and a real history accumulates over time. In a fresh demo
// install we have no past, so for visual context we synthesize trend
// points that converge on the real value. The most-recent point (today)
// always reflects the actual computation, so signing notes / recording
// immunizations in the demo and pressing "Recompute now" on the dashboard
// will move the rate.
//
// When to run : after participant + clinical demo data exists. The
// DemoEnvironmentSeeder calls this near the bottom of its run() method.
// Idempotent : wipes prior demo snapshots for the active tenant first.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\QualityMeasure;
use App\Models\QualityMeasureSnapshot;
use App\Models\Tenant;
use App\Services\QualityMeasureService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class QualityMeasureSnapshotsDemoSeeder extends Seeder
{
    public function run(QualityMeasureService $svc): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first() ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant, skipping quality snapshots.');
            return;
        }

        if (QualityMeasure::count() === 0) {
            $this->command?->warn('  No quality measures, run QualityMeasureSeeder first.');
            return;
        }

        // Wipe prior demo snapshots so re-running this seeder doesn't pile up
        // duplicate trend points.
        QualityMeasureSnapshot::where('tenant_id', $tenant->id)->delete();

        // ── 1) Compute TODAY's point from the real participant data. ─────────
        // computeAll() inserts one snapshot row per measure with computed_at=now.
        $todayRows = $svc->computeAll($tenant->id);

        // ── 2) Synthesize 12 weekly back-history points per measure. ─────────
        // We perturb each measure's true numerator with a smooth drift + small
        // jitter so the trend looks realistic but ends at the real value.
        $points    = 12;
        $now       = CarbonImmutable::now();
        $backfill  = [];

        foreach ($todayRows as $todaySnap) {
            $denom    = (int) $todaySnap->denominator;
            $realNum  = (int) $todaySnap->numerator;
            if ($denom <= 0) {
                continue; // Nothing meaningful to chart.
            }

            // Pick a starting numerator 10-25% off from today's truth, then
            // walk it back toward truth across the 12 prior weeks. This
            // makes the line either climb or slide depending on where the
            // demo data landed for this measure (improving programmes
            // climb; declining programmes slide).
            $offsetPct = (mt_rand(10, 25) / 100.0) * (mt_rand(0, 1) ? 1 : -1);
            $startNum  = max(0, min($denom, (int) round($realNum * (1 - $offsetPct))));

            for ($i = 0; $i < $points; $i++) {
                $progress = $i / max(1, $points - 1);
                $num      = (int) round($startNum + ($realNum - $startNum) * $progress);
                // Jitter ±2% of denominator so the line isn't perfectly smooth.
                $jitter   = (int) round(((mt_rand(-200, 200) / 100.0) / 100.0) * $denom);
                $num      = max(0, min($denom, $num + $jitter));
                $rate     = round(100 * $num / $denom, 2);
                $when     = $now->subWeeks($points - $i); // weeks ago, in chronological order

                $backfill[] = [
                    'tenant_id'   => $tenant->id,
                    'measure_id'  => $todaySnap->measure_id,
                    'numerator'   => $num,
                    'denominator' => $denom,
                    'rate_pct'    => $rate,
                    'computed_at' => $when,
                    'created_at'  => $when,
                    'updated_at'  => $when,
                ];
            }
        }

        // Bulk insert backfill, much faster than per-row create() at this volume.
        foreach (array_chunk($backfill, 200) as $chunk) {
            QualityMeasureSnapshot::insert($chunk);
        }

        $this->command?->line(sprintf(
            '    Quality snapshots: <comment>1 real point + %d synthetic back-history points across %d measures</comment>',
            count($backfill),
            count($todayRows),
        ));
    }
}

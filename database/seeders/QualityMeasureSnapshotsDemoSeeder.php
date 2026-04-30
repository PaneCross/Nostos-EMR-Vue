<?php

// ─── QualityMeasureSnapshotsDemoSeeder ───────────────────────────────────────
// Backfills 90 days of weekly snapshots for every catalog row so the Quality
// Measures dashboard (/dashboards/quality) renders trend lines instead of
// empty cards. Idempotent : wipes prior demo snapshots for the active demo
// tenant before re-seeding.
//
// Each measure gets a believable starting rate, a small per-week drift toward
// a target, and ±2pp jitter so the line isn't a perfectly straight ramp.
// Numerator/denominator are reverse-derived from the rate so exports look
// honest. All snapshots are scoped to the same tenant the rest of the demo
// data uses.
//
// When to run : after QualityMeasureSeeder. DemoEnvironmentSeeder calls this.
// Depends on : QualityMeasureSeeder (the catalog must exist first), Tenant.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\QualityMeasure;
use App\Models\QualityMeasureSnapshot;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class QualityMeasureSnapshotsDemoSeeder extends Seeder
{
    /**
     * Per-measure starting rate + target rate (where the trend ends up after
     * 90 days). Tuned to look plausible : flu vaccination starts mid-50s and
     * climbs to mid-80s like a real season; A1c testing inches up; falls
     * trend in either direction depending on whether the home visit programme
     * is working that quarter.
     */
    private const TARGETS = [
        'FLU'  => ['start' => 56.0, 'end' => 84.0, 'denom' => 250],
        'PNE'  => ['start' => 71.0, 'end' => 88.0, 'denom' => 180],
        'PCV'  => ['start' => 79.0, 'end' => 92.0, 'denom' => 250],
        'A1C'  => ['start' => 64.0, 'end' => 78.0, 'denom' => 95],
        'DEE'  => ['start' => 52.0, 'end' => 67.0, 'denom' => 95],
        'FALL' => ['start' => 91.0, 'end' => 94.0, 'denom' => 250],
        'NPP'  => ['start' => 88.0, 'end' => 97.0, 'denom' => 250],
        'HOS'  => ['start' => 86.0, 'end' => 89.0, 'denom' => 250],
        'AD'   => ['start' => 62.0, 'end' => 81.0, 'denom' => 250],
    ];

    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first()
            ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant — skipping quality snapshots.');
            return;
        }

        $measures = QualityMeasure::pluck('measure_id')->all();
        if (empty($measures)) {
            $this->command?->warn('  No quality measures — run QualityMeasureSeeder first.');
            return;
        }

        // Wipe prior demo snapshots so re-running this seeder doesn't pile up
        // duplicate trend points.
        QualityMeasureSnapshot::where('tenant_id', $tenant->id)->delete();

        $now    = CarbonImmutable::now();
        // 13 weekly points covers ~90 days, which matches the dashboard window.
        $points = 13;
        $rows   = [];

        foreach ($measures as $measureId) {
            $cfg = self::TARGETS[$measureId] ?? ['start' => 70.0, 'end' => 80.0, 'denom' => 200];
            for ($i = 0; $i < $points; $i++) {
                // Linear interpolate start → end with mild jitter (±2pp).
                $progress = $i / max(1, $points - 1);
                $rate     = $cfg['start'] + ($cfg['end'] - $cfg['start']) * $progress;
                $jitter   = (mt_rand(-200, 200) / 100.0); // ±2.00
                $rate     = max(0.0, min(100.0, $rate + $jitter));
                $denom    = (int) $cfg['denom'];
                // Reverse-derive numerator from the rate so exports look honest.
                $num      = (int) round($denom * ($rate / 100.0));
                $when     = $now->subWeeks($points - 1 - $i);

                $rows[] = [
                    'tenant_id'   => $tenant->id,
                    'measure_id'  => $measureId,
                    'numerator'   => $num,
                    'denominator' => $denom,
                    'rate_pct'    => round($rate, 2),
                    'computed_at' => $when,
                    'created_at'  => $when,
                    'updated_at'  => $when,
                ];
            }
        }

        // Bulk insert — much faster than per-row create() at this volume.
        foreach (array_chunk($rows, 200) as $chunk) {
            QualityMeasureSnapshot::insert($chunk);
        }

        $this->command?->line(sprintf(
            '    Quality snapshots: <comment>%d points across %d measures (~%d days)</comment>',
            count($rows),
            count($measures),
            $points * 7,
        ));
    }
}

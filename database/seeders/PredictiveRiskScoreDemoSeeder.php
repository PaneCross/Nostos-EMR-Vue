<?php

// ─── PredictiveRiskScoreDemoSeeder ───────────────────────────────────────────
// Generates fresh predictive-risk scores so /dashboards/high-risk has rows.
// The dashboard endpoint filters to scores computed in the last 24 hours and
// `band='high'`, so without this seeder the page is always empty (the scoring
// job runs daily in prod but never fires in dev).
//
// Distribution per risk_type :
//   ~12 high   (score 70-95)
//   ~ 8 medium (score 40-69)
//   ~10 low    (score 5-39)
//
// Both risk types (disenrollment + acute_event) are seeded for the same set
// of participants so the dashboard's filter dropdown is exercisable.
//
// When to run : after participant seeding. DemoEnvironmentSeeder calls this.
// Depends on : Participant, Tenant.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class PredictiveRiskScoreDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first() ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant — skipping predictive risk scores.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('enrollment_status', 'enrolled')
            ->orderBy('id')
            ->limit(30)
            ->get();

        if ($participants->isEmpty()) {
            $this->command?->warn('  No enrolled participants — skipping predictive risk scores.');
            return;
        }

        // Wipe prior demo rows so re-running doesn't duplicate.
        PredictiveRiskScore::where('tenant_id', $tenant->id)->delete();

        $now = CarbonImmutable::now()->subMinutes(30); // safely inside 24h window

        // Risk-factor narratives that show up in the per-participant detail view.
        $factorPool = [
            ['hospitalization_30d'],
            ['polypharmacy', 'fall_history_90d'],
            ['recent_er_visit', 'cognitive_decline_flag'],
            ['weight_loss_5pct', 'caregiver_burnout'],
            ['med_nonadherence', 'transportation_gap'],
            ['snf_admission_history', 'wound_open'],
            ['behavioral_health_acute'],
            ['polypharmacy', 'low_grip_strength'],
            ['social_isolation', 'food_insecurity'],
            ['multiple_no_shows'],
        ];

        // For variety, each participant gets BOTH risk types but with
        // different bands — feels closer to a real model output where
        // disenrollment and acute_event correlate but aren't identical.
        $rows = [];
        foreach ($participants as $idx => $p) {
            // Disenrollment band : weighted toward high for the first 12, then medium, then low.
            $disenrollmentBand = $idx < 12 ? 'high' : ($idx < 20 ? 'medium' : 'low');
            $acuteBand         = $idx < 14 ? 'high' : ($idx < 22 ? 'medium' : 'low');

            $rows[] = [
                'tenant_id'      => $tenant->id,
                'participant_id' => $p->id,
                'model_version'  => 'demo-1.0',
                'risk_type'      => 'disenrollment',
                'score'          => self::scoreForBand($disenrollmentBand),
                'band'           => $disenrollmentBand,
                'factors'        => json_encode($factorPool[$idx % count($factorPool)]),
                'computed_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            $rows[] = [
                'tenant_id'      => $tenant->id,
                'participant_id' => $p->id,
                'model_version'  => 'demo-1.0',
                'risk_type'      => 'acute_event',
                'score'          => self::scoreForBand($acuteBand),
                'band'           => $acuteBand,
                'factors'        => json_encode($factorPool[($idx + 3) % count($factorPool)]),
                'computed_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            PredictiveRiskScore::insert($chunk);
        }

        $this->command?->line(sprintf(
            '    Predictive risk scores: <comment>%d rows across %d participants (both risk types)</comment>',
            count($rows),
            $participants->count(),
        ));
    }

    /** Pick a believable score from the band bucket, with a little randomness. */
    private static function scoreForBand(string $band): int
    {
        return match ($band) {
            'high'   => mt_rand(70, 95),
            'medium' => mt_rand(40, 69),
            default  => mt_rand(5, 39),
        };
    }
}

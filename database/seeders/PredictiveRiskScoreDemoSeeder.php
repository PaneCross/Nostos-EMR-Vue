<?php

// ─── PredictiveRiskScoreDemoSeeder ───────────────────────────────────────────
// Runs the real PredictiveRiskService against every enrolled participant in
// the demo tenant. The heuristic pulls features from real seeded data —
// LACE+ assessments, recent hospitalizations + ER visits, active medication
// counts (polypharmacy), ADL dependence, age — and emits one score per
// participant per risk_type (disenrollment + acute_event).
//
// The /dashboards/high-risk endpoint filters to scores computed in the last
// 24 hours, so without this seed the page is always empty in dev (the 03:00
// scheduled job never runs locally). Pressing "Recompute now" on the
// dashboard re-runs the same service against any updated demo data —
// adding an ER incident, prescribing more meds, etc. will move scores
// upward on the next click.
//
// When to run : after participant + clinical demo data exists. The
// DemoEnvironmentSeeder calls this near the bottom of its run() method.
// Idempotent : wipes prior demo scores for the active tenant first.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\AdlRecord;
use App\Models\Assessment;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Models\Tenant;
use App\Services\PredictiveRiskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class PredictiveRiskScoreDemoSeeder extends Seeder
{
    public function run(PredictiveRiskService $svc): void
    {
        $tenant = Tenant::where('slug', 'sunrise-pace-demo')->first() ?? Tenant::first();
        if (! $tenant) {
            $this->command?->warn('  No tenant — skipping predictive risk scoring.');
            return;
        }

        $participants = Participant::where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->where('enrollment_status', 'enrolled')
            ->orderBy('id')
            ->get();

        if ($participants->isEmpty()) {
            $this->command?->warn('  No enrolled participants — skipping predictive risk scoring.');
            return;
        }

        // Wipe prior demo rows so re-running this seeder doesn't compound
        // duplicate scores (the dashboard's 24-hour window would dedupe by
        // recency anyway, but a clean slate keeps row counts honest).
        PredictiveRiskScore::where('tenant_id', $tenant->id)->delete();

        // ── Inject risk-elevating clinical data for a subset ──────────────────
        // Without this most demo participants score 'low' because they lack
        // LACE+ assessments and recent hospitalizations — the heuristic's
        // highest-weight features. We seed a small population of "real" risk
        // signals so the dashboard reflects a believable distribution AND so
        // the underlying source data is auditable (clicking through to a
        // participant's chart shows the actual incidents / assessments that
        // produced the high score).
        $this->ensureRiskSignals($participants, $tenant->id);

        $byBand = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($participants as $p) {
            // score() calls scoreType() for both 'disenrollment' and
            // 'acute_event' — two PredictiveRiskScore rows per participant.
            foreach ($svc->score($p) as $score) {
                $byBand[$score->band] = ($byBand[$score->band] ?? 0) + 1;
            }
        }

        $this->command?->line(sprintf(
            '    Predictive risk scores: <comment>%d participants × 2 risk types · bands: %d high / %d medium / %d low</comment>',
            $participants->count(),
            $byBand['high'],
            $byBand['medium'],
            $byBand['low'],
        ));
    }

    /**
     * Insert real LACE+ assessments + recent hospitalization/ER incidents for a
     * subset of participants so the heuristic produces a realistic high /
     * medium / low spread instead of bottoming out at "low" everywhere.
     *
     * The data IS real (visible on the participant's chart) — it's just demo
     * data, like every other clinical row in this seeder cluster. We tag the
     * incidents with a description that calls out their seeded origin so a
     * curious tester can tell.
     */
    private function ensureRiskSignals($participants, int $tenantId): void
    {
        $now = CarbonImmutable::now();

        // First 12 participants → high-risk profile : LACE+ ~85, 2 hospitalisations + 1 ER visit in last 60d.
        // Next 8                → medium-risk        : LACE+ ~50, 1 ER visit in last 75d.
        // Remainder             → leave alone (low risk based on existing data).
        $highRiskIds   = $participants->slice(0, 12)->pluck('id');
        $mediumRiskIds = $participants->slice(12, 8)->pluck('id');

        // Wipe prior demo signals on this exact set so re-running doesn't pile on.
        Assessment::where('tenant_id', $tenantId)
            ->where('assessment_type', 'lace_plus_index')
            ->whereIn('participant_id', $highRiskIds->merge($mediumRiskIds))
            ->delete();
        Incident::where('tenant_id', $tenantId)
            ->whereIn('incident_type', ['hospitalization', 'er_visit'])
            ->whereIn('participant_id', $highRiskIds->merge($mediumRiskIds))
            ->where('description', 'like', '%[demo seed]%')
            ->forceDelete();

        // Pick any active user from the tenant to author the records.
        $authorId = \DB::table('shared_users')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->value('id');

        // Wipe demo ADL signals so re-runs don't pile on.
        AdlRecord::where('tenant_id', $tenantId)
            ->whereIn('participant_id', $highRiskIds)
            ->where('notes', 'like', '%[demo seed]%')
            ->delete();

        // High-risk assessments + incidents + ADL dependence.
        // Heuristic feature contributions targeted to land both risk_types
        // ≥ 70 (high band) :
        //   LACE+ ≈ 0.95 (score 120/125)
        //   recent_hosp = 1.0 (≥ 5 hospital/ER events in last 90d)
        //   adl_dependence = 1.0 (5 records all extensive_assist or worse)
        foreach ($highRiskIds as $idx => $pid) {
            Assessment::create([
                'tenant_id'            => $tenantId,
                'participant_id'       => $pid,
                'authored_by_user_id'  => $authorId,
                'department'           => 'primary_care',
                'assessment_type'      => 'lace_plus_index',
                'responses'            => ['demo' => true],
                'score'                => mt_rand(115, 125),
                'completed_at'         => $now->subDays(mt_rand(5, 25)),
            ]);
            // 4 hospitalisations + 2 ER visits in last 60 days → caps recent_hosp at 1.0.
            for ($h = 0; $h < 4; $h++) {
                $when = $now->subDays(mt_rand(7, 60));
                Incident::create([
                    'tenant_id'      => $tenantId,
                    'participant_id' => $pid,
                    'incident_type'  => 'hospitalization',
                    'occurred_at'    => $when,
                    'reported_at'    => $when,
                    'description'    => '[demo seed] Hospitalisation for risk-model demonstration.',
                    'status'         => 'closed',
                ]);
            }
            for ($e = 0; $e < 2; $e++) {
                $when = $now->subDays(mt_rand(3, 45));
                Incident::create([
                    'tenant_id'      => $tenantId,
                    'participant_id' => $pid,
                    'incident_type'  => 'er_visit',
                    'occurred_at'    => $when,
                    'reported_at'    => $when,
                    'description'    => '[demo seed] ER visit for risk-model demonstration.',
                    'status'         => 'closed',
                ]);
            }
            // 5 ADL records, all extensive_assist or total_dependent (index ≥ 3).
            $cats = ['bathing', 'dressing', 'transferring', 'toileting', 'ambulation'];
            foreach ($cats as $cat) {
                AdlRecord::create([
                    'tenant_id'          => $tenantId,
                    'participant_id'     => $pid,
                    'recorded_by_user_id'=> $authorId,
                    'adl_category'       => $cat,
                    'independence_level' => mt_rand(0, 1) ? 'extensive_assist' : 'total_dependent',
                    'notes'              => '[demo seed] ADL dependence for risk-model demonstration.',
                    'recorded_at'        => $now->subDays(mt_rand(2, 25)),
                ]);
            }
        }

        // Medium-risk assessments + 1 ER visit each.
        foreach ($mediumRiskIds as $pid) {
            Assessment::create([
                'tenant_id'            => $tenantId,
                'participant_id'       => $pid,
                'authored_by_user_id'  => $authorId,
                'department'           => 'primary_care',
                'assessment_type'      => 'lace_plus_index',
                'responses'            => ['demo' => true],
                'score'                => mt_rand(45, 60),
                'completed_at'         => $now->subDays(mt_rand(5, 30)),
            ]);
            $when = $now->subDays(mt_rand(20, 75));
            Incident::create([
                'tenant_id'      => $tenantId,
                'participant_id' => $pid,
                'incident_type'  => 'er_visit',
                'occurred_at'    => $when,
                'reported_at'    => $when,
                'description'    => '[demo seed] ER visit for risk-model demonstration.',
                'status'         => 'closed',
            ]);
        }
    }
}

<?php

// ─── PredictiveRiskService ───────────────────────────────────────────────────
// Phase G8. Simple weighted-feature risk model. Two risk types:
//   - disenrollment: 12-month likelihood of voluntary/involuntary disenroll
//   - acute_event:   90-day likelihood of hospitalization or ER visit
//
// **This is a demo/heuristic model**, not trained on real outcomes. Real
// production models require per-tenant outcome data + validation. The
// `factors` JSON makes the contribution of each feature inspectable so
// clinicians can reason about why a participant is flagged.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AdlRecord;
use App\Models\Assessment;
use App\Models\Incident;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\PredictiveRiskScore;

class PredictiveRiskService
{
    public const MODEL_VERSION = 'g8-v1-demo';

    /**
     * Compute both risk types for a participant. Returns [disenroll, acute].
     * @return array<int, PredictiveRiskScore>
     */
    public function score(Participant $p): array
    {
        return [
            $this->scoreType($p, 'disenrollment'),
            $this->scoreType($p, 'acute_event'),
        ];
    }

    public function scoreType(Participant $p, string $riskType): PredictiveRiskScore
    {
        $features = $this->extract($p);

        // Phase O6 — prefer a trained model version if one exists for this
        // (tenant, risk_type). Falls back to the heuristic weights otherwise.
        $trainedVersion = \App\Models\PredictiveModelVersion::forTenant($p->tenant_id)
            ->forRiskType($riskType)
            ->orderByDesc('version_number')->first();

        if ($trainedVersion && ! empty($trainedVersion->coefficients)) {
            [$score, $contribution] = $this->scoreFromTrainedModel($features, $trainedVersion->coefficients);
            $modelVersionLabel = "trained-v{$trainedVersion->version_number}";
            $modelVersionId    = $trainedVersion->id;
        } else {
            [$score, $contribution] = $this->scoreFromHeuristic($features, $this->weights($riskType));
            $modelVersionLabel = self::MODEL_VERSION;
            $modelVersionId    = null;
        }

        $band = match (true) {
            $score >= 70 => 'high',
            $score >= 40 => 'medium',
            default      => 'low',
        };

        return PredictiveRiskScore::create([
            'tenant_id'        => $p->tenant_id,
            'participant_id'   => $p->id,
            'model_version'    => $modelVersionLabel,
            'model_version_id' => $modelVersionId,
            'risk_type'        => $riskType,
            'score'            => $score,
            'band'             => $band,
            'factors'          => $contribution,
            'computed_at'      => now(),
        ]);
    }

    /**
     * Legacy weighted-sum heuristic path. Used when no trained model exists.
     * @return array{0:int,1:array<string,array{value:float,weight:int,delta:int}>}
     */
    private function scoreFromHeuristic(array $features, array $weights): array
    {
        $contribution = [];
        $sum = 0;
        foreach ($features as $f => $v) {
            $w = $weights[$f] ?? 0;
            $delta = (int) round($w * $v);
            $contribution[$f] = ['value' => $v, 'weight' => $w, 'delta' => $delta];
            $sum += $delta;
        }
        return [max(0, min(100, $sum)), $contribution];
    }

    /**
     * Logistic-regression path. Coefficients come from
     * PredictiveModelTrainingService. z = Σ (coef_i × feature_i), score = σ(z)×100.
     * @return array{0:int,1:array<string,array{value:float,coefficient:float,contribution:float}>}
     */
    private function scoreFromTrainedModel(array $features, array $coefficients): array
    {
        $z = 0.0;
        $contribution = [];
        foreach ($features as $f => $v) {
            $coef = (float) ($coefficients[$f] ?? 0);
            $c = $coef * $v;
            $z += $c;
            $contribution[$f] = [
                'value'        => $v,
                'coefficient'  => round($coef, 4),
                'contribution' => round($c, 4),
            ];
        }
        $sigmoid = 1.0 / (1.0 + exp(-$z));
        return [(int) round($sigmoid * 100), $contribution];
    }

    /**
     * Extract numeric features for a participant. All features normalized to
     * 0-1 where possible so weights map cleanly.
     */
    public function extract(Participant $p): array
    {
        // LACE+ latest total score (0-125) → normalize to 0-1
        $lace = Assessment::where('participant_id', $p->id)
            ->where('assessment_type', 'lace_plus_index')
            ->orderByDesc('created_at')->value('score');
        $laceNorm = $lace !== null ? min(1.0, (float) $lace / 125.0) : 0;

        // Recent hospitalizations in last 90 days (cap 5).
        $recentHosp = Incident::where('participant_id', $p->id)
            ->whereIn('incident_type', ['hospitalization', 'er_visit'])
            ->where('occurred_at', '>=', now()->subDays(90))->count();
        $recentHospNorm = min(1.0, $recentHosp / 5.0);

        // Active med count (polypharmacy at 10+).
        $medCount = Medication::where('participant_id', $p->id)
            ->where('status', 'active')->count();
        $polypharmNorm = min(1.0, $medCount / 15.0);

        // ADL dependence: average "worse than supervision" rate over last
        // 30 days. independence_level index 3+ counts as dependent.
        $recentAdl = AdlRecord::where('participant_id', $p->id)
            ->where('recorded_at', '>=', now()->subDays(30))->get();
        $adlDep = $recentAdl->count() > 0
            ? $recentAdl->filter(fn ($r) => array_search($r->independence_level, AdlRecord::LEVELS) >= 3)->count() / max(1, $recentAdl->count())
            : 0;

        // Age (normalize 60-95 → 0-1).
        $age = $p->dob ? (int) $p->dob->diffInYears(now()) : 70;
        $ageNorm = max(0, min(1.0, ($age - 60) / 35.0));

        return [
            'lace'          => round($laceNorm, 3),
            'recent_hosp'   => round($recentHospNorm, 3),
            'polypharmacy'  => round($polypharmNorm, 3),
            'adl_dependence'=> round($adlDep, 3),
            'age'           => round($ageNorm, 3),
        ];
    }

    /** Weights mapped to produce ~0-100 scores. Tuned so real outcomes skew reasonable. */
    private function weights(string $riskType): array
    {
        return match ($riskType) {
            'disenrollment' => [
                'lace' => 30, 'recent_hosp' => 25, 'polypharmacy' => 10,
                'adl_dependence' => 25, 'age' => 10,
            ],
            'acute_event' => [
                'lace' => 40, 'recent_hosp' => 35, 'polypharmacy' => 10,
                'adl_dependence' => 10, 'age' => 5,
            ],
        };
    }
}

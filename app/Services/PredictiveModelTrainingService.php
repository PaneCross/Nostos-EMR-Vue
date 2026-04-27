<?php

// ─── PredictiveModelTrainingService : Phase M4 ──────────────────────────────
// Trains a minimal logistic-regression model from historical PredictiveRiskScore
// rows + actual outcomes. MVP implements a lightweight in-house fit (gradient
// descent over a small feature vector) so we don't add rubix/ml as a dependency
// just to scaffold the pipeline. Swap in rubix/ml later when the training-set
// volume justifies it.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Incident;
use App\Models\PredictiveModelVersion;
use App\Models\PredictiveRiskScore;

class PredictiveModelTrainingService
{
    public function train(int $tenantId, string $riskType): PredictiveModelVersion
    {
        // Pull historical scores and join against actual outcomes.
        $samples = $this->collectSamples($tenantId, $riskType);

        // Degenerate case : no data. Persist a zero-weight "model" so
        // downstream scoring can still load a version.
        if (count($samples) === 0) {
            return $this->persist($tenantId, $riskType, [], 0.0, 0);
        }

        $features = $this->featureNames($samples);
        [$weights, $accuracy] = $this->fitLogistic($samples, $features);
        return $this->persist($tenantId, $riskType, array_combine($features, $weights), $accuracy, count($samples));
    }

    /**
     * @return array<int, array{features: array<string, float>, outcome: int}>
     */
    private function collectSamples(int $tenantId, string $riskType): array
    {
        $scores = PredictiveRiskScore::forTenant($tenantId)
            ->where('risk_type', $riskType)
            ->get();

        $out = [];
        foreach ($scores as $s) {
            $raw = is_array($s->factors) ? $s->factors : (json_decode((string) $s->factors, true) ?: []);
            $features = [];
            foreach ($raw as $k => $v) {
                $features[$k] = is_numeric($v) ? (float) $v : (is_bool($v) ? ($v ? 1.0 : 0.0) : 0.0);
            }
            // Outcome proxy: did the participant have an incident within 90d of scoring?
            $outcome = Incident::where('tenant_id', $tenantId)
                ->where('participant_id', $s->participant_id)
                ->whereBetween('occurred_at', [$s->computed_at, $s->computed_at->copy()->addDays(90)])
                ->exists() ? 1 : 0;
            $out[] = ['features' => $features, 'outcome' => $outcome];
        }
        return $out;
    }

    /** @param array<int, array{features: array<string, float>, outcome: int}> $samples */
    private function featureNames(array $samples): array
    {
        $keys = [];
        foreach ($samples as $s) foreach (array_keys($s['features']) as $k) $keys[$k] = true;
        return array_keys($keys);
    }

    /**
     * Lightweight batch gradient-descent fit. Returns [weights[], accuracy].
     * Not rubix/ml; just a sanity-check fit so we can persist a non-null model.
     */
    private function fitLogistic(array $samples, array $features): array
    {
        $n = count($features);
        $w = array_fill(0, $n, 0.0);
        $lr = 0.05;
        $iters = 200;

        for ($i = 0; $i < $iters; $i++) {
            $grad = array_fill(0, $n, 0.0);
            foreach ($samples as $s) {
                $z = 0.0;
                foreach ($features as $j => $k) $z += $w[$j] * ($s['features'][$k] ?? 0);
                $p = 1.0 / (1.0 + exp(-$z));
                $err = $p - $s['outcome'];
                foreach ($features as $j => $k) $grad[$j] += $err * ($s['features'][$k] ?? 0);
            }
            foreach ($features as $j => $k) $w[$j] -= $lr * ($grad[$j] / max(1, count($samples)));
        }

        $correct = 0;
        foreach ($samples as $s) {
            $z = 0.0;
            foreach ($features as $j => $k) $z += $w[$j] * ($s['features'][$k] ?? 0);
            $pred = 1.0 / (1.0 + exp(-$z)) >= 0.5 ? 1 : 0;
            if ($pred === $s['outcome']) $correct++;
        }
        $acc = count($samples) > 0 ? $correct / count($samples) : 0.0;
        return [$w, $acc];
    }

    private function persist(int $tenantId, string $riskType, array $coefficients, float $accuracy, int $size): PredictiveModelVersion
    {
        $next = (PredictiveModelVersion::forTenant($tenantId)->forRiskType($riskType)->max('version_number') ?? 0) + 1;
        return PredictiveModelVersion::create([
            'tenant_id' => $tenantId,
            'risk_type' => $riskType,
            'version_number' => $next,
            'algorithm' => 'logistic_regression',
            'coefficients' => $coefficients,
            'training_accuracy' => round($accuracy, 4),
            'training_sample_size' => $size,
            'trained_at' => now(),
            'created_at' => now(),
        ]);
    }
}

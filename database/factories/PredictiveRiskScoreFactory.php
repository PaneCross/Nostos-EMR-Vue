<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class PredictiveRiskScoreFactory extends Factory
{
    protected $model = PredictiveRiskScore::class;

    public function definition(): array
    {
        $score = $this->faker->numberBetween(0, 100);
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'model_version'  => 'g8-v1-demo',
            'risk_type'      => $this->faker->randomElement(PredictiveRiskScore::RISK_TYPES),
            'score'          => $score,
            'band'           => match (true) { $score >= 70 => 'high', $score >= 40 => 'medium', default => 'low' },
            'factors'        => ['lace' => ['value' => 0.5, 'weight' => 30, 'delta' => 15]],
            'computed_at'    => now()->subDays(rand(1, 7)),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

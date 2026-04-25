<?php

namespace Database\Factories;

use App\Models\AnticoagulationPlan;
use App\Models\InrResult;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InrResultFactory extends Factory
{
    protected $model = InrResult::class;

    public function definition(): array
    {
        return [
            'tenant_id'              => Tenant::factory(),
            'participant_id'         => Participant::factory(),
            'anticoagulation_plan_id'=> AnticoagulationPlan::factory(),
            'drawn_at'               => now()->subDays(rand(1, 30)),
            'value'                  => $this->faker->randomFloat(1, 1.5, 4.0),
            'in_range'               => true,
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

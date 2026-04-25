<?php

namespace Database\Factories;

use App\Models\AnticoagulationPlan;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnticoagulationPlanFactory extends Factory
{
    protected $model = AnticoagulationPlan::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'agent'          => 'warfarin',
            'target_inr_low' => 2.0,
            'target_inr_high'=> 3.0,
            'monitoring_interval_days' => 30,
            'start_date'     => now()->subMonths(2),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

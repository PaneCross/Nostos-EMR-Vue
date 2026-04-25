<?php

namespace Database\Factories;

use App\Models\CareGap;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CareGapFactory extends Factory
{
    protected $model = CareGap::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'measure'        => $this->faker->randomElement(CareGap::MEASURES),
            'satisfied'      => false,
            'next_due_date'  => now()->addMonths(rand(1, 12))->toDateString(),
            'calculated_at'  => now(),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
    public function satisfied(): self { return $this->state(fn () => ['satisfied' => true, 'last_satisfied_date' => now()->subMonth()->toDateString()]); }
}

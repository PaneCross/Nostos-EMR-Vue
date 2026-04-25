<?php

namespace Database\Factories;

use App\Models\DietaryOrder;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DietaryOrderFactory extends Factory
{
    protected $model = DietaryOrder::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'diet_type'      => $this->faker->randomElement(DietaryOrder::DIET_TYPES),
            'effective_date' => now()->subWeeks(rand(1, 12)),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

<?php

namespace Database\Factories;

use App\Models\IadlRecord;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class IadlRecordFactory extends Factory
{
    protected $model = IadlRecord::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'participant_id'      => Participant::factory(),
            'recorded_by_user_id' => null,
            'recorded_at'         => now()->subDays(rand(1, 90)),
            'telephone'           => $this->faker->randomElement([0, 1]),
            'shopping'            => $this->faker->randomElement([0, 1]),
            'food_preparation'    => $this->faker->randomElement([0, 1]),
            'housekeeping'        => $this->faker->randomElement([0, 1]),
            'laundry'             => $this->faker->randomElement([0, 1]),
            'transportation'      => $this->faker->randomElement([0, 1]),
            'medications'         => $this->faker->randomElement([0, 1]),
            'finances'            => $this->faker->randomElement([0, 1]),
            'total_score'         => $this->faker->numberBetween(0, 8),
            'interpretation'      => $this->faker->randomElement(IadlRecord::INTERPRETATIONS),
            'notes'               => null,
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

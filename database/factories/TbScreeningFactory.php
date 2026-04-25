<?php

namespace Database\Factories;

use App\Models\Participant;
use App\Models\TbScreening;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TbScreeningFactory extends Factory
{
    protected $model = TbScreening::class;

    public function definition(): array
    {
        $performed = $this->faker->dateTimeBetween('-1 year', 'now');
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'recorded_by_user_id' => null,
            'screening_type' => $this->faker->randomElement(TbScreening::TYPES),
            'performed_date' => $performed,
            'result'         => 'negative',
            'induration_mm'  => 0,
            'next_due_date'  => (clone $performed)->modify('+' . TbScreening::RECERT_DAYS . ' days'),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

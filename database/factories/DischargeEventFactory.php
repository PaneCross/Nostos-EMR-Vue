<?php

namespace Database\Factories;

use App\Models\DischargeEvent;
use App\Models\Participant;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class DischargeEventFactory extends Factory
{
    protected $model = DischargeEvent::class;

    public function definition(): array
    {
        $dischargedOn = Carbon::parse($this->faker->dateTimeBetween('-30 days', 'now'));
        return [
            'tenant_id'              => Tenant::factory(),
            'participant_id'         => Participant::factory(),
            'discharge_from_facility'=> 'General Hospital',
            'discharged_on'          => $dischargedOn,
            'readmission_risk_score' => $this->faker->randomFloat(2, 0, 1),
            'checklist'              => DischargeEvent::buildDefaultChecklist($dischargedOn),
            'auto_created_from_adt'  => false,
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

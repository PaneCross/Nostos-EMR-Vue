<?php

namespace Database\Factories;

use App\Models\AdverseDrugEvent;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AdverseDrugEventFactory extends Factory
{
    protected $model = AdverseDrugEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id'            => Tenant::factory(),
            'participant_id'       => Participant::factory(),
            'onset_date'           => now()->subDays(rand(1, 30)),
            'severity'             => 'mild',
            'reaction_description' => 'Mild rash, resolved without intervention.',
            'causality'            => 'possible',
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

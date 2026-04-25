<?php

namespace Database\Factories;

use App\Models\BereavementContact;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class BereavementContactFactory extends Factory
{
    protected $model = BereavementContact::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'contact_type'   => 'day_15',
            'family_contact_name' => $this->faker->name,
            'family_contact_phone'=> $this->faker->phoneNumber,
            'scheduled_at'   => now()->addDays(rand(15, 90)),
            'status'         => 'scheduled',
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

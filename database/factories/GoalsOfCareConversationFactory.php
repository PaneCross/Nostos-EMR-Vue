<?php

namespace Database\Factories;

use App\Models\GoalsOfCareConversation;
use App\Models\Participant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class GoalsOfCareConversationFactory extends Factory
{
    protected $model = GoalsOfCareConversation::class;

    public function definition(): array
    {
        return [
            'tenant_id'      => Tenant::factory(),
            'participant_id' => Participant::factory(),
            'conversation_date' => now()->subDays(rand(1, 60))->toDateString(),
            'participants_present' => 'Participant, daughter',
            'discussion_summary'   => 'Reviewed prognosis and goals of care.',
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function forParticipant(Participant $p): self { return $this->state(fn () => ['tenant_id' => $p->tenant_id, 'participant_id' => $p->id]); }
}

<?php

namespace Database\Factories;

use App\Models\DmeIssuance;
use App\Models\DmeItem;
use App\Models\Participant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DmeIssuanceFactory extends Factory
{
    protected $model = DmeIssuance::class;

    public function definition(): array
    {
        $issued = $this->faker->dateTimeBetween('-90 days', '-1 day');
        return [
            'tenant_id'          => \App\Models\Tenant::factory(),
            'dme_item_id'        => DmeItem::factory()->issued(),
            'participant_id'     => Participant::factory(),
            'issued_at'          => $issued->format('Y-m-d'),
            'issued_by_user_id'  => User::factory(),
            'expected_return_at' => null,
            'returned_at'        => null,
            'returned_to_user_id'=> null,
            'return_condition'   => null,
            'issue_notes'        => null,
            'return_notes'       => null,
        ];
    }

    public function returned(string $condition = 'good'): static
    {
        return $this->state(fn () => [
            'returned_at'      => $this->faker->dateTimeBetween('-1 day', 'now')->format('Y-m-d'),
            'return_condition' => $condition,
        ]);
    }
}

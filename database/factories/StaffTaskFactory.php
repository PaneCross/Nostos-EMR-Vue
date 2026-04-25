<?php

namespace Database\Factories;

use App\Models\StaffTask;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffTaskFactory extends Factory
{
    protected $model = StaffTask::class;

    public function definition(): array
    {
        return [
            'tenant_id'    => Tenant::factory(),
            'title'        => $this->faker->sentence(4),
            'description'  => $this->faker->sentence(8),
            'priority'     => $this->faker->randomElement(StaffTask::PRIORITIES),
            'status'       => 'pending',
            'due_at'       => now()->addDays(rand(1, 14)),
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function overdue(): self { return $this->state(fn () => ['due_at' => now()->subDays(rand(1, 7))]); }
    public function completed(): self { return $this->state(fn () => ['status' => 'completed', 'completed_at' => now()->subDay()]); }
}

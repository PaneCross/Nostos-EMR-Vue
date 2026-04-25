<?php

namespace Database\Factories;

use App\Models\SavedDashboard;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavedDashboardFactory extends Factory
{
    protected $model = SavedDashboard::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'owner_user_id' => User::factory(),
            'title'         => $this->faker->randomElement(['Executive Daily', 'Quality KPIs', 'Pharmacy Risk', 'Care Gap Trends']),
            'description'   => $this->faker->sentence(),
            'widgets'       => [],
            'is_shared'     => false,
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
    public function shared(): self { return $this->state(fn () => ['is_shared' => true]); }
}

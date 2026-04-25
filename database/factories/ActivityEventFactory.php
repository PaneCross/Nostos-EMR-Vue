<?php

namespace Database\Factories;

use App\Models\ActivityEvent;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityEventFactory extends Factory
{
    protected $model = ActivityEvent::class;

    public function definition(): array
    {
        return [
            'tenant_id'    => Tenant::factory(),
            'site_id'      => Site::factory(),
            'title'        => $this->faker->randomElement(['Music Therapy', 'Chair Yoga', 'Memory Café', 'Bingo', 'Crafts']),
            'category'     => $this->faker->randomElement(ActivityEvent::CATEGORIES),
            'scheduled_at' => now()->addDays(rand(1, 14))->setHour(10),
            'duration_min' => 60,
            'location'     => 'Day Center Activity Room',
        ];
    }

    public function forTenant(int $id): self { return $this->state(fn () => ['tenant_id' => $id]); }
}

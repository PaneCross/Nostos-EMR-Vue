<?php

namespace Database\Factories;

use App\Models\CmsAuditUniverseAttempt;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CmsAuditUniverseAttemptFactory extends Factory
{
    protected $model = CmsAuditUniverseAttempt::class;

    public function definition(): array
    {
        return [
            'tenant_id'           => Tenant::factory(),
            'audit_id'            => 'PACE-' . now()->format('Y') . '-Q' . $this->faker->numberBetween(1, 4),
            'universe'            => $this->faker->randomElement(CmsAuditUniverseAttempt::UNIVERSES),
            'attempt_number'      => $this->faker->numberBetween(1, CmsAuditUniverseAttempt::MAX_ATTEMPTS),
            'passed_validation'   => $this->faker->boolean(70),
            'validation_errors'   => null,
            'row_count'           => $this->faker->numberBetween(0, 250),
            'period_start'        => now()->subQuarter()->startOfQuarter()->toDateString(),
            'period_end'          => now()->subQuarter()->endOfQuarter()->toDateString(),
            'exported_by_user_id' => User::factory(),
        ];
    }
}

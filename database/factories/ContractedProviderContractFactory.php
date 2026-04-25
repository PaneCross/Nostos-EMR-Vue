<?php

namespace Database\Factories;

use App\Models\ContractedProvider;
use App\Models\ContractedProviderContract;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractedProviderContractFactory extends Factory
{
    protected $model = ContractedProviderContract::class;

    public function definition(): array
    {
        return [
            'tenant_id'              => \App\Models\Tenant::factory(),
            'contracted_provider_id' => ContractedProvider::factory(),
            'contract_number'        => $this->faker->bothify('CT-####-??'),
            'effective_date'         => $this->faker->dateTimeBetween('-2 years', '-1 month')->format('Y-m-d'),
            'termination_date'       => null,
            'reimbursement_basis'    => $this->faker->randomElement(ContractedProviderContract::REIMBURSEMENT_BASES),
            'reimbursement_value'    => $this->faker->randomFloat(2, 50, 110),
            'requires_prior_auth_default' => false,
            'notes'                  => null,
        ];
    }

    public function terminated(): static
    {
        return $this->state(fn () => [
            'termination_date' => $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d'),
        ]);
    }
}

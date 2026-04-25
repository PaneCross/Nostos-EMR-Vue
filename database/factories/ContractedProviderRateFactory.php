<?php

namespace Database\Factories;

use App\Models\ContractedProviderContract;
use App\Models\ContractedProviderRate;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractedProviderRateFactory extends Factory
{
    protected $model = ContractedProviderRate::class;

    public function definition(): array
    {
        return [
            'contract_id' => ContractedProviderContract::factory(),
            'cpt_code'    => $this->faker->randomElement(['99213', '99214', '99203', '93000', '71045', '36415']),
            'rate_amount' => $this->faker->randomFloat(2, 25, 350),
            'modifier'    => null,
            'notes'       => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\ContractedProvider;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractedProviderFactory extends Factory
{
    protected $model = ContractedProvider::class;

    public function definition(): array
    {
        return [
            'tenant_id'     => Tenant::factory(),
            'name'          => $this->faker->company() . ' ' . $this->faker->randomElement(['Cardiology', 'Imaging', 'Hospital', 'Lab']),
            'npi'           => $this->faker->numerify('##########'),
            'tax_id'        => $this->faker->numerify('##-#######'),
            'provider_type' => $this->faker->randomElement(ContractedProvider::PROVIDER_TYPES),
            'specialty'     => $this->faker->randomElement(['Cardiology', 'Orthopedics', 'Internal Medicine', 'Imaging', null]),
            'phone'         => $this->faker->phoneNumber(),
            'fax'           => $this->faker->phoneNumber(),
            'address_line1' => $this->faker->streetAddress(),
            'city'          => $this->faker->city(),
            'state'         => $this->faker->stateAbbr(),
            'zip'           => $this->faker->postcode(),
            'accepting_new_referrals' => true,
            'is_active'     => true,
            'notes'         => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false, 'accepting_new_referrals' => false]);
    }
}

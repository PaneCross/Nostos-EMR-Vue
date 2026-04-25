<?php

namespace Database\Factories;

use App\Models\DmeItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DmeItemFactory extends Factory
{
    protected $model = DmeItem::class;

    public function definition(): array
    {
        $itemType = $this->faker->randomElement([
            'walker', 'wheelchair', 'hospital_bed', 'oxygen_concentrator', 'cpap', 'lift_chair',
        ]);
        return [
            'tenant_id'        => Tenant::factory(),
            'item_type'        => $itemType,
            'manufacturer'     => $this->faker->randomElement(['Drive Medical', 'Invacare', 'ResMed', 'Philips', 'Pride Mobility']),
            'model'            => $this->faker->bothify('???-####'),
            'serial_number'    => $this->faker->bothify('SN-########'),
            'hcpcs_code'       => $this->faker->randomElement(['E0143', 'K0001', 'E0260', 'E1390', 'E0601']),
            'purchase_date'    => $this->faker->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'purchase_cost'    => $this->faker->randomFloat(2, 80, 4500),
            'status'           => 'available',
            'next_service_due' => null,
            'notes'            => null,
        ];
    }

    public function issued(): static    { return $this->state(['status' => 'issued']); }
    public function lost(): static      { return $this->state(['status' => 'lost']); }
    public function servicing(): static { return $this->state(['status' => 'servicing']); }
    public function retired(): static   { return $this->state(['status' => 'retired']); }
}

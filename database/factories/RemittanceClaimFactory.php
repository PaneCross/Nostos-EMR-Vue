<?php

// ─── RemittanceClaimFactory ───────────────────────────────────────────────────
//
// Generates test RemittanceClaim records.
// States cover all CLP02 claim status codes for comprehensive test coverage.

namespace Database\Factories;

use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemittanceClaimFactory extends Factory
{
    protected $model = RemittanceClaim::class;

    public function definition(): array
    {
        $submitted = $this->faker->randomFloat(2, 200, 3000);
        $allowed   = $this->faker->randomFloat(2, 150, $submitted);
        $paid      = $this->faker->randomFloat(2, 100, $allowed);
        $serviceDate = $this->faker->dateTimeBetween('-90 days', '-7 days');

        return [
            'remittance_batch_id'    => RemittanceBatch::factory(),
            'tenant_id'              => Tenant::factory(),
            'edi_batch_id'           => null,
            'encounter_log_id'       => null,
            'patient_control_number' => $this->faker->numerify('PCN-#########'),
            'claim_status'           => 'paid_partial',
            'submitted_amount'       => $submitted,
            'allowed_amount'         => $allowed,
            'paid_amount'            => $paid,
            'patient_responsibility' => $this->faker->randomFloat(2, 0, 50),
            'payer_claim_number'     => $this->faker->numerify('ICN##########'),
            'service_date_from'      => $serviceDate->format('Y-m-d'),
            'service_date_to'        => $serviceDate->format('Y-m-d'),
            'rendering_provider_npi' => $this->faker->numerify('##########'),
            'remittance_date'        => $this->faker->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
        ];
    }

    // ── States ─────────────────────────────────────────────────────────────────

    /** Claim paid at 100% of the submitted amount. */
    public function paidFull(): static
    {
        return $this->state(function () {
            $submitted = $this->faker->randomFloat(2, 200, 2000);
            return [
                'claim_status'    => 'paid_full',
                'submitted_amount' => $submitted,
                'allowed_amount'   => $submitted,
                'paid_amount'      => $submitted,
                'patient_responsibility' => 0,
            ];
        });
    }

    /** Claim denied by payer — triggers denial record creation. */
    public function denied(): static
    {
        return $this->state(function () {
            $submitted = $this->faker->randomFloat(2, 200, 2000);
            return [
                'claim_status'    => 'denied',
                'submitted_amount' => $submitted,
                'allowed_amount'   => 0,
                'paid_amount'      => 0,
                'patient_responsibility' => 0,
            ];
        });
    }

    /** Claim reversed by payer — recoupment scenario. */
    public function reversed(): static
    {
        return $this->state(fn () => [
            'claim_status' => 'reversed',
            'paid_amount'  => 0,
        ]);
    }

    /** Claim pending payer adjudication. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'claim_status' => 'pending',
            'paid_amount'  => 0,
            'allowed_amount' => 0,
        ]);
    }
}

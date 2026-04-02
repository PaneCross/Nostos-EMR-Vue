<?php

// ─── RemittanceBatchFactory ───────────────────────────────────────────────────
//
// Generates test RemittanceBatch records with realistic 835 ERA data.
// States simulate the full status lifecycle for feature test scenarios.

namespace Database\Factories;

use App\Models\RemittanceBatch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RemittanceBatchFactory extends Factory
{
    protected $model = RemittanceBatch::class;

    public function definition(): array
    {
        $paymentDate = $this->faker->dateTimeBetween('-3 months', 'now');

        return [
            'tenant_id'        => Tenant::factory(),
            'file_name'        => 'ERA_' . $this->faker->numerify('###########') . '_' . $this->faker->date('Ymd') . '.835',
            'check_eft_number' => $this->faker->numerify('##########'),
            'payer_name'       => $this->faker->randomElement([
                'Centers for Medicare & Medicaid Services',
                'Medicaid Managed Care - LA Care',
                'Blue Cross Blue Shield',
                'United Healthcare Medicare Advantage',
                'Anthem Blue Cross',
            ]),
            'payer_id'         => $this->faker->numerify('#####'),
            'edi_835_content'  => $this->makeSampleEdi835(),
            'payment_date'     => $paymentDate->format('Y-m-d'),
            'payment_amount'   => $this->faker->randomFloat(2, 5000, 150000),
            'check_issue_date' => $this->faker->dateTimeBetween('-5 days', '+3 days')->format('Y-m-d'),
            'payment_method'   => $this->faker->randomElement(['eft', 'check', 'virtual_card']),
            'status'           => 'processed',
            'source'           => 'manual_upload',
            'claim_count'      => $this->faker->numberBetween(5, 50),
            'paid_count'       => $this->faker->numberBetween(3, 45),
            'denied_count'     => $this->faker->numberBetween(0, 10),
            'adjustment_count' => $this->faker->numberBetween(2, 30),
            'processed_at'     => now(),
            'created_by_user_id' => User::factory(),
        ];
    }

    // ── States ─────────────────────────────────────────────────────────────────

    /** Batch just uploaded, not yet processed — simulates queue backlog. */
    public function received(): static
    {
        return $this->state(fn () => [
            'status'           => 'received',
            'claim_count'      => 0,
            'paid_count'       => 0,
            'denied_count'     => 0,
            'adjustment_count' => 0,
            'processed_at'     => null,
        ]);
    }

    /** Batch currently being parsed by Process835RemittanceJob. */
    public function processing(): static
    {
        return $this->state(fn () => [
            'status'      => 'processing',
            'processed_at' => null,
        ]);
    }

    /** Batch processed and posted to accounts receivable. */
    public function posted(): static
    {
        return $this->state(fn () => [
            'status' => 'posted',
        ]);
    }

    /** Batch processing failed — EDI parse error or database error. */
    public function error(): static
    {
        return $this->state(fn () => [
            'status'           => 'error',
            'processed_at'     => null,
            'claim_count'      => 0,
            'paid_count'       => 0,
            'denied_count'     => 0,
            'adjustment_count' => 0,
        ]);
    }

    /** Batch with high denial rate for denial management testing. */
    public function withDenials(int $deniedCount = 5): static
    {
        return $this->state(fn () => [
            'status'      => 'processed',
            'claim_count' => $deniedCount + $this->faker->numberBetween(3, 15),
            'paid_count'  => $this->faker->numberBetween(2, 10),
            'denied_count' => $deniedCount,
        ]);
    }

    /** Batch from CMS for capitation/encounter reconciliation. */
    public function fromCms(): static
    {
        return $this->state(fn () => [
            'payer_name' => 'Centers for Medicare & Medicaid Services',
            'payer_id'   => '77000',
            'payment_method' => 'eft',
        ]);
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** Generate a minimal but structurally valid ISA/GS/ST 835 envelope for testing. */
    private function makeSampleEdi835(): string
    {
        $isaDate   = now()->format('ymd');
        $isaTime   = now()->format('Hi');
        $interCtrl = str_pad($this->faker->numerify('#########'), 9, '0', STR_PAD_LEFT);
        $groupCtrl = $this->faker->numerify('#####');
        $stCtrl    = $this->faker->numerify('######');

        return implode('~', [
            "ISA*00*          *00*          *ZZ*SENDER         *ZZ*RECEIVER       *{$isaDate}*{$isaTime}*^*00501*{$interCtrl}*0*P*:",
            "GS*HP*SENDER*RECEIVER*{$isaDate}*{$isaTime}*{$groupCtrl}*X*005010X221A1",
            "ST*835*{$stCtrl}",
            "BPR*I*1000.00*C*ACH*CTX*01*123456789*DA*987654321*1234567890**01*123456789*DA*987654321*" . now()->format('Ymd'),
            "TRN*1*CHECK12345*1234567890",
            "DTM*405*" . now()->format('Ymd'),
            "N1*PR*TEST PAYER*XX*77000",
            "N1*PE*SUNRISE PACE*XX*1234567890",
            "SE*8*{$stCtrl}",
            "GE*1*{$groupCtrl}",
            "IEA*1*{$interCtrl}",
        ]) . '~';
    }
}

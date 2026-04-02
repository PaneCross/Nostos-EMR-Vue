<?php

// ─── DenialRecordFactory ──────────────────────────────────────────────────────
//
// Generates test DenialRecord instances.
// States cover the full denial lifecycle and appeal deadline scenarios.

namespace Database\Factories;

use App\Models\DenialRecord;
use App\Models\RemittanceClaim;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class DenialRecordFactory extends Factory
{
    protected $model = DenialRecord::class;

    public function definition(): array
    {
        $denialDate    = $this->faker->dateTimeBetween('-60 days', '-7 days');
        $appealDeadline = (clone $denialDate)->modify('+' . DenialRecord::APPEAL_DEADLINE_DAYS . ' days');

        return [
            'remittance_claim_id' => RemittanceClaim::factory()->denied(),
            'tenant_id'           => Tenant::factory(),
            'encounter_log_id'    => null,
            'denial_category'     => $this->faker->randomElement(DenialRecord::CATEGORIES),
            'status'              => 'open',
            'denied_amount'       => $this->faker->randomFloat(2, 150, 2000),
            'primary_reason_code' => $this->faker->randomElement(['97', '50', '29', '4', '16', '96', '22']),
            'denial_reason'       => $this->faker->sentence(8),
            'denial_date'         => $denialDate->format('Y-m-d'),
            'appeal_deadline'     => $appealDeadline->format('Y-m-d'),
            'appeal_submitted_date' => null,
            'appeal_notes'        => null,
            'resolution_date'     => null,
            'resolution_notes'    => null,
            'written_off_by_user_id' => null,
            'written_off_at'      => null,
            'assigned_to_user_id' => null,
        ];
    }

    // ── States ─────────────────────────────────────────────────────────────────

    /** Denial with an active appeal submission in progress. */
    public function appealing(): static
    {
        return $this->state(fn () => [
            'status'                 => 'appealing',
            'appeal_submitted_date'  => now()->subDays(14)->toDateString(),
            'appeal_notes'           => 'Appeal submitted with supporting documentation.',
        ]);
    }

    /** Denial appeal won — payer reversed and paid the claim. */
    public function won(): static
    {
        return $this->state(fn () => [
            'status'                => 'won',
            'appeal_submitted_date' => now()->subDays(30)->toDateString(),
            'resolution_date'       => now()->subDays(5)->toDateString(),
            'resolution_notes'      => 'Payer reversed denial after review. Claim paid in full.',
        ]);
    }

    /** Denial appeal lost — no further recourse. */
    public function lost(): static
    {
        return $this->state(fn () => [
            'status'                => 'lost',
            'appeal_submitted_date' => now()->subDays(45)->toDateString(),
            'resolution_date'       => now()->subDays(10)->toDateString(),
            'resolution_notes'      => 'Appeal upheld by payer. Claim denied with finality.',
        ]);
    }

    /** Denial written off by finance staff — unrecoverable. */
    public function writtenOff(): static
    {
        return $this->state(fn () => [
            'status'         => 'written_off',
            'resolution_date' => now()->subDays(2)->toDateString(),
            'resolution_notes' => 'Written off — past appeal deadline.',
        ]);
    }

    /**
     * Denial past the CMS 120-day appeal deadline with no resolution.
     * Simulates revenue at risk / aged AR scenario.
     */
    public function overdueForAppeal(): static
    {
        return $this->state(function () {
            $denialDate = now()->subDays(DenialRecord::APPEAL_DEADLINE_DAYS + 10);
            return [
                'status'          => 'open',
                'denial_date'     => $denialDate->toDateString(),
                'appeal_deadline' => $denialDate->copy()->addDays(DenialRecord::APPEAL_DEADLINE_DAYS)->toDateString(),
            ];
        });
    }

    /** Denial with appeal deadline in the next 30 days — needs attention. */
    public function deadlineSoon(): static
    {
        return $this->state(function () {
            $denialDate = now()->subDays(DenialRecord::APPEAL_DEADLINE_DAYS - 20);
            return [
                'status'          => 'open',
                'denial_date'     => $denialDate->toDateString(),
                'appeal_deadline' => $denialDate->copy()->addDays(DenialRecord::APPEAL_DEADLINE_DAYS)->toDateString(),
            ];
        });
    }

    /** Authorization-related denial (CARC 96/197/277). */
    public function authorizationDenial(): static
    {
        return $this->state(fn () => [
            'denial_category'     => 'authorization',
            'primary_reason_code' => '96',
            'denial_reason'       => 'Service not authorized by payer prior to rendering.',
        ]);
    }

    /** Coding error denial (CARC 4/16/97). */
    public function codingError(): static
    {
        return $this->state(fn () => [
            'denial_category'     => 'coding_error',
            'primary_reason_code' => '97',
            'denial_reason'       => 'The benefit for this service is included in the payment/allowance for another service/procedure that has already been adjudicated.',
        ]);
    }
}

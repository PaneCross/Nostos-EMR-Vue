<?php

// ─── W53DataSeeder ─────────────────────────────────────────────────────────────
//
// Demo data for W5-3: 835 Remittance Processing + Denial Management.
//
// Seeds:
//   - 3 remittance batches (1 processed/clean, 1 with denials, 1 processing)
//   - 12 remittance claims across the 2 processed batches
//   - 6 remittance adjustments on the denied claims
//   - 4 denial records in various lifecycle stages (open, appealing, written_off)
//
// All batches are synthetic — no real X12 EDI content is required. The
// edi_835_content field stores a minimal ISA envelope stub for display purposes.
//
// Called from DemoEnvironmentSeeder after W52DataSeeder.
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\DenialRecord;
use App\Models\RemittanceAdjustment;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Models\User;
use Illuminate\Database\Seeder;

class W53DataSeeder extends Seeder
{
    public function run(): void
    {
        // ── Resolve tenant context ────────────────────────────────────────────

        $financeUser = User::where('department', 'finance')
            ->whereHas('tenant')
            ->first();

        if (! $financeUser) {
            $this->command->warn('  W53DataSeeder: No finance user found — skipping remittance seed.');
            return;
        }

        $tenantId = $financeUser->tenant_id;
        $userId   = $financeUser->id;

        // ── Batch 1 — CMS Medicare Part A/B, fully processed, all paid ────────

        $stub835 = $this->buildEdiStub('CMS MEDICARE', '0001234567', '20250301', '184500.00');

        $batch1 = RemittanceBatch::create([
            'tenant_id'           => $tenantId,
            'file_name'           => 'cms_medicare_835_20250301.835',
            'edi_835_content'     => $stub835,
            'status'              => 'processed',
            'source'              => 'manual_upload',
            'payer_name'          => 'CMS Medicare',
            'payer_id'            => '00001',
            'payment_date'        => now()->subDays(35)->toDateString(),
            'payment_amount'      => 184500.00,
            'check_eft_number'    => 'EFT-20250301-0001',
            'payment_method'      => 'eft',
            'claim_count'         => 8,
            'paid_count'          => 8,
            'denied_count'        => 0,
            'adjustment_count'    => 12,
            'processed_at'        => now()->subDays(35),
            'created_by_user_id'  => $userId,
        ]);

        // 8 paid claims on batch 1 — no denials
        $this->seedPaidClaims($batch1, $tenantId, 8);

        $this->command->line("    Batch 1 created: {$batch1->file_name} (paid all)");

        // ── Batch 2 — Molina Healthcare, processed, has 4 denied claims ────────

        $stub835b = $this->buildEdiStub('MOLINA HEALTHCARE CA', '0009876543', '20250315', '62800.00');

        $batch2 = RemittanceBatch::create([
            'tenant_id'           => $tenantId,
            'file_name'           => 'molina_hmo_835_20250315.edi',
            'edi_835_content'     => $stub835b,
            'status'              => 'processed',
            'source'              => 'manual_upload',
            'payer_name'          => 'Molina Healthcare CA',
            'payer_id'            => 'MOLICAHMO',
            'payment_date'        => now()->subDays(20)->toDateString(),
            'payment_amount'      => 62800.00,
            'check_eft_number'    => 'CHK-20250315-8842',
            'payment_method'      => 'check',
            'claim_count'         => 8,
            'paid_count'          => 4,
            'denied_count'        => 4,
            'adjustment_count'    => 18,
            'processed_at'        => now()->subDays(20),
            'created_by_user_id'  => $userId,
        ]);

        // 4 paid claims on batch 2
        $this->seedPaidClaims($batch2, $tenantId, 4);

        // 4 denied claims with adjustments and denial records
        $this->seedDeniedClaims($batch2, $tenantId, $userId);

        $this->command->line("    Batch 2 created: {$batch2->file_name} (4 denied)");

        // ── Batch 3 — CalOptima Medi-Cal, still processing (in_progress) ──────

        $stub835c = $this->buildEdiStub('CALOPTIMA MEDI-CAL', '0005551212', '20250325', '0.00');

        RemittanceBatch::create([
            'tenant_id'           => $tenantId,
            'file_name'           => 'caloptima_medicaid_835_20250325.dat',
            'edi_835_content'     => $stub835c,
            'status'              => 'processing',
            'source'              => 'manual_upload',
            'payer_name'          => 'CalOptima Medi-Cal',
            'payer_id'            => 'CALOPTIMA01',
            'payment_date'        => now()->toDateString(), // upload date placeholder — BPR date not yet parsed
            'payment_amount'      => 0.00,
            'check_eft_number'    => null,
            'payment_method'      => 'other',
            'claim_count'         => 0,
            'paid_count'          => 0,
            'denied_count'        => 0,
            'adjustment_count'    => 0,
            'processed_at'        => null,
            'created_by_user_id'  => $userId,
        ]);

        $this->command->line('    Batch 3 created: caloptima_medicaid_835_20250325.dat (processing)');
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Build a minimal X12 835 ISA/GS/ST envelope stub for display.
     * The parser is never called on seeded data — this is for UI display only.
     */
    private function buildEdiStub(
        string $payerName,
        string $checkNumber,
        string $dateYYYYMMDD,
        string $paymentAmount
    ): string {
        return implode('~', [
            'ISA*00*          *00*          *ZZ*NOSTOSEMR      *ZZ*' . str_pad(substr($payerName, 0, 15), 15) . '*' . substr($dateYYYYMMDD, 2, 6) . '*1200*^*00501*000000001*0*P*:',
            'GS*HP*NOSTOSEMR*PAYER*' . $dateYYYYMMDD . '*1200*1*X*005010X221A1',
            'ST*835*0001',
            'BPR*I*' . $paymentAmount . '*C*ACH*CCP*01*111000000*DA*123456789*' . $checkNumber . '**01*111000001*DA*987654321*' . $dateYYYYMMDD,
            'TRN*1*' . $checkNumber . '*1234567890',
            'DTM*405*' . $dateYYYYMMDD,
            'N1*PR*' . $payerName . '*XX*1234567890',
            'N1*PE*SUNRISE PACE DEMO ORG*XX*9876543210',
            'SE*9*0001',
            'GE*1*1',
            'IEA*1*000000001',
        ]) . '~';
    }

    /**
     * Seed a given number of paid claims for a batch.
     * These are happy-path claims with CO-45 contractual adjustments only.
     */
    private function seedPaidClaims(RemittanceBatch $batch, int $tenantId, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $submitted = rand(2000, 5000) + ($i * 100);
            $allowed   = $submitted * 0.78;
            $paid      = $allowed;

            $claim = RemittanceClaim::create([
                'remittance_batch_id'    => $batch->id,
                'tenant_id'              => $tenantId,
                'patient_control_number' => 'PCN' . $batch->id . str_pad($i, 4, '0', STR_PAD_LEFT),
                'payer_claim_number'     => 'PAYER-' . $batch->id . '-' . $i . '-' . rand(10000, 99999),
                'claim_status'           => rand(0, 3) === 0 ? 'paid_partial' : 'paid_full',
                'submitted_amount'       => $submitted,
                'allowed_amount'         => $allowed,
                'paid_amount'            => $paid,
                'patient_responsibility' => 0.00,
                'service_date_from'      => $batch->payment_date
                    ? now()->parse($batch->payment_date)->subDays(rand(20, 35))->toDateString()
                    : now()->subDays(30)->toDateString(),
                'service_date_to'        => $batch->payment_date
                    ? now()->parse($batch->payment_date)->subDays(rand(10, 20))->toDateString()
                    : now()->subDays(20)->toDateString(),
                'remittance_date'        => $batch->payment_date ?? now()->toDateString(),
            ]);

            // Contractual adjustment (fee schedule reduction)
            RemittanceAdjustment::create([
                'remittance_claim_id'   => $claim->id,
                'tenant_id'             => $tenantId,
                'adjustment_group_code' => 'CO',
                'reason_code'           => '45',
                'adjustment_amount'     => $submitted - $allowed,
                'adjustment_quantity'   => null,
                'service_line_id'       => '1',
            ]);
        }
    }

    /**
     * Seed 4 denied claims with realistic denial scenarios.
     * Each claim gets one or more CAS adjustments and a DenialRecord.
     */
    private function seedDeniedClaims(RemittanceBatch $batch, int $tenantId, int $userId): void
    {
        $denialScenarios = [
            // Scenario 1: Prior auth missing — open, deadline approaching
            [
                'pcn'           => 'PCN-AUTH-0001',
                'payer_claim'   => 'PAYER-AUTH-' . rand(10000, 99999),
                'submitted'     => 3200.00,
                'carc_code'     => '197',
                'carc_group'    => 'CO',
                'carc_amount'   => 3200.00,
                'category'      => 'authorization',
                'denial_reason' => 'CARC 197: Precertification/authorization/notification absent.',
                'status'        => 'open',
                'days_ago'      => 15,
            ],
            // Scenario 2: Timely filing — open, past the appeal deadline (120 days)
            [
                'pcn'           => 'PCN-TIME-0001',
                'payer_claim'   => 'PAYER-TIME-' . rand(10000, 99999),
                'submitted'     => 1850.00,
                'carc_code'     => '29',
                'carc_group'    => 'CO',
                'carc_amount'   => 1850.00,
                'category'      => 'timely_filing',
                'denial_reason' => 'CARC 29: The time limit for filing has expired.',
                'status'        => 'open',
                'days_ago'      => 125, // past 120-day appeal window
            ],
            // Scenario 3: Coding error — currently being appealed
            [
                'pcn'           => 'PCN-CODE-0001',
                'payer_claim'   => 'PAYER-CODE-' . rand(10000, 99999),
                'submitted'     => 2750.00,
                'carc_code'     => '16',
                'carc_group'    => 'CO',
                'carc_amount'   => 2750.00,
                'category'      => 'coding_error',
                'denial_reason' => 'CARC 16: Claim lacks information needed for adjudication.',
                'status'        => 'appealing',
                'days_ago'      => 45,
            ],
            // Scenario 4: Medical necessity — written off
            [
                'pcn'           => 'PCN-MED-0001',
                'payer_claim'   => 'PAYER-MED-' . rand(10000, 99999),
                'submitted'     => 980.00,
                'carc_code'     => '50',
                'carc_group'    => 'CO',
                'carc_amount'   => 980.00,
                'category'      => 'medical_necessity',
                'denial_reason' => 'CARC 50: Not deemed medically necessary by the payer.',
                'status'        => 'written_off',
                'days_ago'      => 60,
            ],
        ];

        foreach ($denialScenarios as $scenario) {
            $denialDate = now()->subDays($scenario['days_ago'])->toDateString();
            $serviceDate = now()->subDays($scenario['days_ago'] + 15)->toDateString();

            $claim = RemittanceClaim::create([
                'remittance_batch_id'    => $batch->id,
                'tenant_id'              => $tenantId,
                'patient_control_number' => $scenario['pcn'],
                'payer_claim_number'     => $scenario['payer_claim'],
                'claim_status'           => 'denied',
                'submitted_amount'       => $scenario['submitted'],
                'allowed_amount'         => 0.00,
                'paid_amount'            => 0.00,
                'patient_responsibility' => 0.00,
                'service_date_from'      => $serviceDate,
                'service_date_to'        => $serviceDate,
                'remittance_date'        => $denialDate,
            ]);

            // CAS adjustment segment (CO group = denial)
            RemittanceAdjustment::create([
                'remittance_claim_id'   => $claim->id,
                'tenant_id'             => $tenantId,
                'adjustment_group_code' => $scenario['carc_group'],
                'reason_code'           => $scenario['carc_code'],
                'adjustment_amount'     => $scenario['carc_amount'],
                'adjustment_quantity'   => null,
                'service_line_id'       => '1',
            ]);

            // Build denial record
            $appealDeadline = now()
                ->subDays($scenario['days_ago'])
                ->addDays(DenialRecord::APPEAL_DEADLINE_DAYS)
                ->toDateString();

            $denialData = [
                'remittance_claim_id' => $claim->id,
                'tenant_id'           => $tenantId,
                'encounter_log_id'    => null,
                'denial_category'     => $scenario['category'],
                'status'              => $scenario['status'],
                'denied_amount'       => $scenario['submitted'],
                'primary_reason_code' => $scenario['carc_code'],
                'denial_reason'       => $scenario['denial_reason'],
                'denial_date'         => $denialDate,
                'appeal_deadline'     => $appealDeadline,
            ];

            // Add status-specific fields
            if ($scenario['status'] === 'appealing') {
                $denialData['appeal_submitted_date'] = now()->subDays(10)->toDateString();
                $denialData['appeal_notes']          = 'Medical records attached. Claim was submitted correctly with valid procedure codes per PACE service guidelines. Requesting reconsideration.';
            }

            if ($scenario['status'] === 'written_off') {
                $denialData['resolution_date']        = now()->subDays(5)->toDateString();
                $denialData['resolution_notes']       = 'Denial amount ($' . number_format($scenario['submitted'], 2) . ') does not justify the cost and administrative burden of appeal. Writing off per revenue cycle policy for claims under $1,000.';
                $denialData['written_off_by_user_id'] = $userId;
                $denialData['written_off_at']         = now()->subDays(5);
            }

            DenialRecord::create($denialData);
        }
    }
}

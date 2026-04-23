<?php

// ─── CarcCodeSeeder ────────────────────────────────────────────────────────────
//
// Seeds the emr_carc_codes reference table with standard Claim Adjustment Reason
// Codes (CARCs) published by the X12 standards body and CMS.
//
// CARCs explain WHY a claim was adjusted or denied on an 835 ERA (Electronic
// Remittance Advice). These codes appear in CAS segments of the X12 835 file.
//
// The seeder covers the most common CARC codes encountered in PACE billing:
//   - CO group (Contractual Obligation) — most denials
//   - PR group (Patient Responsibility) — copay/deductible
//   - OA group (Other Adjustment) — coordination of benefits
//   - PI group (Payer Initiated)
//
// category values align with DenialRecord::CATEGORIES:
//   authorization, coding_error, timely_filing, duplicate, medical_necessity,
//   coordination_of_benefits, other
//
// This data is used by:
//   - Remittance835ParserService::categorizeDenial() (fallback lookup)
//   - Process835RemittanceJob::buildDenialReason() (human-readable text)
//   - DenialRecord workflow (category labels)
// ─────────────────────────────────────────────────────────────────────────────

namespace Database\Seeders;

use App\Models\CarcCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CarcCodeSeeder extends Seeder
{
    /**
     * Run the seeder.
     *
     * Uses insert with conflict handling to be safe on repeated runs.
     * The emr_carc_codes table has a unique constraint on `code`.
     */
    public function run(): void
    {
        // Truncate on fresh seed; safe because this is reference data only.
        DB::statement('TRUNCATE TABLE emr_carc_codes RESTART IDENTITY CASCADE');

        $codes = $this->getCarcCodes();
        $now = now();

        // Phase A1 tech-debt fix: seeder data carries friendly field names
        // (`category`, `group_code`) but the migration uses `denial_category`
        // and a derived `is_denial_indicator` boolean. Map at insert time
        // rather than edit 200+ hand-written entries. Also deduplicate by
        // code — some entries (e.g. '253' Sequestration) appear in multiple
        // category buckets.
        $byCode = [];
        foreach ($codes as $row) {
            $byCode[$row['code']] = [
                'code'                => $row['code'],
                'description'         => $row['description'],
                'notes'               => $row['notes'] ?? null,
                'denial_category'     => $row['category'] ?? null,
                'is_denial_indicator' => ($row['group_code'] ?? '') === 'CO',
                'is_active'           => $row['is_active'] ?? true,
                'created_at'          => $now,
            ];
        }
        $mapped = array_values($byCode);

        foreach (array_chunk($mapped, 50) as $chunk) {
            DB::table('emr_carc_codes')->insert($chunk);
        }

        $this->command->info('[CarcCodeSeeder] Seeded ' . count($codes) . ' CARC codes into emr_carc_codes.');
    }

    /**
     * Return the full list of CARC codes to seed.
     *
     * Each entry contains:
     *   code        — X12 CARC code string (1-5 chars, may include letters like 'B15')
     *   group_code  — X12 adjustment group (CO, PR, OA, PI)
     *   description — Human-readable explanation of the adjustment reason
     *   category    — One of DenialRecord::CATEGORIES for denial classification
     *   is_active   — All seeded codes are active (published standards)
     */
    private function getCarcCodes(): array
    {
        return [
            // ── Authorization / Prior Auth (CO group) ─────────────────────────

            [
                'code'        => '96',
                'group_code'  => 'CO',
                'description' => 'Non-covered charge(s). At least one Remark Code must be provided (may be comprised of either the NCPDP Reject Reason Code, or Remittance Advice Remark Code that is not an ALERT.)',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '197',
                'group_code'  => 'CO',
                'description' => 'Precertification/authorization/notification/pre-treatment absent.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '277',
                'group_code'  => 'CO',
                'description' => 'The related or qualifying claim/service was not identified on this claim. Usage: Refer to the 835 Healthcare Policy Identification Segment (loop 2110 Service Payment Information REF), if present.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '119',
                'group_code'  => 'CO',
                'description' => 'Benefit maximum for this time period or occurrence has been reached.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '52',
                'group_code'  => 'CO',
                'description' => 'The referring/prescribing/rendering provider is not eligible to refer/prescribe/order/perform the service billed.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '57',
                'group_code'  => 'CO',
                'description' => 'Payment denied/reduced because the payer deems the information submitted does not support this level of service, this many services, this length of service, this dosage, or this day\'s supply.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '125',
                'group_code'  => 'CO',
                'description' => 'Submission/billing error(s). At least one Remark Code must be provided (may be comprised of either the NCPDP Reject Reason Code, or Remittance Advice Remark Code that is not an ALERT.)',
                'category'    => 'authorization',
                'is_active'   => true,
            ],
            [
                'code'        => '234',
                'group_code'  => 'CO',
                'description' => 'This procedure is not paid separately when performed with another procedure on the same date.',
                'category'    => 'authorization',
                'is_active'   => true,
            ],

            // ── Coding Errors (CO group) ──────────────────────────────────────

            [
                'code'        => '4',
                'group_code'  => 'CO',
                'description' => 'The service/equipment/drug is not covered/authorized by your employer\'s plan.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '16',
                'group_code'  => 'CO',
                'description' => 'Claim/service lacks information or has submission/billing error(s) which is needed for adjudication.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '18',
                'group_code'  => 'CO',
                'description' => 'Exact duplicate claim/service (DO NOT USE THIS CODE TO REMARK A DUPLICATE ADJUSTMENT).',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '97',
                'group_code'  => 'CO',
                'description' => 'The benefit for this service is included in the payment/allowance for another service/procedure that has already been adjudicated.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '177',
                'group_code'  => 'CO',
                'description' => 'Patient has not met the required eligibility requirements.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => 'B15',
                'group_code'  => 'CO',
                'description' => 'This provider type/specialty may not bill this service.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '5',
                'group_code'  => 'CO',
                'description' => 'The procedure code/bill type is inconsistent with the place of service.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '6',
                'group_code'  => 'CO',
                'description' => 'The procedure/revenue code is inconsistent with the patient\'s age.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '7',
                'group_code'  => 'CO',
                'description' => 'The procedure/revenue code is inconsistent with the patient\'s gender.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '11',
                'group_code'  => 'CO',
                'description' => 'The diagnosis is inconsistent with the procedure.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '12',
                'group_code'  => 'CO',
                'description' => 'The diagnosis is inconsistent with the provider type.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '13',
                'group_code'  => 'CO',
                'description' => 'The date of death precedes the date of service.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '14',
                'group_code'  => 'CO',
                'description' => 'The date of birth follows the date of service.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '15',
                'group_code'  => 'CO',
                'description' => 'The authorization number is missing, invalid, or does not apply to the billed services or provider.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '58',
                'group_code'  => 'CO',
                'description' => 'Treatment was deemed by the payer to have been rendered in an inappropriate or invalid place of service.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],
            [
                'code'        => '74',
                'group_code'  => 'CO',
                'description' => 'Improper billing by provider - no action to be taken.',
                'category'    => 'coding_error',
                'is_active'   => true,
            ],

            // ── Timely Filing (CO group) ──────────────────────────────────────

            [
                'code'        => '29',
                'group_code'  => 'CO',
                'description' => 'The time limit for filing has expired.',
                'category'    => 'timely_filing',
                'is_active'   => true,
            ],

            // ── Duplicate Claims (CO group) ───────────────────────────────────

            [
                'code'        => '88',
                'group_code'  => 'CO',
                'description' => 'Claim is covered by another payer per coordination of benefits.',
                'category'    => 'duplicate',
                'is_active'   => true,
            ],
            [
                'code'        => '107',
                'group_code'  => 'CO',
                'description' => 'The related or qualifying claim/service was not identified on this claim.',
                'category'    => 'duplicate',
                'is_active'   => true,
            ],
            [
                'code'        => '183',
                'group_code'  => 'CO',
                'description' => 'The referring provider is not eligible to refer the service billed.',
                'category'    => 'duplicate',
                'is_active'   => true,
            ],
            [
                'code'        => '33',
                'group_code'  => 'CO',
                'description' => 'Duplicate Claim/Service was previously processed as a primary claim.',
                'category'    => 'duplicate',
                'is_active'   => true,
            ],

            // ── Medical Necessity (CO group) ──────────────────────────────────

            [
                'code'        => '50',
                'group_code'  => 'CO',
                'description' => 'These are non-covered services because this is not deemed a \'medical necessity\' by the payer.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => '167',
                'group_code'  => 'CO',
                'description' => 'This (these) diagnosis(es) is (are) not covered.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => '151',
                'group_code'  => 'CO',
                'description' => 'Payment adjusted because the payer deems the information submitted does not support this many/frequency of services.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => '49',
                'group_code'  => 'CO',
                'description' => 'These are non-covered services because this is a routine exam or screening procedure done in conjunction with a routine exam.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => '55',
                'group_code'  => 'CO',
                'description' => 'Claim/service denied. Procedure is inconsistent with the ordering/referring/rendering provider.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => '56',
                'group_code'  => 'CO',
                'description' => 'Claim/service denied because procedure/treatment is deemed experimental/investigational by the payer.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],
            [
                'code'        => 'B9',
                'group_code'  => 'CO',
                'description' => 'Services not covered because the patient is enrolled in a Managed Care Plan.',
                'category'    => 'medical_necessity',
                'is_active'   => true,
            ],

            // ── Coordination of Benefits (OA/CO group) ────────────────────────

            [
                'code'        => '22',
                'group_code'  => 'CO',
                'description' => 'This care may be covered by another payer per coordination of benefits.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],
            [
                'code'        => '23',
                'group_code'  => 'CO',
                'description' => 'The impact of prior payer(s) adjudication including payments and/or adjustments.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],
            [
                'code'        => '24',
                'group_code'  => 'CO',
                'description' => 'Charges are covered under a capitation agreement/managed care plan.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],
            [
                'code'        => '109',
                'group_code'  => 'CO',
                'description' => 'Claim/service not covered by this payer/contractor. You must send the claim/service to the correct payer/contractor.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],
            [
                'code'        => '110',
                'group_code'  => 'OA',
                'description' => 'Billing date predates service date.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],
            [
                'code'        => '128',
                'group_code'  => 'OA',
                'description' => 'Newborn\'s services are covered in the mother\'s Allowance.',
                'category'    => 'coordination_of_benefits',
                'is_active'   => true,
            ],

            // ── Patient Responsibility (PR group) ─────────────────────────────

            [
                'code'        => '1',
                'group_code'  => 'PR',
                'description' => 'Deductible Amount',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '2',
                'group_code'  => 'PR',
                'description' => 'Coinsurance Amount',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '3',
                'group_code'  => 'PR',
                'description' => 'Co-payment Amount',
                'category'    => 'other',
                'is_active'   => true,
            ],

            // ── Contractual Adjustments / Write-downs (CO group) ──────────────

            [
                'code'        => '45',
                'group_code'  => 'CO',
                'description' => 'Charge exceeds fee schedule/maximum allowable or contracted/legislated fee arrangement.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '100',
                'group_code'  => 'CO',
                'description' => 'Payment made to patient/insured/responsible party/employer.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '253',
                'group_code'  => 'CO',
                'description' => 'Sequestration - reduction in federal payment.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '94',
                'group_code'  => 'CO',
                'description' => 'Processed in Excess of charges.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '253',
                'group_code'  => 'CO',
                'description' => 'Sequestration - reduction in federal payment.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => 'A1',
                'group_code'  => 'CO',
                'description' => 'Claim/Service denied. At least one Remark Code must be provided.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => 'A6',
                'group_code'  => 'OA',
                'description' => 'Prior hospitalization or 30 day transfer requirement not met.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => 'B7',
                'group_code'  => 'CO',
                'description' => 'This provider was not certified/eligible to be paid for this procedure/service on this date of service.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => 'B8',
                'group_code'  => 'CO',
                'description' => 'Alternative services were available, and should have been utilized.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '19',
                'group_code'  => 'CO',
                'description' => 'Claim denied because this is a Work-Related injury/illness and thus the liability of the Worker\'s Compensation Carrier.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '26',
                'group_code'  => 'CO',
                'description' => 'Expenses incurred prior to coverage.',
                'category'    => 'other',
                'is_active'   => true,
            ],
            [
                'code'        => '27',
                'group_code'  => 'CO',
                'description' => 'Expenses incurred after coverage terminated.',
                'category'    => 'other',
                'is_active'   => true,
            ],
        ];
    }
}

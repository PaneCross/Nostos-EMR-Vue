<?php

// ─── Remittance835ParserService ───────────────────────────────────────────────
//
// Parses X12 5010 835 Electronic Remittance Advice (ERA) files into structured
// PHP arrays for storage as RemittanceBatch, RemittanceClaim, and
// RemittanceAdjustment records.
//
// X12 835 segment reference:
//   ISA — Interchange Control Header
//   GS  — Functional Group Header
//   ST  — Transaction Set Header (835)
//   BPR — Financial Information (payment amount, method, date)
//   TRN — Reassociation Trace Number (check/EFT number)
//   REF — Reference Information
//   DTM — Date/Time Reference
//   N1  — Name (payer = PR loop, payee = PE loop)
//   LX  — Header Number (service line counter)
//   CLP — Claim Payment Information (one per claim)
//   CAS — Claim Adjustment Segment (one or more per CLP)
//   NM1 — Name (rendering provider etc.)
//   SVC — Service Payment Information (service line detail)
//   DTM — Service date within claim loop
//   SE  — Transaction Set Trailer
//   GE  — Functional Group Trailer
//   IEA — Interchange Control Trailer
//
// parse() returns a structured array with 'batch' header info and 'claims' array.
// matchToClaims() attempts to cross-reference claims to emr_encounter_log records
// by patient_control_number (which maps to our EncounterLog ID or procedure code).

namespace App\Services;

use App\Models\CarcCode;
use App\Models\DenialRecord;
use App\Models\EncounterLog;
use App\Models\RemittanceAdjustment;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Remittance835ParserService
{
    // ── CARC code → denial category inference map ─────────────────────────────
    // Based on standard CARC-to-denial-type mappings used in PACE revenue cycle.

    private const DENIAL_CATEGORY_MAP = [
        // Authorization / Prior Auth
        '96'  => 'authorization',
        '197' => 'authorization',
        '277' => 'authorization',
        '119' => 'authorization',

        // Coding errors
        '4'   => 'coding_error',
        '16'  => 'coding_error',
        '18'  => 'coding_error',
        '97'  => 'coding_error',
        '177' => 'coding_error',
        'B15' => 'coding_error',

        // Timely filing
        '29'  => 'timely_filing',

        // Duplicate claim
        '88'  => 'duplicate',
        '107' => 'duplicate',
        '183' => 'duplicate',

        // Medical necessity
        '50'  => 'medical_necessity',
        '57'  => 'medical_necessity',
        '167' => 'medical_necessity',
        '151' => 'medical_necessity',

        // Coordination of benefits
        '22'  => 'coordination_of_benefits',
        '23'  => 'coordination_of_benefits',
        '24'  => 'coordination_of_benefits',
        '109' => 'coordination_of_benefits',
    ];

    // ── Payment method mapping (BPR04 → our payment_method values) ────────────

    private const PAYMENT_METHOD_MAP = [
        'CHK' => 'check',
        'ACH' => 'eft',
        'BOP' => 'eft',
        'FWT' => 'eft',
        'NON' => 'virtual_card',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // parse()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse a raw X12 835 EDI string into a structured array.
     *
     * Returns:
     *   [
     *     'batch'  => [payer_name, payer_id, payment_date, payment_amount, ...],
     *     'claims' => [
     *       [patient_control_number, claim_status, submitted_amount, ...
     *        'adjustments' => [[group_code, reason_code, amount], ...]
     *       ],
     *       ...
     *     ]
     *   ]
     *
     * @throws \InvalidArgumentException When the EDI content is not a valid 835 transaction.
     */
    public function parse(string $ediContent): array
    {
        // Strip Windows line endings and trailing whitespace
        $ediContent = str_replace(["\r\n", "\r"], "\n", trim($ediContent));

        // Detect element separator (position 3 of ISA segment)
        $elementSep = '*';
        $segmentSep = '~';

        // Split on segment terminator (tilde in standard 835)
        $segments = array_filter(
            array_map('trim', explode($segmentSep, $ediContent)),
            fn ($s) => strlen($s) > 0
        );
        $segments = array_values($segments);

        // Validate this is an 835 transaction
        $this->validateTransactionType($segments, $elementSep);

        $batch  = [];
        $claims = [];

        // State tracking for CLP loop parsing
        $currentClaim      = null;
        $inClpLoop         = false;
        $lastServiceLineId = null;

        foreach ($segments as $segment) {
            $elements = explode($elementSep, $segment);
            $segId    = strtoupper($elements[0]);

            switch ($segId) {
                // ── Batch-level header segments ───────────────────────────────

                case 'BPR':
                    // BPR01=transaction type, BPR02=payment amount, BPR04=payment method
                    // BPR16=check issue date (effective date)
                    $batch['payment_amount'] = (float) ($elements[2] ?? 0);
                    $rawMethod = strtoupper($elements[4] ?? '');
                    $batch['payment_method'] = self::PAYMENT_METHOD_MAP[$rawMethod] ?? 'other';
                    $batch['check_issue_date'] = isset($elements[16]) && strlen($elements[16]) === 8
                        ? $this->parseDate($elements[16])
                        : null;
                    break;

                case 'TRN':
                    // TRN02 = check/EFT reference number
                    $batch['check_eft_number'] = $elements[2] ?? null;
                    break;

                case 'DTM':
                    // DTM*405 = production date (payment date)
                    if (($elements[1] ?? '') === '405') {
                        $batch['payment_date'] = $this->parseDate($elements[2] ?? '');
                    }
                    break;

                case 'N1':
                    // N1*PR = payer name/ID, N1*PE = payee (us)
                    if (($elements[1] ?? '') === 'PR') {
                        $batch['payer_name'] = $elements[2] ?? '';
                        $batch['payer_id']   = $elements[4] ?? null;
                    }
                    break;

                // ── Claim loop segments ───────────────────────────────────────

                case 'LX':
                    // New service line counter — used to group SVC within a claim
                    $lastServiceLineId = $elements[1] ?? null;
                    break;

                case 'CLP':
                    // Save previous claim if exists
                    if ($currentClaim !== null) {
                        $claims[] = $currentClaim;
                    }

                    // CLP01=patient_control_number, CLP02=claim_status_code
                    // CLP03=submitted, CLP04=allowed, CLP05=paid, CLP07=payer_claim_number
                    $inClpLoop = true;
                    $currentClaim = [
                        'patient_control_number' => $elements[1] ?? '',
                        'claim_status'           => RemittanceClaim::mapClpStatus($elements[2] ?? ''),
                        'submitted_amount'        => (float) ($elements[3] ?? 0),
                        'allowed_amount'          => (float) ($elements[4] ?? 0),
                        'paid_amount'             => (float) ($elements[5] ?? 0),
                        'patient_responsibility'  => (float) ($elements[8] ?? 0),
                        'payer_claim_number'      => $elements[7] ?? null,
                        'remittance_date'         => $batch['payment_date'] ?? now()->toDateString(),
                        'service_date_from'       => null,
                        'service_date_to'         => null,
                        'rendering_provider_npi'  => null,
                        'adjustments'             => [],
                    ];
                    break;

                case 'CAS':
                    // CAS01=group_code, CAS02=reason_code, CAS03=amount
                    // Up to 6 reason/amount pairs per CAS segment (CAS02-03, CAS05-06, ...)
                    if ($currentClaim !== null) {
                        $groupCode = strtoupper($elements[1] ?? 'CO');

                        // Process each reason/amount pair in the CAS segment
                        // CAS pairs: (02,03), (05,06), (08,09), (11,12), (14,15), (17,18)
                        $pairOffsets = [[2, 3], [5, 6], [8, 9], [11, 12], [14, 15], [17, 18]];
                        foreach ($pairOffsets as [$reasonIdx, $amountIdx]) {
                            if (isset($elements[$reasonIdx]) && isset($elements[$amountIdx])
                                && strlen($elements[$reasonIdx]) > 0
                                && is_numeric($elements[$amountIdx])) {
                                $currentClaim['adjustments'][] = [
                                    'adjustment_group_code' => $groupCode,
                                    'reason_code'           => $elements[$reasonIdx],
                                    'adjustment_amount'     => (float) $elements[$amountIdx],
                                    'adjustment_quantity'   => isset($elements[$amountIdx + 1])
                                        && is_numeric($elements[$amountIdx + 1])
                                        ? (float) $elements[$amountIdx + 1]
                                        : null,
                                    'service_line_id'       => $lastServiceLineId,
                                ];
                            }
                        }
                    }
                    break;

                case 'NM1':
                    // NM1*82 = rendering provider, NM109 = NPI
                    if ($currentClaim !== null
                        && ($elements[1] ?? '') === '82'
                        && ($elements[8] ?? '') === 'XX') {
                        $currentClaim['rendering_provider_npi'] = $elements[9] ?? null;
                    }
                    break;

                case 'DTM':
                    // DTM*472 = service date (inside CLP loop)
                    if ($currentClaim !== null && ($elements[1] ?? '') === '472') {
                        $date = $this->parseDate($elements[2] ?? '');
                        $currentClaim['service_date_from'] = $date;
                        $currentClaim['service_date_to']   = $date;
                    }
                    // DTM*150 = service period start, DTM*151 = service period end
                    if ($currentClaim !== null && ($elements[1] ?? '') === '150') {
                        $currentClaim['service_date_from'] = $this->parseDate($elements[2] ?? '');
                    }
                    if ($currentClaim !== null && ($elements[1] ?? '') === '151') {
                        $currentClaim['service_date_to'] = $this->parseDate($elements[2] ?? '');
                    }
                    break;

                case 'SE':
                    // End of transaction — save last claim
                    if ($currentClaim !== null) {
                        $claims[] = $currentClaim;
                        $currentClaim = null;
                    }
                    $inClpLoop = false;
                    break;
            }
        }

        // Ensure defaults exist for batch header fields
        $batch['payment_date']   = $batch['payment_date']   ?? now()->toDateString();
        $batch['payment_amount'] = $batch['payment_amount'] ?? 0.0;
        $batch['payment_method'] = $batch['payment_method'] ?? 'other';
        $batch['payer_name']     = $batch['payer_name']     ?? 'Unknown Payer';
        $batch['payer_id']       = $batch['payer_id']       ?? null;

        return [
            'batch'  => $batch,
            'claims' => $claims,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // matchToClaims()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Attempt to match parsed claim arrays to existing EncounterLog records.
     *
     * The patient_control_number in the 835 CLP01 field corresponds to what
     * the PACE organization submitted in the 837P CLM01 field. In our system,
     * we use the EncounterLog ID as the patient_control_number when building
     * 837P files (see Edi837PBuilderService).
     *
     * Returns the same claims array with 'encounter_log_id' populated when matched.
     *
     * @param  array $claims   Parsed claim array from parse()
     * @param  int   $tenantId Tenant to scope the lookup
     * @return array Claims with encounter_log_id filled where matched
     */
    public function matchToClaims(array $claims, int $tenantId): array
    {
        // Build a lookup of encounter IDs from the parsed patient control numbers
        $controlNumbers = array_column($claims, 'patient_control_number');

        // Only look for numeric PCNs (our format is the encounter ID as integer)
        $numericPcns = array_filter($controlNumbers, fn ($pcn) => is_numeric($pcn));

        if (empty($numericPcns)) {
            return $claims;
        }

        // Fetch matching encounter IDs from the database
        $matchedIds = EncounterLog::where('tenant_id', $tenantId)
            ->whereIn('id', $numericPcns)
            ->pluck('id')
            ->flip()
            ->all();

        return array_map(function ($claim) use ($matchedIds) {
            $pcn = $claim['patient_control_number'];
            if (isset($matchedIds[$pcn])) {
                $claim['encounter_log_id'] = (int) $pcn;
            }
            return $claim;
        }, $claims);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // categorizeDenial()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Infer a denial category from a list of CAS adjustment records.
     *
     * Looks at all CO-group adjustments and finds the primary denial reason.
     * Falls back to CARC code lookup table, then to 'other'.
     *
     * @param  array  $adjustments Array of adjustment arrays from parse()
     * @return string One of DenialRecord::CATEGORIES
     */
    public function categorizeDenial(array $adjustments): string
    {
        foreach ($adjustments as $adj) {
            // Only CO-group (Contractual Obligation) adjustments drive denial categories
            if (($adj['adjustment_group_code'] ?? '') !== 'CO') {
                continue;
            }

            $code = $adj['reason_code'] ?? '';

            // Check our hardcoded map first (most common codes)
            if (isset(self::DENIAL_CATEGORY_MAP[$code])) {
                return self::DENIAL_CATEGORY_MAP[$code];
            }

            // Fall back to CARC code lookup table
            $category = CarcCode::categoryForCode($code);
            if ($category !== 'other') {
                return $category;
            }
        }

        return 'other';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getPrimaryReasonCode()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Extract the primary (first CO-group) CARC reason code from adjustments.
     * Returns null if no CO-group adjustment exists.
     */
    public function getPrimaryReasonCode(array $adjustments): ?string
    {
        foreach ($adjustments as $adj) {
            if (($adj['adjustment_group_code'] ?? '') === 'CO') {
                return $adj['reason_code'] ?? null;
            }
        }

        // Fall back to first adjustment of any group
        return $adjustments[0]['reason_code'] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse an X12 date string (YYYYMMDD or CCYYMMDD) to Y-m-d format.
     * Returns today's date if the input is blank or invalid.
     */
    private function parseDate(string $dateStr): string
    {
        $dateStr = trim($dateStr);

        if (strlen($dateStr) === 8 && ctype_digit($dateStr)) {
            $year  = substr($dateStr, 0, 4);
            $month = substr($dateStr, 4, 2);
            $day   = substr($dateStr, 6, 2);

            if (checkdate((int) $month, (int) $day, (int) $year)) {
                return "{$year}-{$month}-{$day}";
            }
        }

        return now()->toDateString();
    }

    /**
     * Validate that the segment array represents an 835 transaction.
     *
     * @throws \InvalidArgumentException
     */
    private function validateTransactionType(array $segments, string $elementSep): void
    {
        foreach ($segments as $segment) {
            $elements = explode($elementSep, $segment);
            if (strtoupper($elements[0]) === 'ST') {
                if (($elements[1] ?? '') !== '835') {
                    throw new \InvalidArgumentException(
                        'EDI file is not an 835 transaction (ST01=' . ($elements[1] ?? 'unknown') . ').'
                    );
                }
                return; // Found and validated ST segment
            }
        }
        // No ST segment found — may still be parseable, log warning but don't throw
        Log::warning('[Remittance835ParserService] No ST segment found in EDI content.');
    }
}

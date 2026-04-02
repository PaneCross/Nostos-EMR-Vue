<?php

// ─── Remittance835ParserServiceTest ──────────────────────────────────────────
// Unit tests for W5-3 Remittance835ParserService.
// Coverage:
//   - parse() returns array with 'batch' and 'claims' keys
//   - parse() extracts BPR payment_amount correctly
//   - parse() maps CHK payment method to 'check'
//   - parse() extracts N1*PR payer_name from the payer loop
//   - parse() parses CLP claim with correct fields (patient_control_number, claim_status)
//   - parse() extracts CAS adjustments into claim's adjustments array
//   - parse() throws InvalidArgumentException for non-835 ST type
//   - categorizeDenial() maps CARC '96' to 'authorization'
//   - categorizeDenial() maps CARC '29' to 'timely_filing'
//   - categorizeDenial() returns 'other' for unknown CO-group code
//   - categorizeDenial() ignores non-CO group adjustments
//   - getPrimaryReasonCode() returns first CO-group reason code
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Unit;

use App\Services\Remittance835ParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Remittance835ParserServiceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function service(): Remittance835ParserService
    {
        return new Remittance835ParserService();
    }

    /**
     * Build a minimal but complete X12 835 EDI string for testing.
     *
     * @param  array $extraSegments Optional segments to insert before SE
     * @param  float $paymentAmount BPR02 payment amount
     * @param  string $paymentMethod BPR04 code (CHK, ACH, etc.)
     * @param  string $payerName N1*PR name
     */
    private function buildEdi(
        array  $extraSegments  = [],
        float  $paymentAmount  = 1500.00,
        string $paymentMethod  = 'CHK',
        string $payerName      = 'Medicare Part A'
    ): string {
        $segments = [
            // Interchange and functional group headers
            "ISA*00*          *00*          *ZZ*PAYER          *ZZ*PAYEE          *260101*1200*^*00501*000000001*0*P*:",
            "GS*HP*PAYER*PAYEE*20260101*1200*1*X*005010X221A1",
            "ST*835*0001",
            // Financial information: BPR01(type) BPR02(amount) BPR03(credit/debit) BPR04(method)
            "BPR*I*{$paymentAmount}*C*{$paymentMethod}************20260101",
            // Trace: check/EFT number
            "TRN*1*CHK12345*1234567890",
            // Production date
            "DTM*405*20260101",
            // Payer name
            "N1*PR*{$payerName}*XX*1234567890",
        ];

        foreach ($extraSegments as $seg) {
            $segments[] = $seg;
        }

        $segments[] = "SE*" . (count($segments) + 1) . "*0001";
        $segments[] = "GE*1*1";
        $segments[] = "IEA*1*000000001";

        return implode('~', $segments) . '~';
    }

    /**
     * Build a CLP segment with one CAS adjustment and return the segment strings.
     */
    private function buildClaimSegments(
        string $pcn         = '12345',
        string $clpStatus   = '2',   // 2 = Paid, 4 = Denied
        float  $submitted   = 200.00,
        float  $allowed     = 150.00,
        float  $paid        = 150.00,
        string $casGroup    = 'CO',
        string $casCode     = '45',
        float  $casAmount   = 50.00
    ): array {
        return [
            "CLP*{$pcn}*{$clpStatus}*{$submitted}*{$allowed}*{$paid}*12*PAYCLAIM001***",
            "CAS*{$casGroup}*{$casCode}*{$casAmount}",
        ];
    }

    // ── parse() ───────────────────────────────────────────────────────────────

    public function test_parse_returns_array_with_batch_and_claims_keys(): void
    {
        $result = $this->service()->parse($this->buildEdi());

        $this->assertIsArray($result);
        $this->assertArrayHasKey('batch', $result);
        $this->assertArrayHasKey('claims', $result);
    }

    public function test_parse_extracts_bpr_payment_amount(): void
    {
        $edi    = $this->buildEdi(paymentAmount: 4250.75);
        $result = $this->service()->parse($edi);

        $this->assertEquals(4250.75, $result['batch']['payment_amount']);
    }

    public function test_parse_maps_chk_payment_method_to_check(): void
    {
        $edi    = $this->buildEdi(paymentMethod: 'CHK');
        $result = $this->service()->parse($edi);

        $this->assertEquals('check', $result['batch']['payment_method']);
    }

    public function test_parse_maps_ach_payment_method_to_eft(): void
    {
        $edi    = $this->buildEdi(paymentMethod: 'ACH');
        $result = $this->service()->parse($edi);

        $this->assertEquals('eft', $result['batch']['payment_method']);
    }

    public function test_parse_extracts_n1_pr_payer_name(): void
    {
        $edi    = $this->buildEdi(payerName: 'Medicare Advantage Plan');
        $result = $this->service()->parse($edi);

        $this->assertEquals('Medicare Advantage Plan', $result['batch']['payer_name']);
    }

    public function test_parse_parses_clp_claim_with_correct_fields(): void
    {
        $claimSegments = $this->buildClaimSegments(
            pcn:       '99001',
            clpStatus: '1',   // 1 = paid_full (2 = paid_partial, 4 = denied)
            submitted: 300.00,
            paid:      300.00
        );

        $edi    = $this->buildEdi($claimSegments);
        $result = $this->service()->parse($edi);

        $this->assertCount(1, $result['claims']);

        $claim = $result['claims'][0];
        $this->assertEquals('99001', $claim['patient_control_number']);
        $this->assertEquals(300.00, $claim['submitted_amount']);
        $this->assertEquals('paid_full', $claim['claim_status']);
    }

    public function test_parse_extracts_cas_adjustments_into_claim(): void
    {
        $claimSegments = $this->buildClaimSegments(
            pcn:       '88001',
            clpStatus: '4',   // denied
            casGroup:  'CO',
            casCode:   '96',
            casAmount: 200.00
        );

        $edi    = $this->buildEdi($claimSegments);
        $result = $this->service()->parse($edi);

        $claim = $result['claims'][0];
        $this->assertNotEmpty($claim['adjustments']);

        $adj = $claim['adjustments'][0];
        $this->assertEquals('CO', $adj['adjustment_group_code']);
        $this->assertEquals('96', $adj['reason_code']);
        $this->assertEquals(200.00, $adj['adjustment_amount']);
    }

    public function test_parse_returns_empty_claims_when_no_clp_segments(): void
    {
        $edi    = $this->buildEdi(); // no claim segments
        $result = $this->service()->parse($edi);

        $this->assertEmpty($result['claims']);
    }

    public function test_parse_throws_invalid_argument_exception_for_non_835_transaction(): void
    {
        // Build an EDI with ST*837 (wrong transaction type)
        $edi = implode('~', [
            "ISA*00*          *00*          *ZZ*SENDER*ZZ*RECEIVER*260101*1200*^*00501*000000001*0*P*:",
            "GS*HC*SENDER*RECEIVER*20260101*1200*1*X*005010X222A1",
            "ST*837*0001",  // 837 claim, NOT 835 remittance
            "SE*2*0001",
            "GE*1*1",
            "IEA*1*000000001",
        ]) . '~';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/835/');

        $this->service()->parse($edi);
    }

    // ── categorizeDenial() ────────────────────────────────────────────────────

    public function test_categorize_denial_maps_carc_96_to_authorization(): void
    {
        $adjustments = [
            ['adjustment_group_code' => 'CO', 'reason_code' => '96', 'adjustment_amount' => 500.0],
        ];

        $category = $this->service()->categorizeDenial($adjustments);

        $this->assertEquals('authorization', $category);
    }

    public function test_categorize_denial_maps_carc_29_to_timely_filing(): void
    {
        $adjustments = [
            ['adjustment_group_code' => 'CO', 'reason_code' => '29', 'adjustment_amount' => 300.0],
        ];

        $category = $this->service()->categorizeDenial($adjustments);

        $this->assertEquals('timely_filing', $category);
    }

    public function test_categorize_denial_returns_other_for_unknown_co_group_code(): void
    {
        // Use a completely invented CARC code not in DENIAL_CATEGORY_MAP or CarcCode table
        $adjustments = [
            ['adjustment_group_code' => 'CO', 'reason_code' => '99999', 'adjustment_amount' => 100.0],
        ];

        // CarcCode::categoryForCode() is called — if no row exists it returns 'other'
        $category = $this->service()->categorizeDenial($adjustments);

        $this->assertEquals('other', $category);
    }

    public function test_categorize_denial_ignores_non_co_group_adjustments(): void
    {
        // PR (Patient Responsibility) adjustments should NOT drive denial category
        $adjustments = [
            ['adjustment_group_code' => 'PR', 'reason_code' => '96', 'adjustment_amount' => 50.0],
        ];

        // Without any CO-group adjustment, should fall through to 'other'
        $category = $this->service()->categorizeDenial($adjustments);

        $this->assertEquals('other', $category);
    }

    // ── getPrimaryReasonCode() ────────────────────────────────────────────────

    public function test_get_primary_reason_code_returns_first_co_group_code(): void
    {
        $adjustments = [
            ['adjustment_group_code' => 'PR', 'reason_code' => '1', 'adjustment_amount' => 20.0],
            ['adjustment_group_code' => 'CO', 'reason_code' => '197', 'adjustment_amount' => 300.0],
            ['adjustment_group_code' => 'CO', 'reason_code' => '4', 'adjustment_amount' => 100.0],
        ];

        $code = $this->service()->getPrimaryReasonCode($adjustments);

        // First CO-group code should be returned (197, not 4)
        $this->assertEquals('197', $code);
    }

    public function test_get_primary_reason_code_returns_null_for_empty_adjustments(): void
    {
        $code = $this->service()->getPrimaryReasonCode([]);

        $this->assertNull($code);
    }
}

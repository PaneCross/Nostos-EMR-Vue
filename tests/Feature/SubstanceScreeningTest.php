<?php

// ─── SubstanceScreeningTest ──────────────────────────────────────────────────
// Phase C2b — AUDIT-C, CAGE, DAST-10 scoring + referral suggestions via
// AssessmentScoringService.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Services\AssessmentScoringService;
use Tests\TestCase;

class SubstanceScreeningTest extends TestCase
{
    private AssessmentScoringService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AssessmentScoringService();
    }

    public function test_audit_c_negative_screen(): void
    {
        $r = $this->svc->score('audit_c_alcohol', ['q1' => 1, 'q2' => 1, 'q3' => 0]);
        $this->assertEquals(2, $r['total']);
        $this->assertEquals('negative', $r['band']);
        $this->assertNull($this->svc->referralFor('audit_c_alcohol', $r['band']));
    }

    public function test_audit_c_positive_screen_triggers_bh_referral(): void
    {
        $r = $this->svc->score('audit_c_alcohol', ['q1' => 2, 'q2' => 2, 'q3' => 1]);
        $this->assertEquals(5, $r['total']);
        $this->assertEquals('positive', $r['band']);
        $ref = $this->svc->referralFor('audit_c_alcohol', 'positive');
        $this->assertEquals('behavioral_health', $ref['dept']);
    }

    public function test_cage_positive_at_two(): void
    {
        $r = $this->svc->score('cage_alcohol', [
            'c1' => 'yes', 'c2' => 'yes', 'c3' => 'no', 'c4' => 'no',
        ]);
        $this->assertEquals(2, $r['total']);
        $this->assertEquals('positive', $r['band']);
    }

    public function test_cage_negative_at_one(): void
    {
        $r = $this->svc->score('cage_alcohol', [
            'c1' => 'yes', 'c2' => 'no', 'c3' => 'no', 'c4' => 'no',
        ]);
        $this->assertEquals(1, $r['total']);
        $this->assertEquals('negative', $r['band']);
    }

    public function test_dast10_reverse_scored_item_three(): void
    {
        // All "no" means: item 3 (reverse) counts as 1, others as 0.
        $allNo = array_fill_keys(['d1','d2','d3','d4','d5','d6','d7','d8','d9','d10'], 'no');
        $r = $this->svc->score('dast10_substance', $allNo);
        $this->assertEquals(1, $r['total']);
        $this->assertEquals('low', $r['band']);
    }

    public function test_dast10_moderate_triggers_referral(): void
    {
        $payload = [
            'd1' => 'yes', 'd2' => 'yes', 'd3' => 'yes', // reverse: 'yes' = 0 weight → skipped
            'd4' => 'yes', 'd5' => 'yes', 'd6' => 'no',
            'd7' => 'no',  'd8' => 'no',  'd9' => 'no', 'd10' => 'no',
        ];
        $r = $this->svc->score('dast10_substance', $payload);
        $this->assertEquals(4, $r['total']);
        $this->assertEquals('moderate', $r['band']);
        $this->assertNotNull($this->svc->referralFor('dast10_substance', 'moderate'));
    }

    public function test_dast10_severe_triggers_urgent_referral(): void
    {
        $allYes = array_fill_keys(['d1','d2','d3','d4','d5','d6','d7','d8','d9','d10'], 'yes');
        // d3 reverse: yes=0, so total = 9
        $r = $this->svc->score('dast10_substance', $allYes);
        $this->assertEquals(9, $r['total']);
        $this->assertEquals('severe', $r['band']);
        $ref = $this->svc->referralFor('dast10_substance', 'severe');
        $this->assertStringContainsString('Urgent', $ref['goal']);
    }

    public function test_definitions_exposed_for_ui(): void
    {
        foreach (['audit_c_alcohol', 'cage_alcohol', 'dast10_substance'] as $i) {
            $d = $this->svc->definition($i);
            $this->assertNotNull($d);
            $this->assertEquals($i, $d['instrument']);
            $this->assertGreaterThan(0, count($d['questions']));
        }
    }
}

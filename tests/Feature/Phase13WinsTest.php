<?php

// ─── Phase13WinsTest ──────────────────────────────────────────────────────────
// Phase 13 — Short-term wins batch A. Covers the four active sub-items:
//   13.1 SNOMED + RxNorm columns + lookup search endpoints
//   13.2 Scored assessment instruments (PHQ-9, Mini-Cog, Morse, Katz ADL)
//   13.3 Drug-drug interaction pre-save preview endpoint
//   13.5 Grievance aging band + day-25 approaching-deadline alert
//
// 13.4 is documentation-only (existing SDR dual-clock verified clean).
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Allergy;
use App\Models\Grievance;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\RxnormLookup;
use App\Models\Site;
use App\Models\SnomedLookup;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssessmentScoringService;
use App\Services\GrievanceService;
use Database\Seeders\Phase13CodingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase13WinsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $doctor;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'P13']);
        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();

        $this->seed(Phase13CodingSeeder::class);
    }

    // ── 13.1 SNOMED / RxNorm ────────────────────────────────────────────────

    public function test_snomed_code_can_be_stored_on_problem(): void
    {
        $p = Problem::create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'icd10_code'     => 'E11.9',
            'icd10_description' => 'Type 2 diabetes mellitus',
            'snomed_code'    => '44054006',
            'snomed_display' => 'Diabetes mellitus type 2 (disorder)',
            'status'         => 'active',
        ]);
        $this->assertEquals('44054006', $p->fresh()->snomed_code);
    }

    public function test_rxnorm_code_can_be_stored_on_allergy(): void
    {
        $a = Allergy::create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'allergy_type'   => 'drug',
            'allergen_name'  => 'Penicillin',
            'rxnorm_code'    => '7258',
            'severity'       => 'severe',
            'is_active'      => true,
        ]);
        $this->assertEquals('7258', $a->fresh()->rxnorm_code);
    }

    public function test_snomed_lookup_search_returns_matches(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->getJson('/coding/snomed?q=diabetes');
        $r->assertOk();
        $codes = collect($r->json('results'))->pluck('code')->all();
        $this->assertContains('44054006', $codes);
    }

    public function test_rxnorm_lookup_respects_allergen_only_filter(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->getJson('/coding/rxnorm?q=penicillin&allergen_only=1');
        $r->assertOk();
        $results = $r->json('results');
        $this->assertNotEmpty($results);
        foreach ($results as $row) {
            $this->assertTrue((bool) $row['is_allergen_candidate']);
        }
    }

    // ── 13.2 Scored instruments ─────────────────────────────────────────────

    public function test_phq9_scoring_moderate_band(): void
    {
        $svc = app(AssessmentScoringService::class);
        // All 2's × 9 = 18 → moderately_severe (15-19)
        $responses = ['q1'=>2, 'q2'=>2, 'q3'=>2, 'q4'=>2, 'q5'=>2, 'q6'=>2, 'q7'=>2, 'q8'=>2, 'q9'=>2];
        $result = $svc->score('phq9_depression', $responses);
        $this->assertEquals(18, $result['total']);
        $this->assertEquals('moderately_severe', $result['band']);
    }

    public function test_mini_cog_positive_screen(): void
    {
        $svc = app(AssessmentScoringService::class);
        $result = $svc->score('mini_cog', ['q1' => 1, 'q2' => 1, 'q3' => 0]); // 2 total → positive
        $this->assertEquals('positive', $result['band']);
    }

    public function test_morse_fall_scale_high_risk(): void
    {
        $svc = app(AssessmentScoringService::class);
        $result = $svc->score('fall_risk_morse', [
            'history_of_falling' => 'yes',       // 25
            'secondary_diagnosis' => 'yes',      // 15
            'ambulatory_aid'     => 'furniture', // 30
            'iv_therapy'         => 'no',        // 0
            'gait'               => 'weak',      // 10
            'mental_status'      => 'forgets',   // 15
        ]); // total 95 → high
        $this->assertEquals(95, $result['total']);
        $this->assertEquals('high', $result['band']);
    }

    public function test_assessment_instrument_endpoint_returns_definition(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->getJson('/assessment-instruments/phq9_depression');
        $r->assertOk();
        $r->assertJsonPath('definition.instrument', 'phq9_depression');
        $this->assertCount(9, $r->json('definition.questions'));
    }

    public function test_assessment_instrument_score_endpoint_validates_responses(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->postJson('/assessment-instruments/katz_adl/score', [
            'responses' => [
                'bathing' => 'independent', 'dressing' => 'independent',
                'toileting' => 'independent', 'transferring' => 'independent',
                'continence' => 'independent', 'feeding' => 'independent',
            ],
        ]);
        $r->assertOk();
        $this->assertEquals(6, $r->json('score.total'));
        $this->assertEquals('independent', $r->json('score.band'));
    }

    // ── 13.3 Drug interaction pre-save preview ──────────────────────────────

    public function test_interaction_preview_returns_conflicts_without_persisting_alerts(): void
    {
        // Seed an existing active medication + a reference interaction pair
        Medication::create([
            'participant_id' => $this->participant->id,
            'tenant_id'      => $this->tenant->id,
            'drug_name'      => 'warfarin',
            'dose' => '5', 'dose_unit' => 'mg', 'route' => 'oral',
            'frequency' => 'daily', 'is_prn' => false,
            'status' => 'active',
            'prescribed_date' => now()->subDay()->toDateString(),
            'start_date' => now()->subDay()->toDateString(),
        ]);
        \DB::table('emr_drug_interactions_reference')->insert([
            'drug_name_1' => 'warfarin',
            'drug_name_2' => 'aspirin',
            'severity'    => 'major',
            'description' => 'Increased bleeding risk.',
        ]);

        $this->actingAs($this->doctor);
        $r = $this->postJson("/participants/{$this->participant->id}/medications/interaction-preview", [
            'drug_name'   => 'aspirin',
            'rxnorm_code' => '1191',
        ]);
        $r->assertOk();
        $this->assertTrue($r->json('has_any'));
        $this->assertTrue($r->json('has_major'));
        // Critically: NO DrugInteractionAlert row should have been created
        $this->assertDatabaseCount('emr_drug_interaction_alerts', 0);
    }

    public function test_interaction_preview_blocks_non_prescribing_department(): void
    {
        $activities = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'activities',
            'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($activities);
        $this->postJson("/participants/{$this->participant->id}/medications/interaction-preview", [
            'drug_name' => 'aspirin',
        ])->assertForbidden();
    }

    // ── 13.5 Grievance aging ────────────────────────────────────────────────

    public function test_grievance_aging_band_transitions(): void
    {
        $g = Grievance::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'category' => 'quality_of_care',
            'priority' => 'standard',
            'status'   => 'open',
            'filed_at' => now()->subDays(10),
            'description' => 'x', 'filed_by_type' => 'participant',
            'filed_by_name' => 'Self', 'received_by_user_id' => $this->qa->id,
        ]);
        $this->assertEquals('green', $g->agingBand());

        $g->update(['filed_at' => now()->subDays(20)]);
        $this->assertEquals('yellow', $g->fresh()->agingBand());

        $g->update(['filed_at' => now()->subDays(28)]);
        $this->assertEquals('red', $g->fresh()->agingBand());

        $g->update(['filed_at' => now()->subDays(35)]);
        $this->assertEquals('overdue', $g->fresh()->agingBand());
    }

    public function test_grievance_service_creates_day_25_approaching_alert(): void
    {
        Grievance::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'category' => 'quality_of_care',
            'priority' => 'standard',
            'status'   => 'open',
            'filed_at' => now()->subDays(27),       // inside 25-30 window
            'description' => 'x', 'filed_by_type' => 'participant',
            'filed_by_name' => 'Self', 'received_by_user_id' => $this->qa->id,
        ]);

        $result = app(GrievanceService::class)->checkOverdue($this->tenant->id);
        $this->assertGreaterThanOrEqual(1, $result['approaching']);
        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $this->tenant->id,
            'alert_type' => 'grievance_approaching_deadline',
        ]);
    }

    public function test_grievance_approaching_alert_dedupes_within_48h(): void
    {
        Grievance::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'category' => 'quality_of_care', 'priority' => 'standard',
            'status' => 'open',
            'filed_at' => now()->subDays(27),
            'description' => 'x', 'filed_by_type' => 'participant',
            'filed_by_name' => 'Self', 'received_by_user_id' => $this->qa->id,
        ]);

        $svc = app(GrievanceService::class);
        $svc->checkOverdue($this->tenant->id);
        $svc->checkOverdue($this->tenant->id);

        $this->assertEquals(1, Alert::where('alert_type', 'grievance_approaching_deadline')
            ->where('tenant_id', $this->tenant->id)->count());
    }
}

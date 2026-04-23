<?php

// ─── InfectionSurveillanceTest ───────────────────────────────────────────────
// Phase B2 — infection surveillance + outbreak detection.
//  - Case CRUD + tenant isolation
//  - OutbreakDetectionService declares outbreak at ≥3 cases in 7d at same site
//  - Service is idempotent (no duplicate active outbreak for same organism/site)
//  - Service emits critical alert on declaration
//  - Outbreak update flow (contain/end + state reporting timestamp)
//  - Compliance universe returns cases + outbreaks + summary
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\InfectionCase;
use App\Models\InfectionOutbreak;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OutbreakDetectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InfectionSurveillanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $nurse;
    private User $qa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'IN']);
        $this->nurse = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function makeParticipant(): Participant
    {
        return Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_nurse_can_report_infection_case(): void
    {
        $this->actingAs($this->nurse);
        $p = $this->makeParticipant();
        $r = $this->postJson("/participants/{$p->id}/infections", [
            'organism_type' => 'influenza',
            'onset_date'    => now()->subDays(1)->toDateString(),
            'severity'      => 'mild',
            'source'        => 'community',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_infection_cases', [
            'participant_id' => $p->id,
            'organism_type'  => 'influenza',
        ]);
    }

    public function test_outbreak_declared_at_third_case_same_site_within_7_days(): void
    {
        $this->actingAs($this->nurse);

        // First two cases — no outbreak.
        for ($i = 0; $i < 2; $i++) {
            $p = $this->makeParticipant();
            $this->postJson("/participants/{$p->id}/infections", [
                'organism_type' => 'norovirus',
                'onset_date'    => now()->subDays(3 - $i)->toDateString(),
            ])->assertStatus(201);
        }
        $this->assertEquals(0, InfectionOutbreak::where('tenant_id', $this->tenant->id)->count());

        // Third case pushes cluster over threshold → outbreak declared.
        $p3 = $this->makeParticipant();
        $r = $this->postJson("/participants/{$p3->id}/infections", [
            'organism_type' => 'norovirus',
            'onset_date'    => now()->toDateString(),
        ]);
        $r->assertStatus(201);
        $r->assertJsonPath('outbreak_declared.organism_type', 'norovirus');
        $this->assertEquals(1, InfectionOutbreak::where('tenant_id', $this->tenant->id)
            ->where('status', 'active')->count());
    }

    public function test_outbreak_detection_is_idempotent(): void
    {
        /** @var OutbreakDetectionService $svc */
        $svc = app(OutbreakDetectionService::class);

        // Seed 4 cases manually at same site/organism.
        for ($i = 0; $i < 4; $i++) {
            InfectionCase::create([
                'tenant_id'           => $this->tenant->id,
                'participant_id'      => $this->makeParticipant()->id,
                'site_id'             => $this->site->id,
                'organism_type'       => 'covid19',
                'onset_date'          => now()->subDays(4 - $i)->toDateString(),
                'severity'            => 'mild',
                'source'              => 'facility',
                'reported_by_user_id' => $this->nurse->id,
            ]);
        }
        $svc->evaluateTenant($this->tenant->id);
        $svc->evaluateTenant($this->tenant->id); // second run

        $this->assertEquals(1, InfectionOutbreak::where('tenant_id', $this->tenant->id)
            ->where('status', 'active')->where('organism_type', 'covid19')->count());
    }

    public function test_outbreak_declaration_emits_critical_alert(): void
    {
        /** @var OutbreakDetectionService $svc */
        $svc = app(OutbreakDetectionService::class);
        for ($i = 0; $i < 3; $i++) {
            InfectionCase::create([
                'tenant_id'           => $this->tenant->id,
                'participant_id'      => $this->makeParticipant()->id,
                'site_id'             => $this->site->id,
                'organism_type'       => 'rsv',
                'onset_date'          => now()->subDays(2)->toDateString(),
                'severity'            => 'mild',
                'source'              => 'facility',
                'reported_by_user_id' => $this->nurse->id,
            ]);
        }
        $svc->evaluateTenant($this->tenant->id);

        $this->assertTrue(
            Alert::where('tenant_id', $this->tenant->id)
                ->where('alert_type', 'infection_outbreak_declared')
                ->where('severity', 'critical')
                ->exists()
        );
    }

    public function test_cases_outside_7_day_window_do_not_declare_outbreak(): void
    {
        /** @var OutbreakDetectionService $svc */
        $svc = app(OutbreakDetectionService::class);
        for ($i = 0; $i < 3; $i++) {
            InfectionCase::create([
                'tenant_id'           => $this->tenant->id,
                'participant_id'      => $this->makeParticipant()->id,
                'site_id'             => $this->site->id,
                'organism_type'       => 'mrsa',
                // 15/12/9 days ago — outside 7-day window
                'onset_date'          => now()->subDays(15 - ($i * 3))->toDateString(),
                'severity'            => 'mild',
                'source'              => 'facility',
                'reported_by_user_id' => $this->nurse->id,
            ]);
        }
        $svc->evaluateTenant($this->tenant->id);
        $this->assertEquals(0, InfectionOutbreak::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_cases_at_different_sites_do_not_cluster(): void
    {
        /** @var OutbreakDetectionService $svc */
        $svc = app(OutbreakDetectionService::class);
        $site2 = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'AA']);
        $site3 = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'BB']);

        foreach ([$this->site, $site2, $site3] as $s) {
            $p = Participant::factory()->enrolled()
                ->forTenant($this->tenant->id)->forSite($s->id)->create();
            InfectionCase::create([
                'tenant_id'           => $this->tenant->id,
                'participant_id'      => $p->id,
                'site_id'             => $s->id,
                'organism_type'       => 'influenza',
                'onset_date'          => now()->toDateString(),
                'severity'            => 'mild',
                'source'              => 'facility',
                'reported_by_user_id' => $this->nurse->id,
            ]);
        }
        $svc->evaluateTenant($this->tenant->id);
        $this->assertEquals(0, InfectionOutbreak::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_tenant_isolation_on_case_report(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'ZZ']);
        $otherParticipant = Participant::factory()->enrolled()
            ->forTenant($otherTenant->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->nurse);
        $r = $this->postJson("/participants/{$otherParticipant->id}/infections", [
            'organism_type' => 'influenza',
            'onset_date'    => now()->toDateString(),
        ]);
        $r->assertStatus(403);
    }

    public function test_qa_can_update_outbreak_status_and_containment(): void
    {
        $this->actingAs($this->qa);

        $outbreak = InfectionOutbreak::create([
            'tenant_id'     => $this->tenant->id,
            'site_id'       => $this->site->id,
            'organism_type' => 'norovirus',
            'started_at'    => now()->subDays(5),
            'status'        => 'active',
        ]);

        $r = $this->postJson("/infection-outbreaks/{$outbreak->id}/update", [
            'status'                    => 'contained',
            'containment_measures_text' => 'Enhanced env-services cleaning + cohort isolation + staff hand-hygiene reinforcement.',
            'reported_to_state_at'      => now()->toIso8601String(),
        ]);
        $r->assertOk();

        $outbreak->refresh();
        $this->assertEquals('contained', $outbreak->status);
        $this->assertNotNull($outbreak->declared_ended_at);
        $this->assertNotNull($outbreak->reported_to_state_at);
    }

    public function test_case_resolve_sets_resolution_date(): void
    {
        $this->actingAs($this->nurse);
        $p = $this->makeParticipant();
        $case = InfectionCase::create([
            'tenant_id'           => $this->tenant->id,
            'participant_id'      => $p->id,
            'site_id'             => $this->site->id,
            'organism_type'       => 'influenza',
            'onset_date'          => now()->subDays(3)->toDateString(),
            'severity'            => 'mild',
            'source'              => 'community',
            'reported_by_user_id' => $this->nurse->id,
        ]);

        $r = $this->postJson("/infections/{$case->id}/resolve", [
            'resolution_date' => now()->toDateString(),
        ]);
        $r->assertOk();
        $this->assertNotNull($case->fresh()->resolution_date);
    }

    public function test_compliance_universe_returns_cases_and_outbreaks(): void
    {
        /** @var OutbreakDetectionService $svc */
        $svc = app(OutbreakDetectionService::class);
        for ($i = 0; $i < 3; $i++) {
            InfectionCase::create([
                'tenant_id'           => $this->tenant->id,
                'participant_id'      => $this->makeParticipant()->id,
                'site_id'             => $this->site->id,
                'organism_type'       => 'norovirus',
                'onset_date'          => now()->subDays(2)->toDateString(),
                'severity'            => 'moderate',
                'source'              => 'facility',
                'reported_by_user_id' => $this->nurse->id,
            ]);
        }
        $svc->evaluateTenant($this->tenant->id);

        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/infections');
        $r->assertOk();
        $r->assertJsonStructure([
            'cases', 'outbreaks',
            'summary' => ['count_cases', 'count_outbreaks', 'count_outbreaks_active'],
        ]);
        $this->assertEquals(3, $r->json('summary.count_cases'));
        $this->assertEquals(1, $r->json('summary.count_outbreaks'));
    }
}

<?php

// ─── AnticoagulationTest ─────────────────────────────────────────────────────
// Phase B5 — Anticoagulation plans + INR recording + overdue job + drug-lab
// reference seed.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\InrOverdueJob;
use App\Models\Alert;
use App\Models\AnticoagulationPlan;
use App\Models\DrugLabInteraction;
use App\Models\InrResult;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DrugLabInteractionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnticoagulationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'AC']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_pcp_can_create_warfarin_plan_with_target(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent'           => 'warfarin',
            'target_inr_low'  => 2.0,
            'target_inr_high' => 3.0,
            'start_date'      => now()->toDateString(),
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_anticoagulation_plans', [
            'participant_id' => $this->participant->id,
            'agent'          => 'warfarin',
        ]);
    }

    public function test_warfarin_plan_without_target_rejected(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent'      => 'warfarin',
            'start_date' => now()->toDateString(),
        ]);
        $r->assertStatus(422);
        $this->assertEquals('warfarin_requires_inr_target', $r->json('error'));
    }

    public function test_doac_plan_does_not_require_inr_target(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent'      => 'apixaban',
            'start_date' => now()->toDateString(),
        ]);
        $r->assertStatus(201);
    }

    public function test_recording_in_range_inr_does_not_alert(): void
    {
        AnticoagulationPlan::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'agent' => 'warfarin', 'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
            'start_date' => now()->subDays(60),
        ]);

        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/inr", [
            'value'    => 2.5,
            'drawn_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $this->assertFalse(Alert::where('alert_type', 'inr_out_of_range')->exists());
    }

    public function test_out_of_range_inr_emits_warning_alert(): void
    {
        AnticoagulationPlan::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'agent' => 'warfarin', 'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
            'start_date' => now()->subDays(60),
        ]);

        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/inr", [
            'value'    => 3.3,
            'drawn_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $alert = Alert::where('alert_type', 'inr_out_of_range')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('warning', $alert->severity);
    }

    public function test_critical_inr_emits_critical_alert(): void
    {
        AnticoagulationPlan::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'agent' => 'warfarin', 'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
            'start_date' => now()->subDays(60),
        ]);

        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/inr", [
            'value'    => 6.2,
            'drawn_at' => now()->toIso8601String(),
        ])->assertStatus(201);

        $alert = Alert::where('alert_type', 'inr_out_of_range')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_cross_tenant_plan_creation_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()
            ->forTenant($other->id)->forSite($otherSite->id)->create();

        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$otherP->id}/anticoagulation/plans", [
            'agent' => 'apixaban', 'start_date' => now()->toDateString(),
        ]);
        $r->assertStatus(403);
    }

    public function test_overdue_job_alerts_active_warfarin_with_no_recent_inr(): void
    {
        $plan = AnticoagulationPlan::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'agent' => 'warfarin', 'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
            'monitoring_interval_days' => 30,
            'start_date' => now()->subDays(120),
        ]);
        InrResult::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'anticoagulation_plan_id' => $plan->id,
            'drawn_at' => now()->subDays(45), // past interval
            'value' => 2.4,
        ]);

        (new InrOverdueJob())->handle(app(\App\Services\AlertService::class));

        $this->assertTrue(Alert::where('alert_type', 'inr_overdue')
            ->whereRaw("(metadata->>'plan_id')::int = ?", [$plan->id])
            ->exists());
    }

    public function test_overdue_job_skips_doacs(): void
    {
        AnticoagulationPlan::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'agent' => 'apixaban',
            'start_date' => now()->subDays(90),
        ]);
        (new InrOverdueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertFalse(Alert::where('alert_type', 'inr_overdue')->exists());
    }

    public function test_drug_lab_interaction_seeder_populates_reference(): void
    {
        (new DrugLabInteractionSeeder())->run();
        $this->assertGreaterThanOrEqual(15, DrugLabInteraction::count());
        $this->assertTrue(DrugLabInteraction::where('drug_keyword', 'warfarin')
            ->where('lab_name', 'INR')->exists());
    }

    public function test_drug_lab_lookup_matches_by_keyword(): void
    {
        (new DrugLabInteractionSeeder())->run();
        $matches = DrugLabInteraction::forDrugName('Warfarin 5 mg tablet');
        $this->assertGreaterThan(0, $matches->count());
        $this->assertEquals('INR', $matches->first()->lab_name);
    }
}

<?php

// ─── Phase I2 — Participant header chips + BCMA scan + Sentinel classify ────
// Component rendering is out-of-scope for feature tests; this verifies the
// backend endpoints the chips + modals consume are shaped as the UI expects
// and that the sentinel-classify auth gate works correctly.

namespace Tests\Feature;

use App\Models\AnticoagulationPlan;
use App\Models\Alert;
use App\Models\BeersCriterion;
use App\Models\CareGap;
use App\Models\EmarRecord;
use App\Models\Incident;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\PredictiveRiskScore;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\BeersCriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I2ClinicalLaunchChipsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private User $qa;
    private User $exec;
    private User $pharm;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'I2']);
        $this->pcp  = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->qa   = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->exec = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'executive', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->pharm= User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'pharmacy', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    /* ─── Header chips — data shape endpoints ─── */

    public function test_beers_flags_endpoint_returns_array(): void
    {
        (new BeersCriteriaSeeder())->run();
        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Diphenhydramine 25mg', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/beers-flags");
        $r->assertOk();
        $flags = $r->json('flags') ?? $r->json('rows') ?? [];
        $this->assertNotEmpty($flags, 'Header Beers chip consumes the flags array from this endpoint');
    }

    public function test_predictive_risk_endpoint_returns_latest_structure(): void
    {
        PredictiveRiskScore::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'model_version' => 'g8-v1-demo', 'risk_type' => 'acute_event',
            'score' => 78, 'band' => 'high', 'factors' => ['lace' => ['value' => 0.5]],
            'computed_at' => now(),
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/predictive-risk");
        $r->assertOk();
        $this->assertArrayHasKey('latest', $r->json());
    }

    public function test_care_gaps_endpoint_returns_gaps_list_with_satisfied_flag(): void
    {
        CareGap::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'measure' => 'annual_pcp_visit', 'satisfied' => false, 'calculated_at' => now(),
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/care-gaps");
        $r->assertOk();
        $gaps = $r->json('gaps');
        $this->assertIsArray($gaps);
        $this->assertArrayHasKey('satisfied', $gaps[0]);
    }

    /* ─── Sentinel classify auth gate ─── */

    public function test_sentinel_classify_works_for_qa(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->qa);
        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Unexpected hypoxic event requiring escalation.',
        ]);
        $r->assertOk();
        $this->assertTrue((bool) $incident->fresh()->is_sentinel);
    }

    public function test_sentinel_classify_works_for_executive(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->exec);
        $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Executive classification per leadership directive.',
        ])->assertOk();
    }

    public function test_sentinel_classify_403_for_pharmacist(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->pharm);
        $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Attempting a disallowed classification.',
        ])->assertStatus(403);
    }

    /* ─── BCMA scan-verify (I2 opens the modal; endpoint covered in B4 tests) ─── */

    public function test_bcma_scan_verify_ok_path_accessible_from_emar_flow(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Lisinopril', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        $record = EmarRecord::factory()
            ->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['medication_id' => $med->id, 'scheduled_time' => now(), 'status' => 'scheduled']);
        $this->actingAs($this->pcp);
        $r = $this->postJson("/emar/{$record->id}/scan-verify", [
            'participant_barcode' => $this->participant->fresh()->barcode_value,
            'medication_barcode' => $med->fresh()->barcode_value,
        ]);
        $r->assertOk();
        $r->assertJsonPath('status', 'ok');
    }

    private function makeIncident(): Incident
    {
        return Incident::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'incident_type' => 'fall',
            'occurred_at' => now()->subDays(1),
        ]);
    }
}

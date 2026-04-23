<?php

// ─── RestraintTest ────────────────────────────────────────────────────────────
// Phase B1 — restraint documentation.
//  - Episode CRUD + lifecycle (initiate → observe → discontinue → IDT review)
//  - Chemical requires ordering provider
//  - Tenant isolation
//  - RestraintMonitoringOverdueJob fires warning at 4h without observation
//  - RestraintMonitoringOverdueJob fires critical at 24h without IDT review
//  - Compliance universe returns rows + summary
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\RestraintMonitoringOverdueJob;
use App\Models\Alert;
use App\Models\Participant;
use App\Models\RestraintEpisode;
use App\Models\RestraintMonitoringObservation;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestraintTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $nurse;
    private User $provider;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'RX']);
        $this->nurse    = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'home_care',      'role' => 'admin', 'is_active' => true]);
        $this->provider = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true]);
        $this->qa       = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance','role' => 'admin', 'is_active' => true]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    // ── Episode CRUD ────────────────────────────────────────────────────────

    public function test_nurse_can_initiate_physical_restraint(): void
    {
        $this->actingAs($this->nurse);
        $r = $this->postJson("/participants/{$this->participant->id}/restraints", [
            'restraint_type' => 'physical',
            'reason_text'    => 'Participant attempting to remove central line — safety concern.',
            'alternatives_tried_text' => '1-on-1 sitter unsuccessful; family not available.',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_restraint_episodes', [
            'participant_id' => $this->participant->id,
            'restraint_type' => 'physical',
            'status'         => 'active',
        ]);
    }

    public function test_chemical_restraint_requires_ordering_provider(): void
    {
        $this->actingAs($this->nurse);
        $r = $this->postJson("/participants/{$this->participant->id}/restraints", [
            'restraint_type' => 'chemical',
            'reason_text'    => 'Severe agitation unresponsive to verbal de-escalation attempts.',
        ]);
        $r->assertStatus(422);
        $this->assertEquals('ordering_provider_required', $r->json('error'));
    }

    public function test_chemical_restraint_succeeds_with_provider(): void
    {
        $this->actingAs($this->nurse);
        $r = $this->postJson("/participants/{$this->participant->id}/restraints", [
            'restraint_type' => 'chemical',
            'reason_text'    => 'Severe agitation unresponsive to verbal de-escalation attempts.',
            'ordering_provider_user_id' => $this->provider->id,
            'medication_text' => 'haloperidol 2mg IM x1',
        ]);
        $r->assertStatus(201);
    }

    public function test_discontinue_requires_reason(): void
    {
        $ep = $this->makeEpisode();
        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/restraints/{$ep->id}/discontinue", [])
            ->assertStatus(422);
    }

    public function test_discontinue_sets_status_and_timestamp(): void
    {
        $ep = $this->makeEpisode();
        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/restraints/{$ep->id}/discontinue", [
            'discontinuation_reason' => 'Participant calm, situation resolved, resumed baseline.',
        ])->assertOk();
        $this->assertDatabaseHas('emr_restraint_episodes', [
            'id' => $ep->id,
            'status' => 'discontinued',
        ]);
    }

    public function test_observation_only_on_active_episode(): void
    {
        $ep = $this->makeEpisode();
        $ep->update(['status' => 'discontinued', 'discontinued_at' => now(), 'discontinued_by_user_id' => $this->nurse->id, 'discontinuation_reason' => 'done']);

        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/restraints/{$ep->id}/observations", [
            'skin_integrity' => 'intact',
        ])->assertStatus(409);
    }

    public function test_observation_records_successfully(): void
    {
        $ep = $this->makeEpisode();
        $this->actingAs($this->nurse);
        $this->postJson("/participants/{$this->participant->id}/restraints/{$ep->id}/observations", [
            'skin_integrity' => 'intact',
            'circulation'    => 'adequate',
            'mental_status'  => 'calm',
            'hydration_offered' => true,
        ])->assertStatus(201);
        $this->assertEquals(1, $ep->observations()->count());
    }

    public function test_idt_review_recorded(): void
    {
        $ep = $this->makeEpisode();
        $this->actingAs($this->qa);
        $this->postJson("/participants/{$this->participant->id}/restraints/{$ep->id}/idt-review", [
            'outcome_text' => 'IDT reviewed episode; concur with nursing judgment. No pattern.',
        ])->assertOk();
        $this->assertDatabaseHas('emr_restraint_episodes', [
            'id' => $ep->id,
            'idt_review_user_id' => $this->qa->id,
        ]);
    }

    public function test_tenant_isolation_blocks_cross_tenant_access(): void
    {
        $other = Tenant::factory()->create();
        $outsider = User::factory()->create([
            'tenant_id' => $other->id, 'department' => 'home_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $ep = $this->makeEpisode();
        $this->actingAs($outsider);
        $this->getJson("/participants/{$this->participant->id}/restraints")->assertForbidden();
    }

    // ── Alert job ───────────────────────────────────────────────────────────

    public function test_job_fires_monitoring_overdue_warning_after_4_hours(): void
    {
        $ep = $this->makeEpisode();
        $ep->update(['initiated_at' => now()->subHours(5)]); // 5h with no observation

        (new RestraintMonitoringOverdueJob())->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $this->tenant->id,
            'alert_type' => 'restraint_monitoring_overdue',
            'severity'   => 'warning',
        ]);
    }

    public function test_job_fires_idt_overdue_critical_after_24_hours(): void
    {
        $ep = $this->makeEpisode();
        $ep->update(['initiated_at' => now()->subHours(26)]);

        (new RestraintMonitoringOverdueJob())->handle(app(\App\Services\AlertService::class));

        $this->assertDatabaseHas('emr_alerts', [
            'tenant_id'  => $this->tenant->id,
            'alert_type' => 'restraint_idt_review_overdue',
            'severity'   => 'critical',
        ]);
    }

    public function test_job_dedupes_within_window(): void
    {
        $ep = $this->makeEpisode();
        $ep->update(['initiated_at' => now()->subHours(26)]);
        $svc = app(\App\Services\AlertService::class);
        (new RestraintMonitoringOverdueJob())->handle($svc);
        (new RestraintMonitoringOverdueJob())->handle($svc);

        $this->assertEquals(1, Alert::where('alert_type', 'restraint_monitoring_overdue')->count());
        $this->assertEquals(1, Alert::where('alert_type', 'restraint_idt_review_overdue')->count());
    }

    // ── Compliance universe ─────────────────────────────────────────────────

    public function test_compliance_restraints_universe_returns_rows_and_summary(): void
    {
        $this->makeEpisode();
        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/restraints');
        $r->assertOk();
        $r->assertJsonStructure([
            'rows' => [['id', 'participant', 'restraint_type', 'status', 'observations_count']],
            'summary' => ['count_total', 'count_active', 'count_physical', 'count_chemical',
                          'count_idt_overdue', 'count_monitoring_overdue', 'window_start', 'window_end'],
        ]);
        $this->assertGreaterThanOrEqual(1, $r->json('summary.count_total'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeEpisode(): RestraintEpisode
    {
        return RestraintEpisode::create([
            'tenant_id'             => $this->tenant->id,
            'participant_id'        => $this->participant->id,
            'restraint_type'        => 'physical',
            'initiated_at'          => now(),
            'initiated_by_user_id'  => $this->nurse->id,
            'reason_text'           => 'Baseline test reason text that is long enough.',
            'monitoring_interval_min' => 15,
            'status'                => 'active',
        ]);
    }
}

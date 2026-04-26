<?php

// ─── SentinelEventTest ───────────────────────────────────────────────────────
// Phase B3 — sentinel event classification + dual deadline enforcement.
//  - QA can classify an incident as sentinel (auto-sets deadlines + forces rca_required)
//  - Non-QA / non-exec dept cannot classify (403)
//  - Double-classification returns 409
//  - Tenant isolation on classification
//  - SentinelEventDeadlineJob fires warning at T-2 days CMS
//  - SentinelEventDeadlineJob fires critical when CMS deadline missed
//  - SentinelEventDeadlineJob fires critical when RCA deadline missed
//  - Alert dedup within window
//  - Compliance universe returns rows + summary
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\SentinelEventDeadlineJob;
use App\Models\Alert;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FreezesTime;
use Tests\TestCase;

class SentinelEventTest extends TestCase
{
    use FreezesTime;
    use RefreshDatabase;

    private Tenant $tenant;
    private User $qa;
    private User $exec;
    private User $clinician;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreezesTime();
        $this->tenant = Tenant::factory()->create();
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->exec = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'executive',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->clinician = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function makeIncident(array $overrides = []): Incident
    {
        $p = Participant::factory()->create(['tenant_id' => $this->tenant->id]);
        return Incident::factory()->create(array_merge([
            'tenant_id'      => $this->tenant->id,
            'participant_id' => $p->id,
            'incident_type'  => 'fall',
            'occurred_at'    => now()->subDays(1),
        ], $overrides));
    }

    public function test_qa_can_classify_incident_as_sentinel(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->qa);

        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Severe temporary harm resulting from medication administration error.',
        ]);
        $r->assertOk();

        $incident->refresh();
        $this->assertTrue((bool) $incident->is_sentinel);
        $this->assertNotNull($incident->sentinel_classified_at);
        $this->assertNotNull($incident->sentinel_cms_5day_deadline);
        $this->assertNotNull($incident->sentinel_rca_30day_deadline);
        $this->assertTrue((bool) $incident->rca_required);
        $this->assertEquals($this->qa->id, $incident->sentinel_classified_by_user_id);
    }

    public function test_executive_can_classify_incident_as_sentinel(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->exec);
        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Executive classification for aggregate risk monitoring.',
        ]);
        $r->assertOk();
    }

    public function test_primary_care_cannot_classify_sentinel(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->clinician);
        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Attempting a disallowed classification request.',
        ]);
        $r->assertStatus(403);
    }

    public function test_reason_is_required_min_10_chars(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->qa);
        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'short',
        ]);
        $r->assertStatus(422);
    }

    public function test_double_classification_returns_409(): void
    {
        $incident = $this->makeIncident();
        $this->actingAs($this->qa);
        $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'First classification — legitimate sentinel event.',
        ])->assertOk();

        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Second classification attempt — should be rejected.',
        ]);
        $r->assertStatus(409);
    }

    public function test_cross_tenant_classification_is_blocked(): void
    {
        $otherTenant = Tenant::factory()->create();
        $p = Participant::factory()->create(['tenant_id' => $otherTenant->id]);
        $incident = Incident::factory()->create([
            'tenant_id' => $otherTenant->id,
            'participant_id' => $p->id,
        ]);

        $this->actingAs($this->qa);
        $r = $this->postJson("/qa/incidents/{$incident->id}/classify-sentinel", [
            'reason' => 'Cross-tenant attempt should fail.',
        ]);
        $r->assertStatus(403);
    }

    public function test_job_fires_warning_when_cms_deadline_approaching(): void
    {
        $incident = $this->makeIncident([
            'is_sentinel'                => true,
            'sentinel_classified_at'     => now()->subDays(3),
            'sentinel_cms_5day_deadline' => now()->addDay(), // 24h away < 48h threshold
            'sentinel_rca_30day_deadline'=> now()->addDays(27),
        ]);

        (new SentinelEventDeadlineJob())->handle(app(\App\Services\AlertService::class));

        $this->assertTrue(Alert::where('alert_type', 'sentinel_cms_deadline_approaching')
            ->whereRaw("(metadata->>'incident_id')::int = ?", [$incident->id])
            ->exists());
    }

    public function test_job_fires_critical_when_cms_deadline_missed(): void
    {
        $incident = $this->makeIncident([
            'is_sentinel'                => true,
            'sentinel_classified_at'     => now()->subDays(10),
            'sentinel_cms_5day_deadline' => now()->subDays(5),
            'sentinel_rca_30day_deadline'=> now()->addDays(20),
        ]);

        (new SentinelEventDeadlineJob())->handle(app(\App\Services\AlertService::class));

        $alert = Alert::where('alert_type', 'sentinel_cms_deadline_missed')
            ->whereRaw("(metadata->>'incident_id')::int = ?", [$incident->id])
            ->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_job_fires_critical_when_rca_deadline_missed(): void
    {
        $incident = $this->makeIncident([
            'is_sentinel'                 => true,
            'sentinel_classified_at'      => now()->subDays(35),
            'sentinel_cms_5day_deadline'  => now()->subDays(30),
            'cms_notification_sent_at'    => now()->subDays(28), // CMS satisfied
            'sentinel_rca_30day_deadline' => now()->subDays(5),
        ]);

        (new SentinelEventDeadlineJob())->handle(app(\App\Services\AlertService::class));

        $alert = Alert::where('alert_type', 'sentinel_rca_deadline_missed')
            ->whereRaw("(metadata->>'incident_id')::int = ?", [$incident->id])
            ->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_job_dedupes_within_window(): void
    {
        $incident = $this->makeIncident([
            'is_sentinel'                => true,
            'sentinel_classified_at'     => now()->subDays(10),
            'sentinel_cms_5day_deadline' => now()->subDays(5),
            'sentinel_rca_30day_deadline'=> now()->addDays(20),
        ]);

        $svc = app(\App\Services\AlertService::class);
        (new SentinelEventDeadlineJob())->handle($svc);
        (new SentinelEventDeadlineJob())->handle($svc);

        $count = Alert::where('alert_type', 'sentinel_cms_deadline_missed')
            ->whereRaw("(metadata->>'incident_id')::int = ?", [$incident->id])
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_compliance_universe_returns_sentinel_rows(): void
    {
        $this->makeIncident([
            'is_sentinel'                => true,
            'sentinel_classified_at'     => now()->subDays(1),
            'sentinel_classified_by_user_id' => $this->qa->id,
            'sentinel_cms_5day_deadline' => now()->addDays(4),
            'sentinel_rca_30day_deadline'=> now()->addDays(29),
            'rca_required'               => true,
        ]);
        $this->makeIncident([
            'is_sentinel'                => true,
            'sentinel_classified_at'     => now()->subDays(10),
            'sentinel_classified_by_user_id' => $this->qa->id,
            'sentinel_cms_5day_deadline' => now()->subDays(5),
            'sentinel_rca_30day_deadline'=> now()->addDays(20),
            'rca_required'               => true,
        ]);

        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/sentinel-events');
        $r->assertOk();
        $r->assertJsonStructure([
            'rows', 'summary' => ['count_total', 'count_cms_missed', 'count_rca_missed', 'count_rca_pending'],
        ]);
        $this->assertEquals(2, $r->json('summary.count_total'));
        $this->assertEquals(1, $r->json('summary.count_cms_missed'));
    }
}

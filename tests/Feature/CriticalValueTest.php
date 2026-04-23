<?php

// ─── CriticalValueTest ───────────────────────────────────────────────────────
// Phase B6 — vital threshold evaluation, ack workflow, escalation job.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\CriticalValueEscalationJob;
use App\Models\Alert;
use App\Models\CriticalValueAcknowledgment;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use App\Models\VitalThreshold;
use App\Services\CriticalValueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CriticalValueTest extends TestCase
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
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'CV']);
        $this->pcp = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    private function postVital(array $payload)
    {
        return $this->actingAs($this->pcp)
            ->postJson("/participants/{$this->participant->id}/vitals", array_merge([
                'recorded_at' => now()->toIso8601String(),
            ], $payload));
    }

    public function test_normal_vital_creates_no_ack(): void
    {
        $this->postVital(['bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse' => 72])
            ->assertStatus(201);
        $this->assertEquals(0, CriticalValueAcknowledgment::count());
    }

    public function test_critical_bp_creates_ack_with_2h_deadline(): void
    {
        $r = $this->postVital(['bp_systolic' => 200, 'pulse' => 72]);
        $r->assertStatus(201);

        $ack = CriticalValueAcknowledgment::first();
        $this->assertNotNull($ack);
        $this->assertEquals('critical', $ack->severity);
        $this->assertEquals('high', $ack->direction);
        $this->assertEquals('bp_systolic', $ack->field_name);
        // 2h deadline window
        $this->assertTrue($ack->deadline_at->between(now()->addMinutes(115), now()->addMinutes(125)));
    }

    public function test_warning_range_creates_warning_ack_with_8h_deadline(): void
    {
        $this->postVital(['bp_systolic' => 170, 'pulse' => 72])->assertStatus(201);
        $ack = CriticalValueAcknowledgment::first();
        $this->assertNotNull($ack);
        $this->assertEquals('warning', $ack->severity);
        $this->assertEquals('high', $ack->direction);
        $this->assertTrue($ack->deadline_at->between(now()->addMinutes(475), now()->addMinutes(485)));
    }

    public function test_tenant_override_overrides_default(): void
    {
        VitalThreshold::create([
            'tenant_id' => $this->tenant->id,
            'vital_field' => 'bp_systolic',
            'warning_low' => 100, 'warning_high' => 140,
            'critical_low' => 85, 'critical_high' => 160,
        ]);
        // With overridden thresholds, 170 is now critical (default critical_high is 180).
        $this->postVital(['bp_systolic' => 170])->assertStatus(201);
        $ack = CriticalValueAcknowledgment::first();
        $this->assertEquals('critical', $ack->severity);
    }

    public function test_critical_vital_emits_critical_alert(): void
    {
        $this->postVital(['bp_systolic' => 200])->assertStatus(201);
        $alert = Alert::where('alert_type', 'critical_value_flagged')->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_multiple_out_of_range_fields_create_one_ack_each(): void
    {
        $this->postVital([
            'bp_systolic'   => 200, // critical high
            'o2_saturation' => 85,  // critical low
            'pulse'         => 72,  // normal
        ])->assertStatus(201);

        $this->assertEquals(2, CriticalValueAcknowledgment::count());
    }

    public function test_acknowledge_sets_acknowledged_at(): void
    {
        $this->postVital(['bp_systolic' => 200])->assertStatus(201);
        $ack = CriticalValueAcknowledgment::first();

        $r = $this->actingAs($this->pcp)
            ->postJson("/critical-values/{$ack->id}/acknowledge", [
                'action_taken_text' => 'Started antihypertensive, BP recheck in 30 min, attending notified.',
            ]);
        $r->assertOk();
        $ack->refresh();
        $this->assertNotNull($ack->acknowledged_at);
        $this->assertEquals($this->pcp->id, $ack->acknowledged_by_user_id);
    }

    public function test_double_acknowledge_returns_409(): void
    {
        $this->postVital(['bp_systolic' => 200])->assertStatus(201);
        $ack = CriticalValueAcknowledgment::first();
        $this->actingAs($this->pcp)->postJson("/critical-values/{$ack->id}/acknowledge", [
            'action_taken_text' => 'First ack; taking action.',
        ])->assertOk();

        $this->actingAs($this->pcp)->postJson("/critical-values/{$ack->id}/acknowledge", [
            'action_taken_text' => 'Second attempt should fail.',
        ])->assertStatus(409);
    }

    public function test_escalation_job_escalates_past_deadline(): void
    {
        $ack = CriticalValueAcknowledgment::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'field_name' => 'bp_systolic',
            'value' => 210, 'severity' => 'critical', 'direction' => 'high',
            'deadline_at' => now()->subHours(3),
        ]);
        (new CriticalValueEscalationJob())->handle(app(\App\Services\AlertService::class));
        $ack->refresh();
        $this->assertNotNull($ack->escalated_at);
        $this->assertTrue(Alert::where('alert_type', 'critical_value_escalation')->exists());
    }

    public function test_escalation_job_is_idempotent(): void
    {
        $ack = CriticalValueAcknowledgment::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'field_name' => 'bp_systolic',
            'value' => 210, 'severity' => 'critical', 'direction' => 'high',
            'deadline_at' => now()->subHours(3),
        ]);
        (new CriticalValueEscalationJob())->handle(app(\App\Services\AlertService::class));
        (new CriticalValueEscalationJob())->handle(app(\App\Services\AlertService::class));
        $this->assertEquals(1, Alert::where('alert_type', 'critical_value_escalation')->count());
    }

    public function test_cross_tenant_ack_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($this->site->id)->create();
        $ack = CriticalValueAcknowledgment::create([
            'tenant_id' => $other->id,
            'participant_id' => $otherP->id,
            'field_name' => 'bp_systolic',
            'value' => 200, 'severity' => 'critical', 'direction' => 'high',
            'deadline_at' => now()->addHours(2),
        ]);
        $this->actingAs($this->pcp)->postJson("/critical-values/{$ack->id}/acknowledge", [
            'action_taken_text' => 'Should fail as cross tenant.',
        ])->assertStatus(403);
    }
}

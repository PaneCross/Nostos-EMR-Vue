<?php

// ─── RoiRequestTest ──────────────────────────────────────────────────────────
// Phase B8b — HIPAA §164.524 Release of Information workflow.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\RoiDeadlineAlertJob;
use App\Models\Alert;
use App\Models\Participant;
use App\Models\RoiRequest;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FreezesTime;
use Tests\TestCase;

class RoiRequestTest extends TestCase
{
    use FreezesTime;
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFreezesTime();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'RO']);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_qa_can_create_roi_request_and_due_by_is_auto_set(): void
    {
        $this->actingAs($this->qa);
        $r = $this->postJson("/participants/{$this->participant->id}/roi-requests", [
            'requestor_type'          => 'self',
            'requestor_name'          => 'Alice Testpatient',
            'records_requested_scope' => 'All visit notes 2024-2025',
        ]);
        $r->assertStatus(201);
        $roi = RoiRequest::first();
        $this->assertEquals(30, (int) abs(round($roi->requested_at->diffInDays($roi->due_by))));
    }

    public function test_roi_transitions_follow_state_machine(): void
    {
        $this->actingAs($this->qa);
        $roi = RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'scope',
            'requested_at' => now(), 'due_by' => now()->addDays(30),
            'status' => 'pending',
        ]);

        // pending → in_progress OK
        $this->postJson("/roi-requests/{$roi->id}/update-status", ['status' => 'in_progress'])->assertOk();
        // in_progress → fulfilled OK
        $this->postJson("/roi-requests/{$roi->id}/update-status", ['status' => 'fulfilled'])->assertOk();
        $roi->refresh();
        $this->assertNotNull($roi->fulfilled_at);
        $this->assertEquals($this->qa->id, $roi->fulfilled_by_user_id);

        // fulfilled → anything is invalid
        $r = $this->postJson("/roi-requests/{$roi->id}/update-status", ['status' => 'denied',
            'denial_reason' => 'After the fact']);
        $r->assertStatus(422);
        $this->assertEquals('invalid_transition', $r->json('error'));
    }

    public function test_denial_requires_reason(): void
    {
        $roi = RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now(), 'due_by' => now()->addDays(30), 'status' => 'pending',
        ]);
        $this->actingAs($this->qa);
        $r = $this->postJson("/roi-requests/{$roi->id}/update-status", ['status' => 'denied']);
        $r->assertStatus(422);
        $this->assertEquals('denial_reason_required', $r->json('error'));
    }

    public function test_approaching_deadline_fires_warning(): void
    {
        RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now()->subDays(27),
            'due_by' => now()->addDays(3), // 3 days remaining < 5
            'status' => 'in_progress',
        ]);
        (new RoiDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));
        $this->assertTrue(Alert::where('alert_type', 'roi_deadline_approaching')->exists());
        $this->assertFalse(Alert::where('alert_type', 'roi_deadline_overdue')->exists());
    }

    public function test_overdue_fires_critical_alert(): void
    {
        $roi = RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now()->subDays(35),
            'due_by' => now()->subDays(5),
            'status' => 'in_progress',
        ]);
        (new RoiDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));
        $alert = Alert::where('alert_type', 'roi_deadline_overdue')
            ->whereRaw("(metadata->>'roi_request_id')::int = ?", [$roi->id])->first();
        $this->assertNotNull($alert);
        $this->assertEquals('critical', $alert->severity);
    }

    public function test_job_dedupes_within_window(): void
    {
        RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now()->subDays(35), 'due_by' => now()->subDays(5),
            'status' => 'in_progress',
        ]);
        (new RoiDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));
        (new RoiDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));
        $this->assertEquals(1, Alert::where('alert_type', 'roi_deadline_overdue')->count());
    }

    public function test_terminal_status_does_not_alert(): void
    {
        RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now()->subDays(35), 'due_by' => now()->subDays(5),
            'status' => 'fulfilled', 'fulfilled_at' => now()->subDays(1),
        ]);
        (new RoiDeadlineAlertJob())->handle(app(\App\Services\AlertService::class));
        $this->assertFalse(Alert::where('alert_type', 'roi_deadline_overdue')->exists());
    }

    public function test_compliance_universe_returns_roi_rows(): void
    {
        RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'x',
            'requested_at' => now(), 'due_by' => now()->addDays(30), 'status' => 'pending',
        ]);
        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/roi');
        $r->assertOk();
        $this->assertEquals(1, $r->json('summary.count_total'));
        $this->assertEquals(1, $r->json('summary.count_open'));
    }

    public function test_cross_tenant_roi_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($otherSite->id)->create();
        $this->actingAs($this->qa);
        $r = $this->postJson("/participants/{$otherP->id}/roi-requests", [
            'requestor_type' => 'self', 'requestor_name' => 'X', 'records_requested_scope' => 'x',
        ]);
        $r->assertStatus(403);
    }
}

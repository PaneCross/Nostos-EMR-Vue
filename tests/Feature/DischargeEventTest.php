<?php

namespace Tests\Feature;

use App\Jobs\DischargeChecklistOverdueJob;
use App\Models\Alert;
use App\Models\DischargeEvent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DischargeEventTest extends TestCase
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
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'DC']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_creating_event_seeds_full_checklist(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/discharge-events", [
            'discharge_from_facility' => 'Mercy General',
            'discharged_on' => now()->toDateString(),
        ]);
        $r->assertStatus(201);
        $event = DischargeEvent::first();
        $this->assertCount(8, $event->checklist);
        $this->assertContains('med_reconciliation', array_column($event->checklist, 'key'));
    }

    public function test_complete_item_marks_timestamp_and_user(): void
    {
        $event = DischargeEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'discharge_from_facility' => 'x', 'discharged_on' => now(),
            'checklist' => DischargeEvent::buildDefaultChecklist(now()),
            'created_by_user_id' => $this->pcp->id,
        ]);
        $this->actingAs($this->pcp);
        $this->postJson("/discharge-events/{$event->id}/items/med_reconciliation/complete", [
            'notes' => 'Done in 2h.',
        ])->assertOk();
        $event->refresh();
        $item = collect($event->checklist)->firstWhere('key', 'med_reconciliation');
        $this->assertNotNull($item['completed_at']);
        $this->assertEquals($this->pcp->id, $item['completed_by_user_id']);
    }

    public function test_double_complete_returns_409(): void
    {
        $event = DischargeEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'discharge_from_facility' => 'x', 'discharged_on' => now(),
            'checklist' => DischargeEvent::buildDefaultChecklist(now()),
            'created_by_user_id' => $this->pcp->id,
        ]);
        $this->actingAs($this->pcp);
        $this->postJson("/discharge-events/{$event->id}/items/pcp_followup/complete", [])->assertOk();
        $this->postJson("/discharge-events/{$event->id}/items/pcp_followup/complete", [])
            ->assertStatus(409);
    }

    public function test_overdue_job_alerts_owning_department(): void
    {
        DischargeEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'discharge_from_facility' => 'x', 'discharged_on' => now()->subDays(5),
            'checklist' => DischargeEvent::buildDefaultChecklist(now()->subDays(5)),
            'created_by_user_id' => $this->pcp->id,
        ]);
        (new DischargeChecklistOverdueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertTrue(Alert::where('alert_type', 'discharge_checklist_overdue')->count() > 0);
    }

    public function test_cross_tenant_blocked(): void
    {
        $other = Tenant::factory()->create();
        $oSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XX']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($oSite->id)->create();
        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$otherP->id}/discharge-events", [
            'discharge_from_facility' => 'x', 'discharged_on' => now()->toDateString(),
        ])->assertStatus(403);
    }
}

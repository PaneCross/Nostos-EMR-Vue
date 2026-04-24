<?php

// ─── Phase J3 — Hospice + Discharge tabs ────────────────────────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class J3TransitionTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'J3']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_hospice_refer_transitions_status(): void
    {
        $this->actingAs($this->user);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/refer", [
            'hospice_provider_text' => 'Acme Hospice',
        ]);
        $r->assertOk();
        $this->assertEquals('referred', $this->participant->fresh()->hospice_status);
    }

    public function test_hospice_enroll_creates_comfort_care_bundle(): void
    {
        $this->actingAs($this->user);
        $r = $this->postJson("/participants/{$this->participant->id}/hospice/enroll", [
            'hospice_started_at' => now()->toDateString(),
        ]);
        $r->assertStatus(201);
        $this->assertEquals('enrolled', $this->participant->fresh()->hospice_status);
    }

    public function test_discharge_index_and_store_and_complete_item(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/discharge-events")
            ->assertOk()->assertJsonStructure(['events']);

        $r = $this->postJson("/participants/{$this->participant->id}/discharge-events", [
            'discharge_from_facility' => 'Acme Hospital',
            'discharged_on' => now()->subDay()->toDateString(),
        ]);
        $r->assertStatus(201);
        $eventId = $r->json('event.id');
        $checklist = $r->json('event.checklist');
        $this->assertIsArray($checklist);
        $this->assertNotEmpty($checklist);

        $firstKey = $checklist[0]['key'];
        $this->postJson("/discharge-events/{$eventId}/items/{$firstKey}/complete", [])
            ->assertOk();
    }
}

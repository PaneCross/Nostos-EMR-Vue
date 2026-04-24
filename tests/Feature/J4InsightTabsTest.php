<?php

// ─── Phase J4 — Care Gaps + Goals + Predictive + Timeline tabs ─────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class J4InsightTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'J4']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_care_gaps_endpoint(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/care-gaps")
            ->assertOk()->assertJsonStructure(['gaps']);
    }

    public function test_goals_of_care_index_and_store(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/goals-of-care")
            ->assertOk()->assertJsonStructure(['conversations']);

        $this->postJson("/participants/{$this->participant->id}/goals-of-care", [
            'conversation_date' => now()->toDateString(),
            'discussion_summary' => 'Discussed CPR preferences with participant.',
        ])->assertSuccessful();
    }

    public function test_predictive_risk_endpoint(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/predictive-risk")
            ->assertOk()->assertJsonStructure(['latest', 'history']);
    }

    public function test_timeline_endpoint(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/timeline")
            ->assertOk()->assertJsonStructure(['timeline']);
    }
}

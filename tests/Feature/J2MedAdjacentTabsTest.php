<?php

// ─── Phase J2 — Anticoagulation + ADE tabs ──────────────────────────────────
namespace Tests\Feature;

use App\Models\AdverseDrugEvent;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class J2MedAdjacentTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'J2']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'pharmacy',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_anticoag_index_returns_trend_shape(): void
    {
        $this->actingAs($this->user);
        $r = $this->getJson("/participants/{$this->participant->id}/anticoagulation");
        $r->assertOk()->assertJsonStructure(['active_plan', 'plans', 'inr_trend']);
    }

    public function test_anticoag_plan_create_warfarin_requires_range(): void
    {
        $this->actingAs($this->user);
        // Missing range → 422
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent' => 'warfarin',
            'start_date' => now()->toDateString(),
        ])->assertStatus(422);
        // Valid → 201
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent' => 'warfarin',
            'start_date' => now()->toDateString(),
            'target_inr_low' => 2.0,
            'target_inr_high' => 3.0,
        ])->assertStatus(201);
    }

    public function test_anticoag_record_inr_creates_value(): void
    {
        $this->actingAs($this->user);
        $this->postJson("/participants/{$this->participant->id}/anticoagulation/plans", [
            'agent' => 'warfarin', 'start_date' => now()->toDateString(),
            'target_inr_low' => 2.0, 'target_inr_high' => 3.0,
        ])->assertStatus(201);

        $r = $this->postJson("/participants/{$this->participant->id}/anticoagulation/inr", [
            'value' => 2.5, 'drawn_at' => now()->toIso8601String(),
        ]);
        $r->assertStatus(201)->assertJsonStructure(['inr']);
    }

    public function test_ade_index_and_store(): void
    {
        $this->actingAs($this->user);
        $this->getJson("/participants/{$this->participant->id}/ade")
            ->assertOk()->assertJsonStructure(['events']);

        $r = $this->postJson("/participants/{$this->participant->id}/ade", [
            'onset_date' => now()->subDay()->toDateString(),
            'severity' => 'mild',
            'causality' => 'possible',
            'reaction_description' => 'Mild rash observed',
        ]);
        $r->assertStatus(201)->assertJsonStructure(['event']);
    }
}

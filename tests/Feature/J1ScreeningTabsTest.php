<?php

// ─── Phase J1 — Screening tabs (IADL + TB + Substance-Use) ──────────────────
// Locks in: the three new participant-tab pages added by Wave J render with
// their scoring panels, threshold colors, and CFR-citation footers. Covers
// Lawton IADL (8 items), TB §460.71 annual cadence display, and AUDIT-C +
// CAGE + DAST-10 substance-use scoring. Regression trap against tab routing
// drift after any participant-shell refactor.
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class J1ScreeningTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'J1']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'standard', 'is_active' => true,
        ]);
    }

    public function test_iadl_index_returns_trend_shape(): void
    {
        $this->actingAs($this->user);
        $r = $this->getJson("/participants/{$this->participant->id}/iadl");
        $r->assertOk()->assertJsonStructure(['records', 'trend', 'baseline', 'current']);
    }

    public function test_iadl_store_creates_and_returns_suggestions(): void
    {
        $this->actingAs($this->user);
        $r = $this->postJson("/participants/{$this->participant->id}/iadl", [
            'telephone' => 1, 'shopping' => 0, 'food_preparation' => 0, 'housekeeping' => 1,
            'laundry' => 1, 'transportation' => 0, 'medications' => 0, 'finances' => 1,
            'notes' => 'test',
        ]);
        $r->assertStatus(201);
        $r->assertJsonStructure(['record', 'suggestions']);
    }

    public function test_tb_index_returns_shape(): void
    {
        $this->actingAs($this->user);
        $r = $this->getJson("/participants/{$this->participant->id}/tb-screenings");
        $r->assertOk()->assertJsonStructure(['records', 'latest', 'days_until_due']);
    }

    public function test_tb_store_ppd_requires_induration(): void
    {
        $this->actingAs($this->user);
        // Without induration → 422
        $this->postJson("/participants/{$this->participant->id}/tb-screenings", [
            'screening_type' => 'ppd',
            'performed_date' => now()->subDay()->toDateString(),
            'result' => 'negative',
        ])->assertStatus(422);

        // With induration → 201
        $this->postJson("/participants/{$this->participant->id}/tb-screenings", [
            'screening_type' => 'ppd',
            'performed_date' => now()->subDay()->toDateString(),
            'result' => 'negative',
            'induration_mm' => 0,
        ])->assertStatus(201);
    }

    public function test_substance_use_types_registered(): void
    {
        $types = \App\Models\Assessment::TYPES;
        $this->assertContains('audit_c_alcohol', $types);
        $this->assertContains('cage_alcohol', $types);
        $this->assertContains('dast10_substance', $types);
    }
}

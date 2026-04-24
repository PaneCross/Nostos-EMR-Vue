<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\DietaryOrder;
use App\Models\Participant;
use App\Models\Problem;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DietaryOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $dietitian;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'DT']);
        $this->dietitian = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'dietary', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_create_order_discontinues_prior_active(): void
    {
        DietaryOrder::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'diet_type' => 'regular', 'effective_date' => now()->subMonth(),
        ]);
        $this->actingAs($this->dietitian);
        $this->postJson("/participants/{$this->participant->id}/dietary-orders", [
            'diet_type' => 'diabetic',
            'effective_date' => now()->toDateString(),
            'calorie_target' => 1800,
        ])->assertStatus(201);
        $this->assertEquals(1, DietaryOrder::whereNull('discontinued_date')->count());
    }

    public function test_diabetic_diagnosis_with_regular_diet_alerts(): void
    {
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'E11.9', 'icd10_description' => 'Type 2 diabetes mellitus',
            'status' => 'active', 'onset_date' => now()->subYear(),
        ]);
        $this->actingAs($this->dietitian);
        $this->postJson("/participants/{$this->participant->id}/dietary-orders", [
            'diet_type' => 'regular',
            'effective_date' => now()->toDateString(),
        ])->assertStatus(201);
        $this->assertTrue(Alert::where('alert_type', 'dietary_order_inconsistent')->exists());
    }

    public function test_no_alert_when_diet_matches_diagnosis(): void
    {
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'E11.9', 'icd10_description' => 'Type 2 diabetes mellitus',
            'status' => 'active', 'onset_date' => now()->subYear(),
        ]);
        $this->actingAs($this->dietitian);
        $this->postJson("/participants/{$this->participant->id}/dietary-orders", [
            'diet_type' => 'diabetic',
            'effective_date' => now()->toDateString(),
        ])->assertStatus(201);
        $this->assertFalse(Alert::where('alert_type', 'dietary_order_inconsistent')->exists());
    }

    public function test_roster_groups_by_diet_type(): void
    {
        DietaryOrder::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'diet_type' => 'diabetic', 'effective_date' => now(),
        ]);
        $p2 = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        DietaryOrder::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $p2->id,
            'diet_type' => 'renal', 'effective_date' => now(),
        ]);
        $this->actingAs($this->dietitian);
        $r = $this->getJson('/dietary/roster');
        $r->assertOk();
        $this->assertArrayHasKey('diabetic', $r->json('groups'));
        $this->assertArrayHasKey('renal', $r->json('groups'));
    }

    public function test_cross_tenant_blocked(): void
    {
        $other = Tenant::factory()->create();
        $oSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XX']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($oSite->id)->create();
        $this->actingAs($this->dietitian);
        $this->postJson("/participants/{$otherP->id}/dietary-orders", [
            'diet_type' => 'regular', 'effective_date' => now()->toDateString(),
        ])->assertStatus(403);
    }
}

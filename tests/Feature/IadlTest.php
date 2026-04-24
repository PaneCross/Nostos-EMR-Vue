<?php

// ─── IadlTest ────────────────────────────────────────────────────────────────
// Phase C1 — Lawton IADL assessment recording + scoring + referral suggestions.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\IadlRecord;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IadlScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IadlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $sw;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'IA']);
        $this->sw = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'social_work',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    private function fullyIndependent(): array
    {
        return array_fill_keys(IadlRecord::ITEMS, 1);
    }

    public function test_scoring_service_bands(): void
    {
        $svc = new IadlScoringService();
        $this->assertEquals('independent',         $svc->band(8));
        $this->assertEquals('mild_impairment',     $svc->band(7));
        $this->assertEquals('mild_impairment',     $svc->band(6));
        $this->assertEquals('moderate_impairment', $svc->band(5));
        $this->assertEquals('moderate_impairment', $svc->band(3));
        $this->assertEquals('severe_impairment',   $svc->band(2));
        $this->assertEquals('severe_impairment',   $svc->band(0));
    }

    public function test_sw_can_record_fully_independent_iadl(): void
    {
        $this->actingAs($this->sw);
        $r = $this->postJson("/participants/{$this->participant->id}/iadl", $this->fullyIndependent());
        $r->assertStatus(201);
        $r->assertJsonPath('record.total_score', 8);
        $r->assertJsonPath('record.interpretation', 'independent');
        $this->assertEmpty($r->json('suggestions'));
    }

    public function test_impaired_items_trigger_referral_suggestions(): void
    {
        $this->actingAs($this->sw);
        $payload = array_merge($this->fullyIndependent(), [
            'finances'    => 0,
            'medications' => 0,
        ]);
        $r = $this->postJson("/participants/{$this->participant->id}/iadl", $payload);
        $r->assertStatus(201);
        $r->assertJsonPath('record.total_score', 6);
        $r->assertJsonPath('record.interpretation', 'mild_impairment');

        $suggestions = collect($r->json('suggestions'));
        $this->assertTrue($suggestions->contains(fn ($s) => $s['item'] === 'finances'   && $s['dept'] === 'social_work'));
        $this->assertTrue($suggestions->contains(fn ($s) => $s['item'] === 'medications' && $s['dept'] === 'pharmacy'));
    }

    public function test_severe_impairment_classification(): void
    {
        $this->actingAs($this->sw);
        $payload = array_fill_keys(IadlRecord::ITEMS, 0);
        $payload['telephone'] = 1; // 1/8 total
        $r = $this->postJson("/participants/{$this->participant->id}/iadl", $payload);
        $r->assertStatus(201);
        $r->assertJsonPath('record.total_score', 1);
        $r->assertJsonPath('record.interpretation', 'severe_impairment');
    }

    public function test_invalid_item_value_rejected(): void
    {
        $this->actingAs($this->sw);
        $payload = array_merge($this->fullyIndependent(), ['finances' => 3]);
        $this->postJson("/participants/{$this->participant->id}/iadl", $payload)->assertStatus(422);
    }

    public function test_index_returns_trend_with_baseline_and_current(): void
    {
        $this->actingAs($this->sw);
        // Seed 3 historical records
        IadlRecord::create(array_merge(
            array_fill_keys(IadlRecord::ITEMS, 1),
            [
                'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
                'recorded_at' => now()->subYear(), 'total_score' => 8, 'interpretation' => 'independent',
                'recorded_by_user_id' => $this->sw->id,
            ]
        ));
        IadlRecord::create(array_merge(
            array_fill_keys(IadlRecord::ITEMS, 1),
            [
                'finances' => 0,
                'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
                'recorded_at' => now()->subMonths(6), 'total_score' => 7, 'interpretation' => 'mild_impairment',
                'recorded_by_user_id' => $this->sw->id,
            ]
        ));
        IadlRecord::create(array_merge(
            array_fill_keys(IadlRecord::ITEMS, 0),
            [
                'telephone' => 1,
                'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
                'recorded_at' => now(), 'total_score' => 1, 'interpretation' => 'severe_impairment',
                'recorded_by_user_id' => $this->sw->id,
            ]
        ));

        $r = $this->getJson("/participants/{$this->participant->id}/iadl");
        $r->assertOk();
        $this->assertCount(3, $r->json('records'));
        $this->assertEquals(8, $r->json('baseline.total_score'));
        $this->assertEquals(1, $r->json('current.total_score'));
    }

    public function test_non_clinical_dept_blocked(): void
    {
        $finance = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'finance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($finance);
        $this->postJson("/participants/{$this->participant->id}/iadl", $this->fullyIndependent())
            ->assertStatus(403);
    }

    public function test_cross_tenant_blocked(): void
    {
        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $otherP = Participant::factory()->enrolled()->forTenant($other->id)->forSite($otherSite->id)->create();
        $this->actingAs($this->sw);
        $this->postJson("/participants/{$otherP->id}/iadl", $this->fullyIndependent())
            ->assertStatus(403);
    }
}

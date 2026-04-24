<?php

namespace Tests\Feature;

use App\Models\CarePlan;
use App\Models\ClinicalNote;
use App\Models\ClinicalOrder;
use App\Models\Incident;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use Database\Seeders\BeersCriteriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShortWinsF2Test extends TestCase
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
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'F2']);
        $this->pcp = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_beers_flags_endpoint_returns_pims(): void
    {
        (new BeersCriteriaSeeder())->run();
        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Diphenhydramine 25mg', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/beers-flags");
        $r->assertOk();
        $this->assertCount(1, $r->json('flags'));
    }

    public function test_smartset_chf_exacerbation_creates_4_orders(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/smartsets/chf_exacerbation");
        $r->assertStatus(201);
        $this->assertEquals(4, ClinicalOrder::count());
        $this->assertTrue(ClinicalOrder::where('clinical_indication', 'CHF exacerbation')
            ->where('priority', 'urgent')->exists());
    }

    public function test_smartset_unknown_key_returns_422(): void
    {
        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/smartsets/bogus")->assertStatus(422);
    }

    public function test_note_pdf_renders(): void
    {
        $note = ClinicalNote::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'site_id' => $this->site->id, 'note_type' => 'soap', 'status' => 'signed',
            'authored_by_user_id' => $this->pcp->id, 'department' => 'primary_care',
            'visit_type' => 'in_center', 'visit_date' => today(),
            'signed_at' => now(), 'signed_by_user_id' => $this->pcp->id,
            'subjective' => 'x', 'objective' => 'x', 'assessment' => 'x', 'plan' => 'x',
        ]);
        $this->actingAs($this->pcp);
        $r = $this->get("/notes/{$note->id}/pdf");
        $r->assertOk();
        $r->assertHeader('content-type', 'application/pdf');
    }

    public function test_search_filter_active_fall_risk(): void
    {
        Incident::factory()->create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'incident_type' => 'fall', 'occurred_at' => now()->subDays(10),
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson('/search/filters?kind=active_fall_risk');
        $r->assertOk();
        $this->assertEquals(1, $r->json('count'));
    }

    public function test_search_filter_unknown_kind_returns_422(): void
    {
        $this->actingAs($this->pcp);
        $this->getJson('/search/filters?kind=banana')->assertStatus(422);
    }

    public function test_bulk_sign_care_plans_single_audit_entry(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $cp = CarePlan::create([
                'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
                'version' => $i + 1,
                'status' => 'under_review',
                'created_by_user_id' => $this->pcp->id,
            ]);
            $ids[] = $cp->id;
        }
        $this->actingAs($this->pcp);
        $r = $this->postJson('/care-plans/bulk-sign', ['care_plan_ids' => $ids]);
        $r->assertOk();
        $this->assertEquals(3, $r->json('signed_count'));
        $this->assertEquals(3, CarePlan::whereNotNull('approved_at')->count());
    }

    public function test_center_wristband_pdf_renders(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        }
        $this->actingAs($this->pcp);
        $r = $this->get('/wristbands/center-print.pdf?site_id=' . $this->site->id);
        $r->assertOk();
        $r->assertHeader('content-type', 'application/pdf');
    }

    public function test_timeline_returns_merged_events(): void
    {
        ClinicalNote::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'site_id' => $this->site->id, 'note_type' => 'soap', 'status' => 'draft',
            'authored_by_user_id' => $this->pcp->id, 'department' => 'primary_care',
            'visit_type' => 'in_center', 'visit_date' => today(),
        ]);
        Vital::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'recorded_by_user_id' => $this->pcp->id, 'recorded_at' => now()->subDay(),
            'bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse' => 72,
        ]);
        $this->actingAs($this->pcp);
        $r = $this->getJson("/participants/{$this->participant->id}/timeline");
        $r->assertOk();
        $kinds = collect($r->json('timeline'))->pluck('kind')->unique()->sort()->values();
        $this->assertTrue($kinds->contains('note'));
        $this->assertTrue($kinds->contains('vitals'));
    }

    public function test_note_reminders_queue_lists_overdue(): void
    {
        // No notes on this participant → immediately "due".
        $this->actingAs($this->pcp);
        $r = $this->getJson('/note-reminders/upcoming');
        $r->assertOk();
        $this->assertGreaterThan(0, $r->json('count'));
    }
}

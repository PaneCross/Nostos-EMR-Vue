<?php

// ─── Phase I1 — ADE compliance universe + ROI/TB Inertia ────────────────────
namespace Tests\Feature;

use App\Models\AdverseDrugEvent;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\RoiRequest;
use App\Models\Site;
use App\Models\TbScreening;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class I1ComplianceUniverseTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'I1']);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_ade_compliance_universe_json_returns_rows(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Vancomycin', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $med->id, 'onset_date' => now()->subDays(5),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'Acute kidney injury',
            'reporter_user_id' => $this->qa->id, 'auto_allergy_created' => true,
        ]);

        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/ade-reporting');
        $r->assertOk();
        $this->assertEquals(1, $r->json('summary.count_total'));
        $this->assertEquals(1, $r->json('summary.count_severe_plus'));
        $this->assertEquals(1, $r->json('summary.count_requires_medwatch'));
        $this->assertEquals(1, $r->json('summary.count_auto_allergy'));
    }

    public function test_ade_universe_inertia_renders(): void
    {
        $this->actingAs($this->qa);
        $r = $this->get('/compliance/ade-reporting');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page->component('Compliance/AdeReporting'));
    }

    public function test_roi_universe_inertia_renders(): void
    {
        RoiRequest::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'requestor_type' => 'self', 'requestor_name' => 'Alice',
            'records_requested_scope' => 'All visit notes',
            'requested_at' => now(), 'due_by' => now()->addDays(30), 'status' => 'pending',
        ]);
        $this->actingAs($this->qa);
        $r = $this->get('/compliance/roi');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page
            ->component('Compliance/Roi')
            ->where('summary.count_total', 1)
        );
    }

    public function test_tb_universe_inertia_renders(): void
    {
        TbScreening::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'recorded_by_user_id' => $this->qa->id,
            'screening_type' => 'quantiferon', 'performed_date' => now()->subDays(30),
            'result' => 'negative', 'next_due_date' => now()->addDays(335),
        ]);
        $this->actingAs($this->qa);
        $r = $this->get('/compliance/tb-screening');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page->component('Compliance/TbScreening'));
    }

    public function test_ade_medwatch_overdue_count_computed(): void
    {
        $med = Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        // 20 days ago + severe + not reported = overdue
        AdverseDrugEvent::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'medication_id' => $med->id, 'onset_date' => now()->subDays(20),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'x', 'reporter_user_id' => $this->qa->id,
        ]);
        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/ade-reporting');
        $r->assertOk();
        $this->assertEquals(1, $r->json('summary.count_medwatch_overdue'));
    }

    public function test_ade_cross_tenant_scope(): void
    {
        $other = Tenant::factory()->create();
        $oSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XT']);
        $op = Participant::factory()->enrolled()->forTenant($other->id)->forSite($oSite->id)->create();
        $om = Medication::factory()->forParticipant($op->id)->forTenant($other->id)
            ->create(['status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        AdverseDrugEvent::create([
            'tenant_id' => $other->id, 'participant_id' => $op->id,
            'medication_id' => $om->id, 'onset_date' => now()->subDays(3),
            'severity' => 'severe', 'causality' => 'probable',
            'reaction_description' => 'x',
        ]);
        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/ade-reporting');
        $r->assertOk();
        $this->assertEquals(0, $r->json('summary.count_total'));
    }
}

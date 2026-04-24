<?php

namespace Tests\Feature;

use App\Models\ConsentRecord;
use App\Models\ConsentTemplate;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentVersioningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;
    private User $pcp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G4']);
        $this->qa = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->pcp = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
    }

    public function test_qa_can_create_draft_template(): void
    {
        $this->actingAs($this->qa);
        $this->postJson('/consent-templates', [
            'consent_type' => 'npp_acknowledgment', 'version' => '2026.04-v1',
            'title' => 'NPP v1', 'body' => 'Notice of Privacy Practices content.',
        ])->assertStatus(201);
        $this->assertEquals('draft', ConsentTemplate::first()->status);
    }

    public function test_non_qa_cannot_create(): void
    {
        $this->actingAs($this->pcp);
        $this->postJson('/consent-templates', [
            'consent_type' => 'npp_acknowledgment', 'version' => 'v1',
            'title' => 't', 'body' => 'b',
        ])->assertStatus(403);
    }

    public function test_approve_archives_prior_approved(): void
    {
        $old = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v1', 'title' => 'Treatment v1', 'body' => 'x',
            'status' => 'approved', 'approved_by_user_id' => $this->qa->id, 'approved_at' => now()->subMonth(),
        ]);
        $new = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v2', 'title' => 'Treatment v2', 'body' => 'x',
            'status' => 'draft',
        ]);
        $this->actingAs($this->qa);
        $this->postJson("/consent-templates/{$new->id}/approve")->assertOk();
        $this->assertEquals('archived', $old->fresh()->status);
        $this->assertEquals('approved', $new->fresh()->status);
    }

    public function test_approve_rejects_already_approved(): void
    {
        $t = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v1', 'title' => 't', 'body' => 'b',
            'status' => 'approved', 'approved_at' => now(), 'approved_by_user_id' => $this->qa->id,
        ]);
        $this->actingAs($this->qa);
        $this->postJson("/consent-templates/{$t->id}/approve")->assertStatus(409);
    }

    public function test_reprompt_queue_flags_stale_consents(): void
    {
        $participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $old = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v1', 'title' => 't', 'body' => 'b',
            'status' => 'archived', 'approved_at' => now()->subYear(),
        ]);
        $new = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v2', 'title' => 't', 'body' => 'b',
            'status' => 'approved', 'approved_at' => now(),
        ]);
        ConsentRecord::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $participant->id,
            'consent_type' => 'treatment_consent', 'document_title' => 't',
            'status' => 'acknowledged', 'acknowledged_at' => now()->subMonth(),
            'consent_template_id' => $old->id,
            'created_by_user_id' => $this->qa->id,
        ]);

        $this->actingAs($this->qa);
        $r = $this->getJson('/consent-templates/reprompt-queue');
        $r->assertOk();
        $this->assertEquals(1, $r->json('count'));
    }

    public function test_reprompt_queue_empty_when_all_current(): void
    {
        $participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $current = ConsentTemplate::create([
            'tenant_id' => $this->tenant->id, 'consent_type' => 'treatment_consent',
            'version' => 'v1', 'title' => 't', 'body' => 'b',
            'status' => 'approved', 'approved_at' => now(),
        ]);
        ConsentRecord::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $participant->id,
            'consent_type' => 'treatment_consent', 'document_title' => 't',
            'status' => 'acknowledged', 'acknowledged_at' => now(),
            'consent_template_id' => $current->id,
            'created_by_user_id' => $this->qa->id,
        ]);
        $this->actingAs($this->qa);
        $r = $this->getJson('/consent-templates/reprompt-queue');
        $r->assertOk();
        $this->assertEquals(0, $r->json('count'));
    }
}

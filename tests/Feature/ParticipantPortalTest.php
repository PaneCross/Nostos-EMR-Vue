<?php

namespace Tests\Feature;

use App\Models\Allergy;
use App\Models\Alert;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\PortalMessage;
use App\Models\PortalRequest;
use App\Models\Problem;
use App\Models\RoiRequest;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ParticipantPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private Participant $participant;
    private ParticipantPortalUser $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'PP']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)
            ->create(['first_name' => 'Alice', 'last_name' => 'Patient']);
        $this->portalUser = ParticipantPortalUser::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'email' => 'alice@example.com',
            'password' => Hash::make('correct-horse'),
            'is_active' => true,
        ]);
    }

    private function asPortal(): self
    {
        return $this->withHeader('X-Portal-User-Id', (string) $this->portalUser->id);
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->postJson('/portal/login', ['email' => 'alice@example.com', 'password' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        $r = $this->postJson('/portal/login', [
            'email' => 'alice@example.com', 'password' => 'correct-horse',
        ]);
        $r->assertOk();
        $this->assertEquals($this->portalUser->id, $r->json('portal_user_id'));
    }

    public function test_anonymous_request_rejected(): void
    {
        $this->getJson('/portal/overview')->assertStatus(401);
    }

    public function test_overview_returns_participant_basics(): void
    {
        $r = $this->asPortal()->getJson('/portal/overview');
        $r->assertOk();
        $this->assertEquals('Alice', $r->json('participant.first_name'));
        $this->assertFalse($r->json('is_proxy'));
    }

    public function test_medications_returns_only_active(): void
    {
        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Lisinopril', 'status' => 'active', 'is_controlled' => false, 'controlled_schedule' => null]);
        Medication::factory()->forParticipant($this->participant->id)->forTenant($this->tenant->id)
            ->create(['drug_name' => 'Warfarin (old)', 'status' => 'discontinued', 'is_controlled' => false, 'controlled_schedule' => null]);
        $r = $this->asPortal()->getJson('/portal/medications');
        $r->assertOk();
        $this->assertCount(1, $r->json('medications'));
    }

    public function test_allergies_returns_active_only(): void
    {
        Allergy::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'allergy_type' => 'drug', 'allergen_name' => 'Penicillin',
            'reaction_description' => 'rash', 'severity' => 'moderate',
            'onset_date' => now()->subYear(), 'is_active' => true,
        ]);
        $r = $this->asPortal()->getJson('/portal/allergies');
        $r->assertOk();
        $this->assertCount(1, $r->json('allergies'));
    }

    public function test_problems_returns_active(): void
    {
        Problem::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'icd10_code' => 'I10', 'icd10_description' => 'HTN', 'status' => 'active',
            'onset_date' => now()->subYears(3),
        ]);
        $r = $this->asPortal()->getJson('/portal/problems');
        $r->assertOk();
        $this->assertCount(1, $r->json('problems'));
    }

    public function test_send_message_creates_alert_for_staff(): void
    {
        $this->asPortal()->postJson('/portal/messages', [
            'subject' => 'Question about my meds',
            'body'    => 'Can I skip the morning dose?',
        ])->assertStatus(201);
        $this->assertEquals(1, PortalMessage::count());
        $this->assertTrue(Alert::where('alert_type', 'portal_message_received')->exists());
    }

    public function test_records_request_creates_roi_row(): void
    {
        $this->asPortal()->postJson('/portal/requests', [
            'request_type' => 'records',
            'payload' => ['scope' => '2024 visit notes'],
        ])->assertStatus(201);
        $this->assertEquals(1, PortalRequest::count());
        $this->assertEquals(1, RoiRequest::count());
        $this->assertEquals('self', RoiRequest::first()->requestor_type);
    }

    public function test_limited_proxy_cannot_submit_request(): void
    {
        $proxy = ParticipantPortalUser::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'proxy_scope' => 'limited',
            'email' => 'daughter@example.com',
            'password' => Hash::make('pw'),
            'is_active' => true,
        ]);
        $this->withHeader('X-Portal-User-Id', (string) $proxy->id)
            ->postJson('/portal/requests', ['request_type' => 'appointment'])
            ->assertStatus(403);
    }

    public function test_full_proxy_can_submit_request(): void
    {
        $proxy = ParticipantPortalUser::create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'proxy_scope' => 'full',
            'email' => 'poa@example.com',
            'password' => Hash::make('pw'),
            'is_active' => true,
        ]);
        $this->withHeader('X-Portal-User-Id', (string) $proxy->id)
            ->postJson('/portal/requests', ['request_type' => 'records', 'payload' => ['scope' => 'all']])
            ->assertStatus(201);
        $this->assertEquals('legal_rep', RoiRequest::first()->requestor_type);
    }

    public function test_messages_index_returns_conversation(): void
    {
        PortalMessage::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'from_portal_user_id' => $this->portalUser->id,
            'subject' => 's', 'body' => 'b',
        ]);
        $r = $this->asPortal()->getJson('/portal/messages');
        $r->assertOk();
        $this->assertCount(1, $r->json('messages'));
    }

    public function test_inactive_portal_user_cannot_access(): void
    {
        $this->portalUser->update(['is_active' => false]);
        $this->asPortal()->getJson('/portal/overview')->assertStatus(401);
    }
}

<?php

// ─── Phase L2 — Portal Vue pages + PWA manifest ─────────────────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\RoiRequest;
use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class L2PortalUiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private ParticipantPortalUser $portalUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'L2']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)
            ->create(['first_name' => 'Pat', 'last_name' => 'Portal']);
        $this->portalUser = ParticipantPortalUser::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'email' => 'l2@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
    }

    public function test_overview_page_renders(): void
    {
        $this->get('/portal/home')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Portal/Overview'));
    }

    public function test_medications_page_renders(): void
    {
        $this->get('/portal/meds')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Portal/Medications'));
    }

    public function test_messages_page_renders(): void
    {
        $this->get('/portal/mail')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Portal/Messages'));
    }

    public function test_records_request_creates_roi_row(): void
    {
        $r = $this->withHeader('X-Portal-User-Id', (string) $this->portalUser->id)
            ->postJson('/portal/requests', [
                'request_type' => 'records',
                'payload' => ['scope' => 'Last 6 months'],
            ]);
        $r->assertStatus(201);
        $this->assertEquals(1, RoiRequest::where('participant_id', $this->participant->id)->count());
    }

    public function test_manifest_served(): void
    {
        $r = $this->get('/manifest.webmanifest');
        $r->assertOk();
        $this->assertStringContainsString('Nostos Portal', $r->getContent());
    }
}

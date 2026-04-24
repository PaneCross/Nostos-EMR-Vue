<?php

// ─── Phase M3 — CCD + HIE gateway ───────────────────────────────────────────
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CcdaExportService;
use App\Services\Hie\NullHieGateway;
use App\Services\Hie\SequoiaHieGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class M3HieInteropTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Participant $participant;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'M3']);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($site->id)->create();
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
    }

    public function test_ccd_builder_produces_cda_xml(): void
    {
        $svc = app(CcdaExportService::class);
        $xml = $svc->build($this->participant);
        $this->assertStringContainsString('<ClinicalDocument', $xml);
        // C-CDA CCD template IDs
        $this->assertStringContainsString('2.16.840.1.113883.10.20.22.1.2', $xml);
    }

    public function test_null_gateway_publish_is_noop_ok(): void
    {
        $gw = new NullHieGateway();
        $r = $gw->publishCcd($this->participant, '<xml/>');
        $this->assertTrue($r['ok']);
        $this->assertNotEmpty($r['transmission_id']);
    }

    public function test_sequoia_gateway_throws_until_configured(): void
    {
        $gw = new SequoiaHieGateway();
        $this->expectException(\RuntimeException::class);
        $gw->publishCcd($this->participant, '<xml/>');
    }

    public function test_publish_endpoint_returns_gateway_result(): void
    {
        $this->actingAs($this->user);
        $this->postJson("/participants/{$this->participant->id}/hie/publish")
            ->assertOk()
            ->assertJsonStructure(['gateway', 'result' => ['ok', 'transmission_id']]);
    }

    public function test_ccd_endpoint_returns_xml(): void
    {
        $this->actingAs($this->user);
        $r = $this->get("/hie/ccd/{$this->participant->id}");
        $r->assertOk();
        $this->assertStringContainsString('<ClinicalDocument', $r->getContent());
    }
}

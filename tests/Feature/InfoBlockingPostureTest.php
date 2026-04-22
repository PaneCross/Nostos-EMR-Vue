<?php

// ─── InfoBlockingPostureTest ──────────────────────────────────────────────────
// Phase 5 (MVP roadmap) — 21st Century Cures Act / ONC HTI-1 posture.
// Covers new index + history endpoints, FHIR Bundle presence, policy pages.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\EhiExport;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class InfoBlockingPostureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $itAdmin;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'IBP']);
        $this->itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'it_admin',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── EHI export index + history ───────────────────────────────────────────

    public function test_staff_can_view_ehi_export_index_page(): void
    {
        $this->actingAs($this->itAdmin)
            ->get("/participants/{$this->participant->id}/ehi-export")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Participants/EhiExport')
                ->has('participant')
                ->has('exports')
            );
    }

    public function test_non_admin_cannot_view_ehi_export_index_page(): void
    {
        $random = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);
        $this->actingAs($random)
            ->get("/participants/{$this->participant->id}/ehi-export")
            ->assertStatus(403);
    }

    public function test_history_endpoint_returns_past_exports(): void
    {
        // Create 2 past exports directly
        EhiExport::create([
            'participant_id'       => $this->participant->id,
            'tenant_id'            => $this->tenant->id,
            'requested_by_user_id' => $this->itAdmin->id,
            'token'                => str_repeat('a', 64),
            'file_path'            => 'ehi_exports/fake.zip',
            'status'               => 'ready',
            'expires_at'           => now()->addHours(20),
        ]);
        EhiExport::create([
            'participant_id'       => $this->participant->id,
            'tenant_id'            => $this->tenant->id,
            'requested_by_user_id' => $this->itAdmin->id,
            'token'                => str_repeat('b', 64),
            'file_path'            => 'ehi_exports/old.zip',
            'status'               => 'expired',
            'expires_at'           => now()->subDays(3),
        ]);

        $this->actingAs($this->itAdmin)
            ->getJson("/participants/{$this->participant->id}/ehi-export/history")
            ->assertOk()
            ->assertJsonStructure(['exports' => [['id', 'status', 'downloadable']]])
            ->assertJsonPath('exports.0.downloadable', true)
            ->assertJsonPath('exports.1.downloadable', false);
    }

    // ── FHIR Bundle presence ─────────────────────────────────────────────────

    public function test_generated_export_includes_fhir_bundle_json(): void
    {
        // Do NOT Storage::fake — we need a real ZipArchive write to storage path.
        $resp = $this->actingAs($this->itAdmin)
            ->postJson("/participants/{$this->participant->id}/ehi-export")
            ->assertStatus(202);

        $exportId = $resp->json('export_id');
        $export = EhiExport::findOrFail($exportId);

        $fullPath = storage_path('app/' . $export->file_path);
        $this->assertFileExists($fullPath, 'EHI export ZIP should exist on disk.');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($fullPath) === true, 'ZIP should open.');

        // FHIR Bundle entry
        $bundleJson = $zip->getFromName('fhir/Bundle.json');
        $this->assertNotFalse($bundleJson, 'Bundle.json must be in ZIP.');

        $bundle = json_decode($bundleJson, true);
        $this->assertEquals('Bundle', $bundle['resourceType']);
        $this->assertEquals('collection', $bundle['type']);
        $this->assertIsArray($bundle['entry']);
        $this->assertGreaterThanOrEqual(1, count($bundle['entry']), 'Bundle must contain at least Patient entry.');
        $this->assertEquals('Patient', $bundle['entry'][0]['resource']['resourceType']);

        $zip->close();
        // Cleanup
        @unlink($fullPath);
    }

    // ── Policy pages ─────────────────────────────────────────────────────────

    public function test_info_blocking_policy_page_loads(): void
    {
        $this->actingAs($this->itAdmin)
            ->get('/policies/info-blocking')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/InfoBlocking'));
    }

    public function test_npp_policy_page_loads(): void
    {
        $this->actingAs($this->itAdmin)
            ->get('/policies/npp')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/NoticeOfPrivacyPractices'));
    }

    public function test_acceptable_use_policy_page_loads(): void
    {
        $this->actingAs($this->itAdmin)
            ->get('/policies/acceptable-use')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Policies/AcceptableUse'));
    }

    public function test_unauthenticated_user_cannot_reach_policy_pages(): void
    {
        // Info-blocking policy requires auth — unauthenticated users redirect to login.
        $this->get('/policies/info-blocking')->assertRedirect('/login');
    }
}

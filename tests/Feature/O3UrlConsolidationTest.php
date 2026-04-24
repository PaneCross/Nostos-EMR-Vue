<?php

// ─── Phase O3 — dual JSON/Inertia via wantsJson() branch on canonical URL ──
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\ParticipantPortalUser;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class O3UrlConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_overview_dual_serves_inertia_and_json(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O3']);
        $p = Participant::factory()->enrolled()
            ->forTenant($t->id)->forSite($site->id)
            ->create(['first_name' => 'Alice']);
        $pu = ParticipantPortalUser::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'email' => 'o3@example.com', 'password' => Hash::make('x'),
            'is_active' => true,
        ]);

        // JSON branch (axios-style)
        $json = $this->withHeader('X-Portal-User-Id', (string) $pu->id)
            ->getJson('/portal/overview');
        $json->assertOk()->assertJsonStructure(['participant', 'is_proxy']);

        // Inertia branch (browser-style)
        $html = $this->withHeader('X-Portal-User-Id', (string) $pu->id)
            ->get('/portal/overview');
        $html->assertOk()->assertInertia(fn ($p) => $p->component('Portal/Overview'));
    }

    public function test_dashboards_high_risk_dual_serves(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->getJson('/dashboards/high-risk')->assertOk()->assertJsonStructure(['rows']);
        $this->get('/dashboards/high-risk')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Dashboards/HighRisk'));
    }

    public function test_registries_dual_serves(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->getJson('/registries/diabetes')->assertOk()->assertJsonStructure(['count', 'rows']);
        $this->get('/registries/diabetes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Registries/Diabetes'));
    }

    public function test_old_portal_ui_paths_are_gone(): void
    {
        $this->get('/portal/home')->assertNotFound();
        $this->get('/portal/meds')->assertNotFound();
    }

    public function test_old_dashboards_risk_path_is_gone(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/dashboards/risk')->assertNotFound();
    }

    public function test_old_registries_ui_paths_are_gone(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($u);
        $this->get('/registries-ui/diabetes')->assertNotFound();
    }
}

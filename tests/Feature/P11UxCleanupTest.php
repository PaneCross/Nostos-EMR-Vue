<?php

// ─── Phase P11 — UX cleanup bundle ─────────────────────────────────────────
namespace Tests\Feature;

use App\Models\InfectionCase;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P11UxCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenant(string $dept = 'qa_compliance'): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => $dept,
            'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u, $site];
    }

    public function test_pharmacy_dashboard_empty_state_in_template(): void
    {
        // Source-level check that the empty-state strings exist in the Vue file.
        $vue = file_get_contents(resource_path('js/Pages/Dashboard/Depts/PharmacyDashboard.vue'));
        $this->assertStringContainsString('No enrolled participants', $vue);
        $this->assertStringContainsString('no enrolled participants', $vue);
    }

    public function test_vite_config_has_sw_cache_bumper(): void
    {
        $vite = file_get_contents(base_path('vite.config.ts'));
        $this->assertStringContainsString('bumpServiceWorkerCache', $vite);
        $this->assertStringContainsString('nostos-portal-', $vite);
    }

    public function test_reportable_infections_csv_exports_notifiable_only(): void
    {
        [$t, $u, $site] = $this->setupTenant();
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        // Create one notifiable + one non-notifiable case
        InfectionCase::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $p->id,
            'organism_type' => 'tuberculosis', 'severity' => 'moderate',
            'onset_date' => now()->subDays(7)->toDateString(),
        ]);
        InfectionCase::create([
            'tenant_id' => $t->id, 'site_id' => $site->id, 'participant_id' => $p->id,
            'organism_type' => 'other', 'severity' => 'mild',
            'onset_date' => now()->subDays(3)->toDateString(),
        ]);
        $this->actingAs($u);
        $r = $this->get('/compliance/reportable-infections.csv');
        $r->assertOk();
        $body = $r->getContent();
        $this->assertStringContainsString('tuberculosis', $body);
        $this->assertStringNotContainsString(',other,', $body);
    }

    // ── Error-path representative tests (M3) ──────────────────────────────

    public function test_amendment_decide_unauthenticated_redirects(): void
    {
        $this->postJson('/amendment-requests/1/decide', ['status' => 'accepted'])
            ->assertStatus(401);
    }

    public function test_eligibility_check_validation_rejects_bad_payer(): void
    {
        [$t, $u, $site] = $this->setupTenant('enrollment');
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $this->actingAs($u);
        $this->postJson("/participants/{$p->id}/eligibility-checks", [
            'payer_type' => 'medicaaaaare',  // typo
        ])->assertStatus(422);
    }

    public function test_breach_logging_requires_description(): void
    {
        [$t, $u] = $this->setupTenant('it_admin');
        $this->actingAs($u);
        $this->postJson('/it-admin/breaches', [
            'discovered_at' => now()->toDateString(),
            'affected_count' => 1,
            'breach_type' => 'lost_device',
            // missing description
        ])->assertStatus(422);
    }

    public function test_prior_auth_wrong_dept_rejected(): void
    {
        [$t, $u, $site] = $this->setupTenant('dietary');
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        $this->actingAs($u);
        $this->getJson('/pharmacy/prior-auth')->assertForbidden();
    }
}

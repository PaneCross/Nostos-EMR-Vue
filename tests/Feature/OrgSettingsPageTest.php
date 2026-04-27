<?php

// ─── OrgSettingsPageTest ─────────────────────────────────────────────────────
// Phase OS3 — covers the executive Org Settings Inertia page + bulk-save endpoint.
//
// Locks in:
//   - Route gating: super_admin role OR (department=executive + role=admin) only
//   - Other depts (it_admin, primary_care, etc.) get 403
//   - Page renders the Executive/OrgSettings Vue component with grouped prefs
//   - Bulk save persists changes through the service + writes one AuditLog per change
//   - Required keys cannot be flipped via the endpoint (silently skipped)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function executiveAdmin(): array
    {
        $t = Tenant::factory()->create();
        $s = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'EXC']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'executive', 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $u];
    }

    public function test_index_requires_executive_or_super_admin(): void
    {
        [$t, ] = $this->executiveAdmin();

        // PCP admin — should be denied
        $pcp = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($pcp)->get('/executive/org-settings')->assertStatus(403);

        // IT admin — also denied (Org Settings is executive-only)
        $itAdmin = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($itAdmin)->get('/executive/org-settings')->assertStatus(403);

        // Executive standard (non-admin) — denied
        $execStandard = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'executive', 'role' => 'standard', 'is_active' => true,
        ]);
        $this->actingAs($execStandard)->get('/executive/org-settings')->assertStatus(403);
    }

    public function test_index_renders_for_executive_admin(): void
    {
        [, $exec] = $this->executiveAdmin();

        $r = $this->actingAs($exec)->get('/executive/org-settings');
        $r->assertOk();
        $r->assertInertia(fn ($page) => $page
            ->component('Executive/OrgSettings')
            ->has('orgGrouped')
            ->has('orgGrouped.Medical Director')
            ->has('orgGrouped.Compliance Officer')
            ->has('orgGrouped.Workflow')
            ->has('sites')
            ->has('sitesWithOverrides')
        );
    }

    public function test_site_effective_endpoint_returns_per_site_view(): void
    {
        [$t, $exec] = $this->executiveAdmin();
        $site = \App\Models\Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'EFF']);

        $r = $this->actingAs($exec)->getJson("/executive/org-settings/site/{$site->id}");
        $r->assertOk()
            ->assertJsonStructure([
                'siteId', 'siteName',
                'grouped' => ['Medical Director', 'Compliance Officer', 'Workflow'],
            ]);
        $this->assertEquals($site->id, $r->json('siteId'));
    }

    public function test_site_effective_blocks_cross_tenant(): void
    {
        [, $exec] = $this->executiveAdmin();
        $other = Tenant::factory()->create();
        $otherSite = \App\Models\Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'XOX']);

        $this->actingAs($exec)->getJson("/executive/org-settings/site/{$otherSite->id}")
            ->assertStatus(403);
    }

    public function test_save_with_site_id_creates_per_site_override(): void
    {
        [$t, $exec] = $this->executiveAdmin();
        $site = \App\Models\Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'OVR']);

        $r = $this->actingAs($exec)->postJson('/executive/org-settings', [
            'site_id'     => $site->id,
            'preferences' => [
                'designation.nursing_director.fall_risk_threshold' => true,
            ],
        ]);
        $r->assertOk()->assertJson(['changed' => 1]);

        $this->assertEquals(1, \App\Models\NotificationPreference::where('tenant_id', $t->id)
            ->where('site_id', $site->id)
            ->where('preference_key', 'designation.nursing_director.fall_risk_threshold')
            ->where('enabled', true)
            ->count());
    }

    public function test_clear_override_removes_the_row(): void
    {
        [$t, $exec] = $this->executiveAdmin();
        $site = \App\Models\Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'CLR']);
        $key = 'designation.nursing_director.fall_risk_threshold';

        // Create an override
        $svc = app(\App\Services\NotificationPreferenceService::class);
        $svc->set($t->id, $key, true, $exec->id, $site->id);

        // Now clear it via the endpoint
        $r = $this->actingAs($exec)->deleteJson("/executive/org-settings/site/{$site->id}/key/{$key}");
        $r->assertOk()->assertJson(['cleared' => true]);

        $this->assertNull(\App\Models\NotificationPreference::where('tenant_id', $t->id)
            ->where('site_id', $site->id)
            ->where('preference_key', $key)
            ->first());
    }

    public function test_index_renders_for_super_admin(): void
    {
        [$t, ] = $this->executiveAdmin();

        $sa = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'super_admin', 'role' => 'super_admin', 'is_active' => true,
        ]);
        $this->actingAs($sa)->get('/executive/org-settings')->assertOk();
    }

    public function test_bulk_save_persists_optional_changes_and_audits(): void
    {
        [$t, $exec] = $this->executiveAdmin();

        $payload = ['preferences' => [
            'designation.nursing_director.fall_risk_threshold' => true,
            'designation.pharmacy_director.critical_drug_interaction' => true,
        ]];

        $r = $this->actingAs($exec)->postJson('/executive/org-settings', $payload);
        $r->assertOk()->assertJson(['changed' => 2]);

        // Both rows persisted with enabled=true
        $this->assertEquals(2, NotificationPreference::where('tenant_id', $t->id)
            ->where('enabled', true)
            ->whereIn('preference_key', array_keys($payload['preferences']))
            ->count());

        // AuditLog: one row per actually-changed pref
        $this->assertEquals(2, AuditLog::where('action', 'org_settings.preference_changed')->count());
    }

    public function test_bulk_save_silently_skips_required_keys(): void
    {
        [$t, $exec] = $this->executiveAdmin();

        // Try to disable a Required key + change a valid Optional key
        $payload = ['preferences' => [
            'designation.medical_director.restraint_observation_overdue' => false, // REQUIRED — must be ignored
            'designation.nursing_director.fall_risk_threshold'           => true,  // OK
        ]];

        $r = $this->actingAs($exec)->postJson('/executive/org-settings', $payload);
        $r->assertOk()->assertJson(['changed' => 1]); // only the optional one counted

        // No row was written for the required key
        $this->assertNull(NotificationPreference::where('tenant_id', $t->id)
            ->where('preference_key', 'designation.medical_director.restraint_observation_overdue')
            ->first());
    }

    public function test_save_requires_executive_or_super_admin(): void
    {
        [$t, ] = $this->executiveAdmin();
        $pcp = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($pcp)
            ->postJson('/executive/org-settings', ['preferences' => ['designation.nursing_director.fall_risk_threshold' => true]])
            ->assertStatus(403);
    }
}

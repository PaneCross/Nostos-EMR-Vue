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
            ->has('grouped')
            ->has('grouped.Medical Director')
            ->has('grouped.Compliance Officer')
            ->has('grouped.Workflow')
        );
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

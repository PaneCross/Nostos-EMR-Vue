<?php

// ─── TenantContextTest ────────────────────────────────────────────────────────
// Feature tests for the super-admin tenant context switcher.
//
// Coverage:
//   - SA (role) can switch to any tenant
//   - SA (dept) can switch to any tenant
//   - Regular user gets 403
//   - Executive gets 403 (this scope is SA-only)
//   - Switching also clears active_site_id (so site dropdown doesn't dangle)
//   - Clear endpoint removes session key, reverts to home tenant
//   - User::effectiveTenantId() returns session override for SA, home for others
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => 'super_admin']);
    }

    private function deptSuperAdmin(): User
    {
        return User::factory()->create(['department' => 'super_admin', 'role' => 'standard']);
    }

    private function regularUser(): User
    {
        return User::factory()->create(['department' => 'it_admin', 'role' => 'standard']);
    }

    public function test_super_admin_can_switch_tenant_context(): void
    {
        $sa     = $this->superAdmin();
        $target = Tenant::factory()->create();

        $this->actingAs($sa)
            ->postJson('/tenant-context/switch', ['tenant_id' => $target->id])
            ->assertOk()
            ->assertJsonPath('tenant.id', $target->id);

        $this->assertSame($target->id, session('active_tenant_id'));
    }

    public function test_dept_super_admin_can_switch_tenant_context(): void
    {
        $sa     = $this->deptSuperAdmin();
        $target = Tenant::factory()->create();

        $this->actingAs($sa)
            ->postJson('/tenant-context/switch', ['tenant_id' => $target->id])
            ->assertOk();

        $this->assertSame($target->id, session('active_tenant_id'));
    }

    public function test_regular_user_cannot_switch_tenant(): void
    {
        $user   = $this->regularUser();
        $target = Tenant::factory()->create();

        $this->actingAs($user)
            ->postJson('/tenant-context/switch', ['tenant_id' => $target->id])
            ->assertForbidden();
    }

    public function test_executive_cannot_switch_tenant(): void
    {
        $exec   = User::factory()->create(['department' => 'executive', 'role' => 'standard']);
        $target = Tenant::factory()->create();

        $this->actingAs($exec)
            ->postJson('/tenant-context/switch', ['tenant_id' => $target->id])
            ->assertForbidden();
    }

    public function test_switching_tenant_clears_active_site(): void
    {
        $sa     = $this->superAdmin();
        $target = Tenant::factory()->create();

        // Pre-seed an active_site_id from a previous switch
        $this->actingAs($sa)
            ->withSession(['active_site_id' => 999])
            ->postJson('/tenant-context/switch', ['tenant_id' => $target->id])
            ->assertOk();

        $this->assertNull(session('active_site_id'));
    }

    public function test_clear_endpoint_reverts_to_home_tenant(): void
    {
        $sa     = $this->superAdmin();
        $target = Tenant::factory()->create();

        $this->actingAs($sa)
            ->withSession(['active_tenant_id' => $target->id])
            ->deleteJson('/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant', null);

        $this->assertNull(session('active_tenant_id'));
    }

    public function test_effective_tenant_id_returns_session_override_for_sa(): void
    {
        $sa     = $this->superAdmin();
        $target = Tenant::factory()->create();

        $this->actingAs($sa)
            ->withSession(['active_tenant_id' => $target->id])
            // Hit any authenticated endpoint to bind the session into the request
            ->get('/dashboard');

        // After the request, session() reflects the override; helper reads it.
        session(['active_tenant_id' => $target->id]);
        $this->assertSame($target->id, $sa->fresh()->effectiveTenantId());
    }

    public function test_effective_tenant_id_returns_home_for_regular_user(): void
    {
        $user = $this->regularUser();

        // Session override should be ignored for non-SA users
        session(['active_tenant_id' => 99999]);
        $this->assertSame($user->tenant_id, $user->effectiveTenantId());
    }

    public function test_inertia_share_exposes_tenant_context_for_super_admin(): void
    {
        $sa = $this->superAdmin();

        $this->actingAs($sa)
            ->get('/super-admin-panel')
            ->assertInertia(fn ($p) =>
                $p->has('tenant_context')
                  ->has('available_tenants')
            );
    }

    public function test_inertia_share_omits_tenant_switcher_for_regular_user(): void
    {
        $user = $this->regularUser();

        // SuperAdminPanel responds 403 to non-SAs but the share() middleware
        // still runs for forbidden requests; regardless, we use a route the
        // factory user can hit. Use participants index — protected only by
        // auth + standard permissions, available to it_admin role/dept.
        $response = $this->actingAs($user)->get('/participants');

        // We don't need to assert on the page contents. We just need to
        // confirm Inertia share() decided this user has no tenant switcher.
        $response->assertInertia(fn ($p) =>
            $p->where('tenant_context', null)
              ->where('available_tenants', [])
        );
    }
}

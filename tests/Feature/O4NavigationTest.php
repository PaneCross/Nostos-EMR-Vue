<?php

// ─── Phase O4 — Wave I-N routes wired into dept nav groups ─────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O4NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $dept, string $role = 'admin'): User
    {
        $t = Tenant::factory()->create();
        return User::factory()->create([
            'tenant_id' => $t->id, 'department' => $dept,
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function hrefs(User $user): array
    {
        $groups = app(PermissionService::class)->navGroups($user);
        $out = [];
        foreach ($groups as $g) foreach ($g['items'] as $i) $out[] = $i['href'];
        return $out;
    }

    public function test_super_admin_sees_every_wave_i_n_route(): void
    {
        $u = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'department' => 'it_admin', 'role' => 'super_admin', 'is_active' => true,
        ]);
        $hrefs = $this->hrefs($u);
        $expected = [
            '/tasks', '/ops/panel', '/ops/dietary', '/ops/activities', '/ops/huddle',
            '/dashboards/quality', '/dashboards/gaps', '/dashboards/high-risk',
            '/dashboards/pde-reconciliation', '/dashboards/capitation-reconciliation',
            '/bi/builder', '/bi/saved',
            '/registries/diabetes', '/registries/chf', '/registries/copd',
            '/mobile',
            '/compliance/ade-reporting', '/compliance/roi', '/compliance/tb-screening',
        ];
        foreach ($expected as $href) {
            $this->assertContains($href, $hrefs, "Super-admin nav missing {$href}");
        }
    }

    public function test_primary_care_user_sees_clinical_wave_i_n_links(): void
    {
        $u = $this->user('primary_care');
        $hrefs = $this->hrefs($u);
        $this->assertContains('/tasks', $hrefs);
        $this->assertContains('/ops/panel', $hrefs);
        $this->assertContains('/registries/diabetes', $hrefs);
    }

    public function test_finance_user_sees_reconciliation_dashboards(): void
    {
        $u = $this->user('finance');
        $hrefs = $this->hrefs($u);
        $this->assertContains('/dashboards/pde-reconciliation', $hrefs);
        $this->assertContains('/dashboards/capitation-reconciliation', $hrefs);
    }
}

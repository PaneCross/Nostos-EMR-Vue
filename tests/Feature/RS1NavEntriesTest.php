<?php

// ─── Phase RS1 — Wave R nav entries surfaced in sidebar ────────────────────
namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RS1NavEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_qa_compliance_nav_includes_hpms_and_cms_universes(): void
    {
        $this->seed(PermissionSeeder::class);
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);

        $groups = app(PermissionService::class)->navGroups($u);
        $hrefs = collect($groups)->flatMap(fn ($g) => collect($g['items'])->pluck('href'))->all();

        $this->assertContains('/compliance/hpms-incident-reports', $hrefs);
        $this->assertContains('/compliance/cms-audit-universes', $hrefs);
    }

    public function test_enrollment_nav_includes_marketing_funnel(): void
    {
        $this->seed(PermissionSeeder::class);
        $t = Tenant::factory()->create();
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'enrollment',
            'role' => 'admin', 'is_active' => true,
        ]);

        $groups = app(PermissionService::class)->navGroups($u);
        $hrefs = collect($groups)->flatMap(fn ($g) => collect($g['items'])->pluck('href'))->all();

        $this->assertContains('/enrollment/marketing-funnel', $hrefs);
    }
}

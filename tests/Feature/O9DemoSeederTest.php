<?php

// ─── Phase O9 — Wave I-M demo data seeder smoke test ───────────────────────
namespace Tests\Feature;

use App\Models\AnticoagulationPlan;
use App\Models\CareGap;
use App\Models\IadlRecord;
use App\Models\InrResult;
use App\Models\Participant;
use App\Models\Site;
use App\Models\StaffTask;
use App\Models\TbScreening;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\WaveIMDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class O9DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantWith3Participants(): Tenant
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'O9']);
        for ($i = 0; $i < 3; $i++) {
            Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        }
        User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'primary_care',
            'role' => 'standard', 'site_id' => $site->id, 'is_active' => true,
        ]);
        return $t;
    }

    public function test_seeder_populates_iadl_tb_anticoag_caregaps(): void
    {
        $t = $this->seedTenantWith3Participants();
        $this->seed(WaveIMDemoSeeder::class);
        $this->assertGreaterThan(0, IadlRecord::forTenant($t->id)->count());
        $this->assertGreaterThan(0, TbScreening::forTenant($t->id)->count());
        $this->assertGreaterThan(0, AnticoagulationPlan::forTenant($t->id)->count());
        $this->assertGreaterThan(0, InrResult::where('tenant_id', $t->id)->count());
        $this->assertGreaterThan(0, CareGap::forTenant($t->id)->count());
    }

    public function test_seeder_populates_staff_tasks_for_demo_users(): void
    {
        $t = $this->seedTenantWith3Participants();
        $this->seed(WaveIMDemoSeeder::class);
        $this->assertGreaterThanOrEqual(5, StaffTask::forTenant($t->id)->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $t = $this->seedTenantWith3Participants();
        $this->seed(WaveIMDemoSeeder::class);
        $iadlAfter1 = IadlRecord::forTenant($t->id)->count();
        $this->seed(WaveIMDemoSeeder::class);
        $iadlAfter2 = IadlRecord::forTenant($t->id)->count();
        $this->assertEquals($iadlAfter1, $iadlAfter2, 'WaveIMDemoSeeder must be idempotent.');
    }

    public function test_seeder_skips_tenant_without_participants(): void
    {
        Tenant::factory()->create();
        $this->seed(WaveIMDemoSeeder::class);
        // No assertions necessary — seeder must not crash on empty tenant.
        $this->assertTrue(true);
    }
}

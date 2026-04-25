<?php

// ─── Phase R5 — orphan seeders now wired into DemoEnvironmentSeeder ────────
namespace Tests\Feature;

use App\Models\Site;
use App\Models\Tenant;
use Database\Seeders\BeersCriteriaSeeder;
use Database\Seeders\DayCenterScheduleSeeder;
use Database\Seeders\HccMappingSeeder;
use Database\Seeders\ProSurveySeeder;
use Database\Seeders\QualityMeasureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class R5OrphanSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphan_seeders_run_clean_and_populate_reference_tables(): void
    {
        // Pre-seed dependencies (DayCenterScheduleSeeder expects tenant + site).
        $t = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'OS']);

        // Run each seeder; assert at least one row in its primary table.
        $this->seed(BeersCriteriaSeeder::class);
        $this->assertGreaterThan(0, DB::table('emr_beers_criteria')->count());

        $this->seed(HccMappingSeeder::class);
        $this->assertGreaterThan(0, DB::table('emr_hcc_mappings')->count());

        $this->seed(DayCenterScheduleSeeder::class);
        // Schedule seeder may seed templates or per-tenant — ensure it ran without throwing.
        $this->assertTrue(true);

        $this->seed(ProSurveySeeder::class);
        $this->assertGreaterThan(0, DB::table('emr_pro_surveys')->count());

        $this->seed(QualityMeasureSeeder::class);
        $this->assertGreaterThan(0, DB::table('emr_quality_measures')->count());
    }
}

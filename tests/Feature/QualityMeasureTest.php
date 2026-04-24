<?php

namespace Tests\Feature;

use App\Models\ClinicalNote;
use App\Models\Immunization;
use App\Models\Participant;
use App\Models\QualityMeasure;
use App\Models\QualityMeasureSnapshot;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QualityMeasureService;
use Database\Seeders\QualityMeasureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityMeasureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'G3']);
        $this->qa = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true, 'site_id' => $this->site->id]);
        (new QualityMeasureSeeder())->run();
    }

    public function test_seeder_populates_at_least_8_measures(): void
    {
        $this->assertGreaterThanOrEqual(8, QualityMeasure::count());
    }

    public function test_flu_measure_rate_perfect_when_all_vaccinated(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $p = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
            Immunization::create([
                'tenant_id' => $this->tenant->id, 'participant_id' => $p->id,
                'vaccine_type' => 'influenza', 'vaccine_name' => 'Flu', 'cvx_code' => '150',
                'administered_date' => now()->subMonths(2),
            ]);
        }
        $snap = (new QualityMeasureService())->computeOne($this->tenant->id, 'FLU');
        $this->assertEquals(3, $snap->denominator);
        $this->assertEquals(3, $snap->numerator);
        $this->assertEquals(100.00, (float) $snap->rate_pct);
    }

    public function test_pcv_measure_rate_zero_without_visits(): void
    {
        for ($i = 0; $i < 2; $i++) {
            Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        }
        $snap = (new QualityMeasureService())->computeOne($this->tenant->id, 'PCV');
        $this->assertEquals(0, $snap->numerator);
        $this->assertEquals(2, $snap->denominator);
    }

    public function test_compute_all_creates_snapshot_per_measure(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $snaps = (new QualityMeasureService())->computeAll($this->tenant->id);
        $this->assertGreaterThanOrEqual(8, count($snaps));
        $this->assertGreaterThanOrEqual(8, QualityMeasureSnapshot::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_snapshots_endpoint_groups_by_measure(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        (new QualityMeasureService())->computeAll($this->tenant->id);
        $this->actingAs($this->qa);
        $r = $this->getJson('/quality-measures/snapshots?days=30');
        $r->assertOk();
        $this->assertArrayHasKey('FLU', $r->json('rows'));
    }

    public function test_compute_endpoint_writes_fresh_snapshots(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
        $this->actingAs($this->qa);
        $before = QualityMeasureSnapshot::count();
        $this->postJson('/quality-measures/compute')->assertOk();
        $this->assertGreaterThan($before, QualityMeasureSnapshot::count());
    }
}

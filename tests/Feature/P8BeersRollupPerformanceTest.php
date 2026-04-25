<?php

// ─── Phase P8 — Beers rollup query-count regression ────────────────────────
namespace Tests\Feature;

use App\Models\BeersCriterion;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BeersCriteriaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class P8BeersRollupPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenantWithMeds(int $count): array
    {
        $t = Tenant::factory()->create();
        $prefix = strtoupper(\Illuminate\Support\Str::random(3));
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => $prefix]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'pharmacy',
            'role' => 'admin', 'is_active' => true,
        ]);
        // Seed a couple of Beers criteria.
        BeersCriterion::create([
            'drug_keyword' => 'diphenhydramine',
            'risk_category' => 'Anticholinergic',
            'rationale' => 'High anticholinergic burden in older adults.',
            'recommendation' => 'Avoid',
            'evidence_quality' => 'high',
        ]);
        $participants = collect();
        for ($i = 0; $i < $count; $i++) {
            $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
            Medication::create([
                'tenant_id' => $t->id, 'participant_id' => $p->id,
                'drug_name' => 'Diphenhydramine 25mg', 'status' => 'active',
                'prescribed_by_user_id' => $u->id,
                'start_date' => now()->subWeek(),
            ]);
            $participants->push($p);
        }
        return [$t, $u, $participants];
    }

    public function test_batch_returns_same_shape_as_single(): void
    {
        [$t, $u, $participants] = $this->setupTenantWithMeds(3);
        $svc = new BeersCriteriaService();
        $single = $svc->evaluate($participants[0]);
        $batch  = collect($svc->evaluateBatch($participants))
            ->firstWhere('participant_id', $participants[0]->id);
        $this->assertNotNull($batch);
        $this->assertEquals(count($single), count($batch['flags']));
    }

    public function test_batch_query_count_is_constant(): void
    {
        [$t, $u, $participants] = $this->setupTenantWithMeds(10);
        $svc = new BeersCriteriaService();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $svc->evaluateBatch($participants);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 1 query for medications + 1 for criterion table = 2. Allow tiny
        // headroom for boot-time metadata queries; cap at 5.
        $this->assertLessThanOrEqual(5, $count, "Batch path used {$count} queries; expected ≤5 (was N+1 before P8).");
    }
}

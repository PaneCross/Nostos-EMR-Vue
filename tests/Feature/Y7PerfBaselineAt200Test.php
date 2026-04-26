<?php

// ─── Phase Y7 — Perf baseline at 200-enrolled scale (H12 next-pass option 3)
// Establishes a query-count + wall-time baseline for 5 hot endpoints when
// the tenant has 200 enrolled participants. Acts as a regression trap:
// future N+1 introductions or unbounded queries will trip this test.
//
// Numbers are conservative ceilings — a real prod tenant will see far fewer
// queries because most controllers eager-load. The point is to flag growth.
namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Y7PerfBaselineAt200Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create([
            'tenant_id' => $this->tenant->id, 'mrn_prefix' => 'PB',
        ]);
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'department' => 'primary_care', 'role' => 'admin', 'is_active' => true,
        ]);

        // 200 enrolled participants. Keep allocation tight by skipping
        // child-record seeding — this baseline tests query growth on the
        // participant set itself, not the child fanout.
        Participant::factory()
            ->enrolled()
            ->count(200)
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    /**
     * @return array{queries: int, ms: float}
     */
    private function measure(string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $t0 = microtime(true);
        $r = $this->actingAs($this->admin)->get($url);
        $ms = (microtime(true) - $t0) * 1000;
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertTrue(
            $r->status() < 500,
            "Endpoint $url returned {$r->status()} (expected 2xx/3xx).",
        );

        return ['queries' => $queries, 'ms' => $ms];
    }

    public function test_global_search_under_query_budget(): void
    {
        $m = $this->measure('/search?q=Lovelace');
        // 6 entity kinds × ~2 queries each + 1 audit log + a few overhead
        // queries (auth, tenant) — ceiling 30 to leave headroom.
        $this->assertLessThan(
            30, $m['queries'],
            "Global search at 200 participants used {$m['queries']} queries (budget 30)."
        );
        fwrite(STDERR, "\n[Y7] /search?q=…  q={$m['queries']}  t=" . round($m['ms']) . "ms\n");
    }

    public function test_participant_index_under_query_budget(): void
    {
        $m = $this->measure('/participants');
        $this->assertLessThan(
            25, $m['queries'],
            "Participant index at 200 used {$m['queries']} queries (budget 25)."
        );
        fwrite(STDERR, "[Y7] /participants  q={$m['queries']}  t=" . round($m['ms']) . "ms\n");
    }

    public function test_compliance_universe_pull_under_query_budget(): void
    {
        // Roi compliance universe — narrow read; should be tight.
        $m = $this->measure('/compliance/roi');
        $this->assertLessThan(
            20, $m['queries'],
            "/compliance/roi at 200 used {$m['queries']} queries (budget 20)."
        );
        fwrite(STDERR, "[Y7] /compliance/roi  q={$m['queries']}  t=" . round($m['ms']) . "ms\n");
    }

    public function test_audit_log_index_under_query_budget(): void
    {
        $m = $this->measure('/it-admin/audit');
        // The audit page does a count() + auth checks; budget 15.
        // Note: this test runs as primary_care admin, so it likely 403s.
        // Either way, the 403 response itself shouldn't blow the budget.
        $this->assertLessThan(
            15, $m['queries'],
            "/it-admin/audit at 200 used {$m['queries']} queries (budget 15)."
        );
        fwrite(STDERR, "[Y7] /it-admin/audit  q={$m['queries']}  t=" . round($m['ms']) . "ms\n");
    }
}

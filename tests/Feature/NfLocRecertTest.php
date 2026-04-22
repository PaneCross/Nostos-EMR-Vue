<?php

// ─── NfLocRecertTest ──────────────────────────────────────────────────────────
// Phase 2 (MVP roadmap) — NF-LOC annual recertification §460.160(b)(2).
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\NfLocRecertAlertJob;
use App\Models\Alert;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NfLocRecertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $enrollmentUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'NFT']);
        $this->enrollmentUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'enrollment',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_days_remaining_is_null_when_waived(): void
    {
        $p = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => now()->addDays(40)->toDateString(),
            'nf_recert_waived' => true,
            'nf_recert_waived_reason' => 'Stable, state waiver on file.',
        ]);
        $this->assertNull($p->nfLocRecertDaysRemaining());
        $this->assertFalse($p->nfLocRecertOverdue());
    }

    public function test_days_remaining_calculates_correctly(): void
    {
        Carbon::setTestNow('2026-06-01 10:00:00');
        $p = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => '2026-06-30',
        ]);
        $this->assertEquals(29, $p->nfLocRecertDaysRemaining());
        $this->assertFalse($p->nfLocRecertOverdue());
    }

    public function test_detects_overdue(): void
    {
        Carbon::setTestNow('2026-06-01');
        $p = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => '2026-05-01',
        ]);
        $this->assertLessThan(0, $p->nfLocRecertDaysRemaining());
        $this->assertTrue($p->nfLocRecertOverdue());
    }

    public function test_job_creates_alerts_at_thresholds(): void
    {
        Carbon::setTestNow('2026-06-01 08:00:00');
        // 30-day threshold
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => Carbon::parse('2026-07-01')->toDateString(),
        ]);
        // Overdue by 5 days
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => Carbon::parse('2026-05-27')->toDateString(),
        ]);

        $job = new NfLocRecertAlertJob();
        $job->handle(app(AlertService::class));

        // 30-day (warning) + overdue (critical) alerts created
        $this->assertEquals(2, Alert::where('tenant_id', $this->tenant->id)->count());
        $this->assertTrue(Alert::where('alert_type', 'nf_loc_recert_30d')->exists());
        $this->assertTrue(Alert::where('alert_type', 'nf_loc_recert_overdue')->exists());
    }

    public function test_job_skips_waived_participants(): void
    {
        Carbon::setTestNow('2026-06-01');
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => Carbon::parse('2026-05-01')->toDateString(),
            'nf_recert_waived' => true,
        ]);

        $job = new NfLocRecertAlertJob();
        $job->handle(app(AlertService::class));

        $this->assertEquals(0, Alert::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_job_dedupes_repeated_runs(): void
    {
        Carbon::setTestNow('2026-06-01');
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => Carbon::parse('2026-07-01')->toDateString(),  // 30 days out
        ]);

        $job = new NfLocRecertAlertJob();
        $job->handle(app(AlertService::class));
        $job->handle(app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'nf_loc_recert_30d')->count());
    }

    public function test_enrollment_dashboard_widget_returns_participants(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => now()->addDays(20)->toDateString(),
        ]);

        $this->actingAs($this->enrollmentUser)
            ->getJson('/dashboards/enrollment/nf-loc-recert')
            ->assertOk()
            ->assertJsonStructure(['participants' => [['id','name','mrn','expires_at','days_remaining','overdue']], 'count_total', 'count_overdue'])
            ->assertJsonPath('count_total', 1);
    }

    public function test_compliance_audit_universe_returns_summary(): void
    {
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->count(3)->create([
            'nf_certification_expires_at' => now()->addDays(100)->toDateString(),
        ]);
        Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'nf_certification_expires_at' => now()->subDays(5)->toDateString(),
        ]);

        $this->actingAs($this->enrollmentUser)
            ->getJson('/compliance/nf-loc-status')
            ->assertOk()
            ->assertJsonStructure(['rows', 'summary' => ['count_total', 'count_overdue', 'count_current']]);
    }
}

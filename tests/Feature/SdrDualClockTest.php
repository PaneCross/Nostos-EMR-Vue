<?php

// ─── SdrDualClockTest ─────────────────────────────────────────────────────────
// Phase 2 (MVP roadmap) — SDR standard 72h vs expedited 24h clock §460.121.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SdrDeadlineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SdrDualClockTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $idtUser;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'SDR']);
        $this->idtUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'idt',
            'role'      => 'admin',
            'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_standard_sdr_gets_72_hour_due(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');
        $sdr = Sdr::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'sdr_type' => Sdr::TYPE_STANDARD,
            'submitted_at' => now(),
        ]);
        $this->assertEquals('2026-06-04 09:00:00', $sdr->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_expedited_sdr_gets_24_hour_due(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');
        $sdr = Sdr::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'sdr_type' => Sdr::TYPE_EXPEDITED,
            'submitted_at' => now(),
        ]);
        $this->assertEquals('2026-06-02 09:00:00', $sdr->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_changing_sdr_type_on_open_sdr_reslides_due_at(): void
    {
        Carbon::setTestNow('2026-06-01 09:00:00');
        $sdr = Sdr::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'sdr_type' => Sdr::TYPE_STANDARD,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $sdr->update(['sdr_type' => Sdr::TYPE_EXPEDITED]);
        $this->assertEquals('2026-06-02 09:00:00', $sdr->fresh()->due_at->format('Y-m-d H:i:s'));
    }

    public function test_expedited_warning_alert_fires_at_3h_remaining(): void
    {
        Carbon::setTestNow('2026-06-01 08:00:00');
        Sdr::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'sdr_type' => Sdr::TYPE_EXPEDITED,
            'submitted_at' => now(),
            'assigned_department' => 'primary_care',
        ]);

        // Fast-forward to 21 hours later (3 hours remaining on 24h clock)
        Carbon::setTestNow('2026-06-02 05:00:00');

        $svc = app(SdrDeadlineService::class);
        $svc->processBatch(Sdr::open()->get());

        $this->assertTrue(
            Alert::where('tenant_id', $this->tenant->id)
                ->where('alert_type', 'sdr_warning_3h')
                ->exists()
        );
    }

    public function test_idt_dashboard_sla_widget_returns_data(): void
    {
        Sdr::factory()->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
            'sdr_type' => Sdr::TYPE_EXPEDITED,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->idtUser)
            ->getJson('/dashboards/idt/sdr-sla')
            ->assertOk()
            ->assertJsonStructure(['sdrs' => [['id','sdr_type','hours_remaining','window_pct','overdue']], 'count_open', 'count_expedited', 'count_overdue'])
            ->assertJsonPath('count_expedited', 1);
    }

    public function test_compliance_sdr_sla_universe_returns_all_sdrs(): void
    {
        $qaUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'qa_compliance',
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sdr::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'participant_id' => $this->participant->id,
        ]);

        $this->actingAs($qaUser)
            ->getJson('/compliance/sdr-sla')
            ->assertOk()
            ->assertJsonPath('count', 3);
    }
}

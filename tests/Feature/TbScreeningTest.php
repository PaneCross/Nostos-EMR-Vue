<?php

// ─── TbScreeningTest ─────────────────────────────────────────────────────────
// Phase C2a — TB screening (42 CFR §460.71).
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\TbScreeningDueJob;
use App\Models\Alert;
use App\Models\Participant;
use App\Models\Site;
use App\Models\TbScreening;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TbScreeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pcp;
    private User $qa;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'TB']);
        $this->pcp = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    public function test_pcp_can_record_ppd_negative(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/tb-screenings", [
            'screening_type' => 'ppd',
            'performed_date' => now()->subDays(1)->toDateString(),
            'result'         => 'negative',
            'induration_mm'  => 0,
        ]);
        $r->assertStatus(201);
        $row = TbScreening::first();
        $this->assertEquals('ppd', $row->screening_type);
        $this->assertNotNull($row->next_due_date);
    }

    public function test_ppd_requires_induration(): void
    {
        $this->actingAs($this->pcp);
        $r = $this->postJson("/participants/{$this->participant->id}/tb-screenings", [
            'screening_type' => 'ppd',
            'performed_date' => now()->toDateString(),
            'result'         => 'positive',
        ]);
        $r->assertStatus(422);
        $this->assertEquals('induration_mm_required', $r->json('error'));
    }

    public function test_quantiferon_does_not_require_induration(): void
    {
        $this->actingAs($this->pcp);
        $this->postJson("/participants/{$this->participant->id}/tb-screenings", [
            'screening_type' => 'quantiferon',
            'performed_date' => now()->toDateString(),
            'result'         => 'negative',
        ])->assertStatus(201);
    }

    public function test_due_job_alerts_when_missing(): void
    {
        (new TbScreeningDueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertTrue(Alert::where('alert_type', 'tb_screening_overdue')
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$this->participant->id])
            ->exists());
    }

    public function test_due_job_alerts_at_30_day_threshold(): void
    {
        TbScreening::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'recorded_by_user_id' => $this->pcp->id,
            'screening_type' => 'quantiferon',
            'performed_date' => now()->subDays(340),
            'result' => 'negative',
            'next_due_date' => now()->addDays(25), // inside 30 but > 0
        ]);
        (new TbScreeningDueJob())->handle(app(\App\Services\AlertService::class));

        $alert = Alert::where('alert_type', 'tb_screening_due_30')
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$this->participant->id])
            ->first();
        $this->assertNotNull($alert);
        $this->assertEquals('warning', $alert->severity);
    }

    public function test_due_job_skips_current_screenings(): void
    {
        TbScreening::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'recorded_by_user_id' => $this->pcp->id,
            'screening_type' => 'quantiferon',
            'performed_date' => now()->subDays(30),
            'result' => 'negative',
            'next_due_date' => now()->addDays(335),
        ]);
        (new TbScreeningDueJob())->handle(app(\App\Services\AlertService::class));
        $this->assertEquals(0, Alert::where('alert_type', 'like', 'tb_screening_%')
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$this->participant->id])
            ->count());
    }

    public function test_compliance_universe_returns_rows(): void
    {
        TbScreening::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'recorded_by_user_id' => $this->pcp->id,
            'screening_type' => 'quantiferon',
            'performed_date' => now()->subDays(30),
            'result' => 'negative',
            'next_due_date' => now()->addDays(335),
        ]);
        $this->actingAs($this->qa);
        $r = $this->getJson('/compliance/tb-screening');
        $r->assertOk();
        $this->assertEquals(1, $r->json('summary.count_total'));
        $this->assertEquals(1, $r->json('summary.count_current'));
    }
}

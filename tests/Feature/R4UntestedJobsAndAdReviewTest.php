<?php

// ─── Phase R4 — Cron job test coverage + §460.116(b) AD review ─────────────
namespace Tests\Feature;

use App\Jobs\DocumentationComplianceJob;
use App\Jobs\IdtReviewFrequencyJob;
use App\Jobs\QualityMeasureSnapshotJob;
use App\Models\Alert;
use App\Models\ClinicalNote;
use App\Models\Participant;
use App\Models\QualityMeasureSnapshot;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AlertService;
use App\Services\QaMetricsService;
use App\Services\QualityMeasureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R4UntestedJobsAndAdReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_measure_snapshot_job_persists_snapshots(): void
    {
        $t = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'QM']);
        Participant::factory()->enrolled()->forTenant($t->id)->create();

        app(QualityMeasureSnapshotJob::class)->handle(app(QualityMeasureService::class));

        $this->assertGreaterThan(0, QualityMeasureSnapshot::where('tenant_id', $t->id)->count());
    }

    public function test_documentation_compliance_job_emits_unsigned_note_alert(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'DC']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'primary_care', 'role' => 'standard', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();

        // Note from 30 hours ago, unsigned
        $note = ClinicalNote::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'site_id' => $site->id,
            'authored_by_user_id' => $u->id, 'department' => 'primary_care',
            'note_type' => 'soap', 'subjective' => 'S', 'objective' => 'O',
            'assessment' => 'A', 'plan' => 'P',
            'status' => 'draft', 'visit_date' => now()->subDays(2)->toDateString(),
        ]);
        $note->created_at = now()->subHours(30);
        $note->save();

        app(DocumentationComplianceJob::class)->handle(app(QaMetricsService::class), app(AlertService::class));

        $this->assertEquals(1, Alert::where('alert_type', 'unsigned_note')
            ->where('participant_id', $p->id)->count());
    }

    public function test_idt_review_frequency_job_emits_ad_review_overdue_alert(): void
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'AD']);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create([
            'advance_directive_reviewed_at' => now()->subMonths(7),
        ]);

        app(IdtReviewFrequencyJob::class)->handle();

        $this->assertEquals(1, Alert::where('alert_type', 'advance_directive_review_overdue')
            ->where('participant_id', $p->id)->count());
    }
}

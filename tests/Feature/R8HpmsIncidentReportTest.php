<?php

// ─── Phase R8 — HPMS Incident Reports (5 CMS-aligned exports) ──────────────
namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R8HpmsIncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private function setupHpms(): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'HP']);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true,
        ]);
        $p = Participant::factory()->enrolled()->forTenant($t->id)->forSite($site->id)->create();
        return [$t, $u, $p];
    }

    public function test_index_renders_inertia_page_with_5_summary_keys(): void
    {
        [$t, $u, $p] = $this->setupHpms();
        $this->actingAs($u);
        $this->get('/compliance/hpms-incident-reports')->assertOk()
            ->assertInertia(fn ($pg) => $pg
                ->component('Compliance/HpmsIncidentReports')
                ->has('summary.falls')
                ->has('summary.medication_errors')
                ->has('summary.abuse_neglect')
                ->has('summary.unexpected_deaths')
                ->has('summary.elopements')
            );
    }

    public function test_falls_csv_export_streams_filtered_rows(): void
    {
        [$t, $u, $p] = $this->setupHpms();
        Incident::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'incident_type' => 'fall', 'occurred_at' => now()->subDays(5),
            'reported_at' => now()->subDays(5), 'reported_by_user_id' => $u->id,
            'description' => 'Slip in dining room.', 'rca_required' => true,
            'status' => 'open',
        ]);
        Incident::create([
            'tenant_id' => $t->id, 'participant_id' => $p->id,
            'incident_type' => 'medication_error', 'occurred_at' => now()->subDays(3),
            'reported_at' => now()->subDays(3), 'reported_by_user_id' => $u->id,
            'description' => 'Wrong dose given.', 'rca_required' => true,
            'status' => 'open',
        ]);

        $this->actingAs($u);
        $r = $this->get('/compliance/hpms-incident-reports/falls.csv');
        $r->assertOk();
        $body = $r->streamedContent();
        $this->assertStringContainsString('incident_id,occurred_at', $body);
        $this->assertStringContainsString('Slip in dining room', $body);
        $this->assertStringNotContainsString('Wrong dose given', $body);
    }

    public function test_unknown_report_returns_404(): void
    {
        [$t, $u, $p] = $this->setupHpms();
        $this->actingAs($u);
        $this->get('/compliance/hpms-incident-reports/bogus.csv')->assertNotFound();
    }
}

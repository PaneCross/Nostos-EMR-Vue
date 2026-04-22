<?php

// ─── QapiAnnualEvaluationTest ─────────────────────────────────────────────────
// Phase 2 (MVP roadmap) — annual QAPI evaluation artifact §460.200.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\QapiAnnualEvaluation;
use App\Models\QapiProject;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\QapiAnnualEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QapiAnnualEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $qaUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'QAP']);
        $this->qaUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
    }

    public function test_service_generates_evaluation_with_pdf(): void
    {
        QapiProject::factory()->create([
            'tenant_id' => $this->tenant->id,
            'title'     => 'Reduce hospital readmits',
            'status'    => 'active',
            'start_date'=> '2026-03-01',
        ]);

        $svc = app(QapiAnnualEvaluationService::class);
        $evaluation = $svc->generate($this->tenant, 2026, $this->qaUser);

        $this->assertEquals(2026, $evaluation->year);
        $this->assertNotNull($evaluation->pdf_path);
        $this->assertGreaterThan(0, $evaluation->pdf_size_bytes);
        $this->assertEquals(1, $evaluation->summary_snapshot['total_projects'] ?? 0);
        $this->assertEquals(1, $evaluation->summary_snapshot['active_count'] ?? 0);
    }

    public function test_regenerating_preserves_governing_body_review(): void
    {
        $svc = app(QapiAnnualEvaluationService::class);
        $e1 = $svc->generate($this->tenant, 2026, $this->qaUser);

        $e1 = $svc->recordGoverningBodyReview($e1, $this->qaUser, 'Reviewed at June board.');
        $this->assertNotNull($e1->governing_body_reviewed_at);

        $e2 = $svc->generate($this->tenant, 2026, $this->qaUser);

        $this->assertEquals($e1->id, $e2->id, 'Should update existing row, not create new.');
        $this->assertNotNull($e2->governing_body_reviewed_at, 'Review stamp should be preserved.');
        $this->assertEquals('Reviewed at June board.', $e2->governing_body_notes);
    }

    public function test_endpoint_generates_and_downloads_pdf(): void
    {
        $this->actingAs($this->qaUser)
            ->postJson('/qapi/evaluations', ['year' => 2026])
            ->assertStatus(201)
            ->assertJsonPath('year', 2026);

        $eval = QapiAnnualEvaluation::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->actingAs($this->qaUser)
            ->get("/qapi/evaluations/{$eval->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_record_review_endpoint_stamps_evaluation(): void
    {
        $svc = app(QapiAnnualEvaluationService::class);
        $eval = $svc->generate($this->tenant, 2026, $this->qaUser);

        $this->actingAs($this->qaUser)
            ->postJson("/qapi/evaluations/{$eval->id}/review", ['notes' => 'Board approved.'])
            ->assertOk();

        $eval->refresh();
        $this->assertNotNull($eval->governing_body_reviewed_at);
        $this->assertEquals('Board approved.', $eval->governing_body_notes);
    }

    public function test_non_qa_user_cannot_generate(): void
    {
        $otherUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);

        $this->actingAs($otherUser)
            ->postJson('/qapi/evaluations', ['year' => 2026])
            ->assertStatus(403);
    }

    public function test_cannot_download_other_tenants_evaluation(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherQa = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'department' => 'qa_compliance',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $eval = app(QapiAnnualEvaluationService::class)->generate($otherTenant, 2026, $otherQa);

        $this->actingAs($this->qaUser)
            ->get("/qapi/evaluations/{$eval->id}/download")
            ->assertStatus(403);
    }
}

<?php

// ─── FhirBulkExportTest ───────────────────────────────────────────────────────
// Phase 15.1 (MVP roadmap). Covers HL7 FHIR Bulk Data Access flow:
//   POST $export → 202 + Content-Location
//   GET status    → 202 in-progress / 200 manifest / errors
//   GET file      → NDJSON stream
//   DELETE        → 202 cancel
//
// Queue is sync (test default) so the RunFhirBulkExportJob runs inline and
// the job completes by the time the test reads status.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\FhirBulkExportJob;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class FhirBulkExportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $user;
    private ApiToken $token;
    private string $plaintext;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'BULK']);
        $this->user   = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->plaintext = Str::random(64);
        $this->token = ApiToken::create([
            'user_id'   => $this->user->id,
            'tenant_id' => $this->tenant->id,
            'token'     => ApiToken::hashToken($this->plaintext),
            'scopes'    => ['system/*.read'],
            'name'      => 'bulk test',
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->plaintext, 'Accept' => 'application/fhir+json'];
    }

    // ── Kickoff ─────────────────────────────────────────────────────────────

    public function test_export_kickoff_requires_prefer_respond_async(): void
    {
        $r = $this->post('/fhir/R4/$export', [], $this->auth());
        $r->assertStatus(400);
        $this->assertEquals('OperationOutcome', $r->json('resourceType'));
    }

    public function test_export_kickoff_returns_202_with_content_location(): void
    {
        $r = $this->post('/fhir/R4/$export', [],
            array_merge($this->auth(), ['Prefer' => 'respond-async']));
        $r->assertStatus(202);
        $r->assertHeader('Content-Location');
        $this->assertStringContainsString('/fhir/R4/export-status/', $r->headers->get('Content-Location'));
        $this->assertDatabaseHas('emr_fhir_bulk_export_jobs', [
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_export_kickoff_rejects_unknown_resource_type(): void
    {
        $r = $this->post('/fhir/R4/$export?_type=BogusType',
            [], array_merge($this->auth(), ['Prefer' => 'respond-async']));
        $r->assertStatus(400);
    }

    public function test_export_kickoff_requires_bearer_token(): void
    {
        $this->post('/fhir/R4/$export', [], ['Prefer' => 'respond-async'])
            ->assertStatus(401);
    }

    // ── Status ──────────────────────────────────────────────────────────────

    public function test_status_returns_manifest_on_completion(): void
    {
        Queue::fake(); // capture dispatch so we can assert the chain
        $kick = $this->post('/fhir/R4/$export', [],
            array_merge($this->auth(), ['Prefer' => 'respond-async']))
            ->assertStatus(202);

        // Synchronously execute the job (Queue::fake swallows it; run inline)
        $jobRow = FhirBulkExportJob::latest('id')->first();
        app(\App\Services\FhirBulkExportService::class)->run($jobRow->refresh());

        $r = $this->get('/fhir/R4/export-status/' . $jobRow->id, $this->auth());
        $r->assertStatus(200);
        $manifest = $r->json();
        $this->assertArrayHasKey('transactionTime', $manifest);
        $this->assertArrayHasKey('output', $manifest);
        // At least one resource type (Patient) should have produced a file.
        $patientEntry = collect($manifest['output'])->firstWhere('type', 'Patient');
        $this->assertNotNull($patientEntry);
        $this->assertStringContainsString('Patient.ndjson', $patientEntry['url']);
        $this->assertGreaterThanOrEqual(1, $patientEntry['count']);
    }

    public function test_status_returns_404_on_cross_tenant_access(): void
    {
        $job = FhirBulkExportJob::create([
            'tenant_id' => $this->tenant->id, 'status' => 'complete', 'progress_pct' => 100,
            'manifest_json' => '{"output":[]}',
        ]);
        $other = Tenant::factory()->create();
        $outsiderPlain = Str::random(64);
        ApiToken::create([
            'user_id'   => null, 'tenant_id' => $other->id,
            'token'     => ApiToken::hashToken($outsiderPlain),
            'scopes'    => ['system/*.read'], 'name' => 'outsider',
        ]);
        $this->get('/fhir/R4/export-status/' . $job->id,
            ['Authorization' => 'Bearer ' . $outsiderPlain])->assertStatus(404);
    }

    public function test_status_returns_202_in_progress_while_running(): void
    {
        $job = FhirBulkExportJob::create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'in_progress',
            'progress_pct' => 45,
        ]);
        $r = $this->get('/fhir/R4/export-status/' . $job->id, $this->auth());
        $r->assertStatus(202);
        $r->assertHeader('X-Progress');
        $this->assertStringContainsString('45%', $r->headers->get('X-Progress'));
    }

    // ── File download ────────────────────────────────────────────────────────

    public function test_file_download_returns_ndjson_after_export(): void
    {
        // Kickoff + run inline
        $this->post('/fhir/R4/$export', [],
            array_merge($this->auth(), ['Prefer' => 'respond-async']))->assertStatus(202);
        $job = FhirBulkExportJob::latest('id')->first();
        app(\App\Services\FhirBulkExportService::class)->run($job->refresh());

        $r = $this->get('/fhir/R4/export-file/' . $job->id . '/Patient.ndjson', $this->auth());
        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/fhir+ndjson');
        $content = $r->streamedContent();
        $this->assertNotEmpty($content);
        // Each line should parse as a JSON FHIR resource
        $first = json_decode(strtok($content, "\n"), true);
        $this->assertEquals('Patient', $first['resourceType'] ?? null);
    }

    public function test_file_download_404s_for_missing_resource(): void
    {
        $job = FhirBulkExportJob::create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'complete', 'progress_pct' => 100,
            'manifest_json' => '{"output":[]}',
        ]);
        $this->get('/fhir/R4/export-file/' . $job->id . '/NotARealResource.ndjson', $this->auth())
            ->assertStatus(404);
    }

    // ── Cancel ──────────────────────────────────────────────────────────────

    public function test_cancel_transitions_job_to_cancelled(): void
    {
        $job = FhirBulkExportJob::create([
            'tenant_id' => $this->tenant->id,
            'status'    => 'in_progress', 'progress_pct' => 20,
        ]);
        $this->delete('/fhir/R4/export-status/' . $job->id, [], $this->auth())->assertStatus(202);
        $this->assertEquals('cancelled', $job->fresh()->status);
    }

    // ── Capability advertised ───────────────────────────────────────────────

    public function test_capability_statement_advertises_export_operation(): void
    {
        $r = $this->getJson('/fhir/R4/metadata');
        $r->assertOk();
        $ops = collect($r->json('rest.0.operation') ?? []);
        $this->assertTrue($ops->contains(fn ($op) => ($op['name'] ?? '') === 'export'));
    }
}

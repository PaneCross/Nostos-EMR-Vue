<?php

// ─── Phase15MediumWinsTest ────────────────────────────────────────────────────
// Phase 15 (medium-term wins batch) — coverage for 9 sub-items shipped in
// one pass: 15.2 SAML scaffold, 15.3 Reports, 15.4 Data imports, 15.5 Mobile
// ADL, 15.6 CDS, 15.7 HRIS webhook, 15.8 Committees, 15.9 State Medicaid
// submission, 15.10 Formulary.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Committee;
use App\Models\CommitteeMeeting;
use App\Models\EdiBatch;
use App\Models\FormularyEntry;
use App\Models\HrisConfig;
use App\Models\HrisEvent;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\ReportDefinition;
use App\Models\SamlIdentityProvider;
use App\Models\Site;
use App\Models\StateMedicaidConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vital;
use App\Services\ClinicalDecisionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Phase15MediumWinsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $pharmacy;
    private User $qa;
    private User $itAdmin;
    private User $homeCare;
    private User $executive;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'P15']);
        $this->pharmacy = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'pharmacy',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->qa = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'qa_compliance',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->itAdmin = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'it_admin',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->homeCare = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'home_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->executive = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'executive',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create();
    }

    // ── 15.10 Formulary ─────────────────────────────────────────────────────

    public function test_formulary_store_creates_entry(): void
    {
        $this->actingAs($this->pharmacy);
        $r = $this->postJson('/formulary', [
            'drug_name' => 'Lisinopril',
            'tier' => 1,
            'prior_authorization_required' => false,
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_formulary_entries', ['drug_name' => 'Lisinopril', 'tier' => 1]);
    }

    public function test_formulary_check_returns_match_with_restrictions(): void
    {
        FormularyEntry::create([
            'tenant_id' => $this->tenant->id,
            'drug_name' => 'Apixaban',
            'tier' => 3,
            'prior_authorization_required' => true,
            'is_active' => true,
        ]);
        $this->actingAs($this->pharmacy);
        $r = $this->getJson('/formulary/check?drug_name=Apixaban');
        $r->assertOk();
        $this->assertTrue($r->json('on_formulary'));
        $this->assertContains('prior_authorization', $r->json('restrictions'));
    }

    public function test_coverage_determination_stores_pending(): void
    {
        $this->actingAs($this->pharmacy);
        $r = $this->postJson("/participants/{$this->participant->id}/coverage-determinations", [
            'drug_name' => 'Rivaroxaban',
            'determination_type' => 'prior_authorization',
            'clinical_justification' => 'Atrial fibrillation with history of GI bleed on warfarin.',
        ]);
        $r->assertStatus(201);
        $this->assertDatabaseHas('emr_coverage_determinations', [
            'participant_id' => $this->participant->id,
            'determination_type' => 'prior_authorization',
            'status' => 'pending',
        ]);
    }

    // ── 15.3 Reports ────────────────────────────────────────────────────────

    public function test_report_store_and_run_returns_rows(): void
    {
        $this->actingAs($this->qa);
        $def = ReportDefinition::create([
            'tenant_id' => $this->tenant->id,
            'created_by_user_id' => $this->qa->id,
            'name' => 'Active participants',
            'entity' => 'participants',
            'filters' => [['field' => 'enrollment_status', 'op' => '=', 'value' => 'enrolled']],
            'columns' => ['id', 'first_name', 'last_name', 'enrollment_status'],
        ]);
        $r = $this->postJson("/reports/{$def->id}/run");
        $r->assertOk();
        $this->assertGreaterThanOrEqual(1, $r->json('total'));
    }

    public function test_report_csv_download(): void
    {
        $def = ReportDefinition::create([
            'tenant_id' => $this->tenant->id,
            'created_by_user_id' => $this->qa->id,
            'name' => 'CSV export',
            'entity' => 'participants',
            'columns' => ['id', 'first_name'],
        ]);
        $this->actingAs($this->qa);
        $r = $this->get("/reports/{$def->id}/download");
        $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    // ── 15.4 Data imports ───────────────────────────────────────────────────

    public function test_data_import_template_download(): void
    {
        $this->actingAs($this->itAdmin);
        $r = $this->get('/data-imports/template/participants');
        $r->assertOk();
        $this->assertStringContainsString('first_name', $r->getContent());
    }

    public function test_data_import_upload_stages_then_commits_participants(): void
    {
        Storage::fake('local');
        $csv = "first_name,last_name,dob,gender,enrollment_status,enrollment_date,medicare_id,medicaid_id,primary_language\n"
             . "Grace,Hopper,1906-12-09,female,enrolled,2020-01-01,GH111,GH222,English\n";
        $file = UploadedFile::fake()->createWithContent('participants.csv', $csv);

        $this->actingAs($this->itAdmin);
        $upload = $this->post('/data-imports', ['entity' => 'participants', 'file' => $file]);
        $upload->assertStatus(201);
        $importId = $upload->json('import.id');

        $commit = $this->postJson("/data-imports/{$importId}/commit");
        $commit->assertOk();
        $this->assertEquals(1, $commit->json('result.inserted'));
        $this->assertDatabaseHas('emr_participants', ['first_name' => 'Grace', 'last_name' => 'Hopper']);
    }

    // ── 15.5 Mobile ADL ─────────────────────────────────────────────────────

    public function test_mobile_adl_redirects_to_canonical_mobile_page(): void
    {
        // Phase O8: /home-care/mobile-adl now redirects to /mobile (the canonical
        // home-care day-list entry). MobileAdl.vue was deleted; the ADL quick-
        // capture flow lives on the participant ADL tab reachable from /mobile.
        $this->actingAs($this->homeCare);
        $this->get('/home-care/mobile-adl')->assertRedirect('/mobile');
    }

    public function test_mobile_page_blocks_non_home_care(): void
    {
        // Note: /mobile allows home_care + primary_care + therapies + it_admin.
        // qa_compliance is not in that set, so it gets 403.
        $this->actingAs($this->qa);
        $this->get('/mobile')->assertForbidden();
    }

    // ── 15.6 CDS ────────────────────────────────────────────────────────────

    public function test_cds_flags_anticoag_nsaid_combination(): void
    {
        Medication::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'drug_name' => 'Warfarin 5 MG Oral Tablet', 'dose' => '5', 'dose_unit' => 'mg',
            'route' => 'oral', 'frequency' => 'daily', 'is_prn' => false,
            'status' => 'active', 'prescribed_date' => now()->toDateString(),
            'start_date' => now()->toDateString(),
        ]);
        Medication::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $this->participant->id,
            'drug_name' => 'Ibuprofen 400 MG Oral Tablet', 'dose' => '400', 'dose_unit' => 'mg',
            'route' => 'oral', 'frequency' => 'PRN', 'is_prn' => true,
            'status' => 'active', 'prescribed_date' => now()->toDateString(),
            'start_date' => now()->toDateString(),
        ]);
        $result = app(ClinicalDecisionSupportService::class)->evaluate($this->participant);
        $rules = collect($result['findings'])->pluck('rule')->all();
        $this->assertContains('anticoag_nsaid', $rules);
        $this->assertGreaterThanOrEqual(1, $result['alerts_created']);
    }

    public function test_cds_endpoint_returns_findings_json(): void
    {
        $this->actingAs($this->pharmacy);
        $r = $this->postJson("/participants/{$this->participant->id}/cds/evaluate");
        $r->assertOk();
        $r->assertJsonStructure(['participant_id', 'findings', 'alerts_created']);
    }

    // ── 15.7 HRIS webhook ───────────────────────────────────────────────────

    public function test_hris_webhook_records_event_as_ignored_when_no_config(): void
    {
        $r = $this->post("/webhooks/hris/{$this->tenant->id}/bamboohr", [
            'event_type' => 'credential_added',
            'employee_email' => 'new@example.com',
        ]);
        $r->assertStatus(202);
        $this->assertTrue($r->json('ok'));
        $this->assertStringContainsString('scaffolded', $r->json('honest_label'));
        $this->assertDatabaseHas('emr_hris_events', [
            'tenant_id' => $this->tenant->id,
            'provider' => 'bamboohr',
            'processing_status' => 'ignored',
        ]);
    }

    public function test_hris_webhook_rejects_unknown_provider(): void
    {
        $this->post("/webhooks/hris/{$this->tenant->id}/notavendor", [])->assertStatus(400);
    }

    // ── 15.8 Committees ─────────────────────────────────────────────────────

    public function test_committee_create_schedule_vote(): void
    {
        $this->actingAs($this->qa);

        // Create committee
        $c = $this->postJson('/committees', [
            'name' => 'Formulary',
            'committee_type' => 'formulary',
            'charter' => 'Reviews formulary tier changes.',
        ]);
        $c->assertStatus(201);
        $committeeId = $c->json('committee.id');

        // Schedule meeting
        $m = $this->postJson("/committees/{$committeeId}/meetings", [
            'scheduled_date' => now()->addWeek()->toDateString(),
            'agenda' => 'Q2 tier review.',
        ]);
        $m->assertStatus(201);
        $meetingId = $m->json('meeting.id');

        // Record vote
        $v = $this->postJson("/committee-meetings/{$meetingId}/votes", [
            'motion_text' => 'Move Apixaban from tier 3 to tier 2.',
            'votes_yes' => 5, 'votes_no' => 1, 'votes_abstain' => 1,
            'outcome' => 'passed',
        ]);
        $v->assertStatus(201);
        $this->assertDatabaseHas('emr_committee_votes', [
            'meeting_id' => $meetingId, 'outcome' => 'passed',
        ]);
    }

    // ── 15.9 State Medicaid submissions ─────────────────────────────────────

    public function test_state_medicaid_stage_records_staged_manual(): void
    {
        $batch = EdiBatch::create([
            'tenant_id' => $this->tenant->id, 'batch_type' => '837P',
            'file_name' => 't.x12', 'record_count' => 1, 'total_charge_amount' => 100,
            'status' => 'draft', 'submission_method' => 'clearinghouse',
            'created_by_user_id' => $this->qa->id,
        ]);

        $this->actingAs($this->qa);
        $r = $this->postJson("/state-medicaid/batches/{$batch->id}/stage/CA");
        $r->assertStatus(201);
        $this->assertEquals('staged_manual', $r->json('submission.status'));
        $this->assertStringContainsString('scaffold', $r->json('honest_label'));
    }

    // ── 15.2 SAML ───────────────────────────────────────────────────────────

    public function test_saml_metadata_returns_xml(): void
    {
        $r = $this->get("/saml/{$this->tenant->id}/metadata");
        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/samlmetadata+xml');
        $this->assertStringContainsString('<EntityDescriptor', $r->getContent());
    }

    public function test_saml_login_returns_scaffold_501_when_idp_active(): void
    {
        SamlIdentityProvider::create([
            'tenant_id' => $this->tenant->id,
            'display_name' => 'Azure AD (test)',
            'entity_id' => 'https://sts.windows.net/abc',
            'sso_url' => 'https://login.microsoftonline.com/abc/saml2',
            'x509_cert' => 'MII...',
            'sp_entity_id' => 'https://emr.test/saml/1/metadata',
            'is_active' => true,
        ]);
        $r = $this->get("/saml/{$this->tenant->id}/login");
        $r->assertStatus(501);
        $this->assertTrue($r->json('scaffold'));
    }
}

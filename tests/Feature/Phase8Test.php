<?php

// ─── Phase8Test ───────────────────────────────────────────────────────────────
// Phase 8 (MVP roadmap) — IIS HL7 VXU + C-CDA import/export + Advance
// directive PDF. Covers each service + each controller route + tenant
// isolation / auth gates.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Immunization;
use App\Models\ImmunizationSubmission;
use App\Models\Participant;
use App\Models\Site;
use App\Models\StateImmunizationRegistryConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CcdaExportService;
use App\Services\CcdaImportService;
use App\Services\Hl7VxuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class Phase8Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $clinician;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'P8']);
        $this->clinician = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create([
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
                'dob'        => '1948-04-03',
                'gender'     => 'female',
            ]);

        \App\Models\ParticipantAddress::create([
            'participant_id' => $this->participant->id,
            'address_type'   => 'home',
            'street'         => '1 Analytical Way',
            'city'           => 'Sacramento',
            'state'          => 'CA',
            'zip'            => '95814',
            'is_primary'     => true,
        ]);
    }

    // ── HL7 VXU ─────────────────────────────────────────────────────────────

    public function test_vxu_builder_emits_msh_pid_rxa_segments_with_correct_fields(): void
    {
        $imm = Immunization::create([
            'participant_id'   => $this->participant->id,
            'tenant_id'        => $this->tenant->id,
            'vaccine_type'     => 'influenza',
            'vaccine_name'     => 'Influenza (Flu)',
            'cvx_code'         => '141',
            'administered_date'=> '2026-03-15',
            'dose_number'      => 1,
            'refused'          => false,
            'vis_given'        => true,
            'vis_publication_date' => '2025-08-06',
        ]);

        $svc = app(Hl7VxuBuilder::class);
        $out = $svc->build($this->participant, $imm);

        $this->assertNotEmpty($out['message_control_id']);
        $msg = $out['message'];
        $this->assertStringStartsWith('MSH|', $msg);
        $this->assertStringContainsString('VXU^V04^VXU_V04', $msg);
        $this->assertStringContainsString('PID|1||P8-', $msg);          // MRN prefix
        $this->assertStringContainsString('Lovelace^Ada', $msg);
        $this->assertStringContainsString('19480403', $msg);            // DOB
        $this->assertStringContainsString('RXA|0|1|20260315|20260315|141^Influenza (Flu)^CVX', $msg);
        $this->assertStringContainsString('29769-7^Date VIS presented^LN', $msg);
    }

    public function test_iis_submit_endpoint_records_submission_and_writes_audit(): void
    {
        StateImmunizationRegistryConfig::create([
            'tenant_id'         => $this->tenant->id,
            'state_code'        => 'CA',
            'state_name'        => 'California',
            'registry_name'     => 'CAIR2',
            'sender_application'=> 'NostosEMR-TEST',
            'sender_facility_id'=> 'PACE-TEST',
            'is_active'         => true,
        ]);

        $imm = Immunization::create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'vaccine_type'      => 'covid_19',
            'vaccine_name'      => 'COVID-19',
            'administered_date' => '2026-04-01',
            'refused'           => false,
        ]);

        $this->actingAs($this->clinician);
        $resp = $this->postJson(
            "/participants/{$this->participant->id}/immunizations/{$imm->id}/iis-submit",
            ['state_code' => 'CA']
        );

        $resp->assertStatus(201);
        $body = $resp->json();
        $this->assertEquals('submitted', $body['submission']['status']);
        $this->assertStringContainsString('Simulated submission', $body['submission']['honest_label']);

        $this->assertDatabaseHas('emr_immunization_submissions', [
            'participant_id'  => $this->participant->id,
            'immunization_id' => $imm->id,
            'state_code'      => 'CA',
            'status'          => 'submitted',
        ]);
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'immunization.vxu_simulated_submit',
            'resource_id' => $imm->id,
        ]);
    }

    public function test_iis_submit_blocks_cross_tenant_immunization(): void
    {
        $other = Tenant::factory()->create();
        $imm = Immunization::create([
            'participant_id'    => $this->participant->id,
            'tenant_id'         => $this->tenant->id,
            'vaccine_type'      => 'influenza',
            'vaccine_name'      => 'Influenza (Flu)',
            'administered_date' => '2026-03-15',
            'refused'           => false,
        ]);

        $outsider = User::factory()->create([
            'tenant_id'  => $other->id,
            'department' => 'primary_care',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->actingAs($outsider);
        $this->postJson(
            "/participants/{$this->participant->id}/immunizations/{$imm->id}/iis-submit",
            ['state_code' => 'CA']
        )->assertNotFound();
    }

    // ── C-CDA export ────────────────────────────────────────────────────────

    public function test_ccda_export_returns_valid_xml_with_core_sections(): void
    {
        $svc = app(CcdaExportService::class);
        $xml = $svc->build($this->participant);

        $this->assertStringContainsString('<?xml version="1.0"', $xml);
        $this->assertStringContainsString('<ClinicalDocument', $xml);
        $this->assertStringContainsString('2.16.840.1.113883.10.20.22.1.2', $xml);  // CCD template
        $this->assertStringContainsString('Lovelace', $xml);
        // Core sections present
        foreach (['48765-2', '10160-0', '11450-4', '11369-6', '47519-4'] as $loinc) {
            $this->assertStringContainsString($loinc, $xml, "Missing LOINC {$loinc}");
        }

        // Parseable XML
        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
    }

    public function test_ccda_export_endpoint_returns_xml_download(): void
    {
        $this->actingAs($this->clinician);
        $resp = $this->get("/participants/{$this->participant->id}/ccda/export");
        $resp->assertOk();
        $this->assertStringContainsString('application/xml', $resp->headers->get('Content-Type'));
        $this->assertStringContainsString('ClinicalDocument', $resp->getContent());
    }

    // ── C-CDA import ────────────────────────────────────────────────────────

    public function test_ccda_import_parses_allergies_meds_problems(): void
    {
        $xml = app(CcdaExportService::class)->build($this->participant);
        $importer = app(CcdaImportService::class);

        $summary = $importer->parse($xml);
        $this->assertEquals('Ada', $summary['patient']['first_name']);
        $this->assertEquals('Lovelace', $summary['patient']['last_name']);
        $this->assertEquals('1948-04-03', $summary['patient']['dob']);
        $this->assertEquals('female', $summary['patient']['gender']);
        $this->assertIsArray($summary['allergies']);
        $this->assertIsArray($summary['medications']);
        $this->assertIsArray($summary['problems']);
    }

    public function test_ccda_import_endpoint_requires_file_and_returns_preview(): void
    {
        $xml = app(CcdaExportService::class)->build($this->participant);
        $file = UploadedFile::fake()->createWithContent('ccda.xml', $xml);

        $this->actingAs($this->clinician);
        $resp = $this->post("/participants/{$this->participant->id}/ccda/import", [
            'ccda_file' => $file,
        ], ['Accept' => 'application/json']);

        $resp->assertOk();
        $resp->assertJsonStructure(['summary' => ['patient', 'allergies', 'medications', 'problems'], 'honest_label']);
        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'ccda.imported_preview',
        ]);
    }

    // ── Advance directive PDF ───────────────────────────────────────────────

    public function test_advance_directive_pdf_endpoint_returns_pdf_bytes(): void
    {
        $this->actingAs($this->clinician);
        $resp = $this->get("/participants/{$this->participant->id}/advance-directive/pdf?type=dnr");
        $resp->assertOk();
        $resp->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $resp->getContent());
        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'advance_directive.pdf_generated',
        ]);
    }

    public function test_advance_directive_pdf_gate_rejects_unauthorized_department(): void
    {
        $u = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'transportation',
            'role'       => 'standard',
            'is_active'  => true,
        ]);
        $this->actingAs($u);
        $this->get("/participants/{$this->participant->id}/advance-directive/pdf?type=dnr")
            ->assertForbidden();
    }
}

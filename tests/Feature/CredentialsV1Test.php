<?php

// ─── CredentialsV1Test ──────────────────────────────────────────────────────
// Coverage for the V1 credentials buildout:
//  - JobTitle CRUD
//  - CredentialDefinition CRUD + CMS-mandatory locking
//  - Targeting (OR semantics : dept / job_title / designation)
//  - Per-site disable override
//  - Gap detection (missing-required)
//  - Compliance matrix building
//  - PDF upload on staff credential
//  - My Credentials self-service + renewal flow
//  - Weekly digest job
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Jobs\WeeklyCredentialDigestJob;
use App\Models\Alert;
use App\Models\CredentialDefinition;
use App\Models\CredentialDefinitionTarget;
use App\Models\JobTitle;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Credentials\CredentialComplianceService;
use App\Services\Credentials\CredentialDefinitionService;
use Database\Seeders\CmsCredentialBaselineSeeder;
use Database\Seeders\JobTitleBaselineSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CredentialsV1Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $exec;
    private User $itAdmin;
    private User $nurse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->seed(PermissionSeeder::class);
        $this->seed(JobTitleBaselineSeeder::class);
        $this->seed(CmsCredentialBaselineSeeder::class);

        $this->exec = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'executive',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->itAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'it_admin',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->nurse = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'role'       => 'standard',
            'job_title'  => 'rn',
            'is_active'  => true,
        ]);
    }

    public function test_seeders_create_baseline_definitions(): void
    {
        $defs = CredentialDefinition::where('tenant_id', $this->tenant->id)->get();
        // 12 CMS-mandatory rows : 8 original federal mandates + 4 added in
        // Phase 3 (orientation, OIG/SAM, competency eval, supervising-physician)
        $this->assertCount(12, $defs);
        $this->assertEquals(12, $defs->where('is_cms_mandatory', true)->count());
    }

    public function test_executive_can_create_custom_definition(): void
    {
        $resp = $this->actingAs($this->exec)->postJson('/executive/credential-definitions', [
            'code'  => 'dea_registration',
            'title' => 'DEA Registration',
            'credential_type' => 'license',
            'description' => 'DEA Schedule II-V prescribing.',
            'requires_psv' => true,
            'default_doc_required' => true,
            'reminder_cadence_days' => [90, 30, 0],
            'targets' => [
                ['kind' => 'job_title', 'value' => 'md'],
                ['kind' => 'job_title', 'value' => 'np'],
            ],
        ]);

        $resp->assertCreated();
        $this->assertDatabaseHas('emr_credential_definitions', ['code' => 'dea_registration']);
        $def = CredentialDefinition::where('code', 'dea_registration')->first();
        $this->assertCount(2, $def->targets);
        $this->assertFalse((bool) $def->is_cms_mandatory);
    }

    public function test_non_executive_cannot_create_definition(): void
    {
        $this->actingAs($this->nurse)
            ->postJson('/executive/credential-definitions', ['code' => 'foo', 'title' => 'X', 'credential_type' => 'license'])
            ->assertForbidden();
    }

    public function test_cms_mandatory_definition_cannot_be_deleted(): void
    {
        $hipaa = CredentialDefinition::where('code', 'hipaa_annual_training')->firstOrFail();
        $this->actingAs($this->exec)
            ->deleteJson("/executive/credential-definitions/{$hipaa->id}")
            ->assertStatus(422);
        $this->assertDatabaseHas('emr_credential_definitions', ['id' => $hipaa->id]);
    }

    public function test_targeting_or_semantics_match_user_via_dept(): void
    {
        $hipaa = CredentialDefinition::where('code', 'hipaa_annual_training')->firstOrFail();
        $svc = app(CredentialDefinitionService::class);

        // Nurse is in primary_care which is in HIPAA's all_workforce target list
        $this->assertTrue($svc->userMatchesDefinition($this->nurse, $hipaa));
    }

    public function test_targeting_or_semantics_match_user_via_job_title(): void
    {
        // Create a definition that targets only job_title=rn
        $def = CredentialDefinition::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'rn_license_test',
            'title'     => 'RN License (test)',
            'credential_type' => 'license',
            'is_cms_mandatory' => false,
        ]);
        CredentialDefinitionTarget::create([
            'credential_definition_id' => $def->id,
            'target_kind'  => 'job_title',
            'target_value' => 'rn',
        ]);

        $svc = app(CredentialDefinitionService::class);
        $this->assertTrue($svc->userMatchesDefinition($this->nurse, $def));

        // Other user with different job title : should NOT match
        $aide = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'primary_care',
            'job_title'  => 'cna',
        ]);
        $this->assertFalse($svc->userMatchesDefinition($aide, $def));
    }

    public function test_missing_definitions_detected_when_no_credential_held(): void
    {
        $svc = app(CredentialDefinitionService::class);
        // Nurse (job_title=rn, dept=primary_care) matches 11 of the 12 CMS
        // mandates (all except supervising_physician_agreement which targets
        // job_title=np/pa only).
        $missing = $svc->missingForUser($this->nurse);
        $this->assertCount(11, $missing);
    }

    public function test_missing_definitions_decrease_when_credential_added(): void
    {
        $hipaa = CredentialDefinition::where('code', 'hipaa_annual_training')->firstOrFail();

        StaffCredential::create([
            'tenant_id'  => $this->tenant->id,
            'user_id'    => $this->nurse->id,
            'credential_definition_id' => $hipaa->id,
            'credential_type' => 'training',
            'title'      => 'HIPAA 2026',
            'expires_at' => now()->addYear(),
            'cms_status' => 'active',
        ]);

        $svc = app(CredentialDefinitionService::class);
        $missing = $svc->missingForUser($this->nurse);
        $this->assertCount(10, $missing);
        $this->assertFalse($missing->contains('id', $hipaa->id));
    }

    public function test_per_site_disable_override_skips_definition_for_that_site(): void
    {
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->nurse->update(['site_id' => $site->id]);

        // Create non-mandatory definition targeting primary_care
        $def = CredentialDefinition::create([
            'tenant_id' => $this->tenant->id,
            'code'      => 'optional_thing',
            'title'     => 'Optional Thing',
            'credential_type' => 'training',
            'is_cms_mandatory' => false,
        ]);
        CredentialDefinitionTarget::create([
            'credential_definition_id' => $def->id,
            'target_kind' => 'department',
            'target_value' => 'primary_care',
        ]);

        $svc = app(CredentialDefinitionService::class);
        $this->assertTrue($svc->activeForUser($this->nurse->fresh())->contains('id', $def->id));

        // Executive disables for this site
        $this->actingAs($this->exec)
            ->postJson("/executive/credential-definitions/{$def->id}/site-overrides", ['site_id' => $site->id])
            ->assertCreated();

        $this->assertFalse($svc->activeForUser($this->nurse->fresh())->contains('id', $def->id));
    }

    public function test_cms_mandatory_definition_cannot_be_disabled_per_site(): void
    {
        $site = Site::factory()->create(['tenant_id' => $this->tenant->id]);
        $hipaa = CredentialDefinition::where('code', 'hipaa_annual_training')->firstOrFail();

        $this->actingAs($this->exec)
            ->postJson("/executive/credential-definitions/{$hipaa->id}/site-overrides", ['site_id' => $site->id])
            ->assertStatus(422);
    }

    public function test_doc_required_definition_rejects_submission_without_document(): void
    {
        $hipaa = CredentialDefinition::where('code', 'hipaa_annual_training')->firstOrFail();
        // hipaa is seeded with default_doc_required=true.
        $this->assertTrue((bool) $hipaa->default_doc_required);

        $resp = $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_definition_id' => $hipaa->id,
                'credential_type' => 'training',
                'title'           => 'HIPAA 2026',
                'expires_at'      => now()->addYear()->toDateString(),
                // intentionally no document
            ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['document']);
    }

    public function test_requires_psv_definition_rejects_self_attestation(): void
    {
        // Create a non-mandatory def with requires_psv=true and target primary_care
        $def = CredentialDefinition::create([
            'tenant_id' => $this->tenant->id,
            'code' => 'test_psv_required',
            'title' => 'Test PSV License',
            'credential_type' => 'license',
            'requires_psv' => true,
            'default_doc_required' => false,
        ]);

        $resp = $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_definition_id' => $def->id,
                'credential_type' => 'license',
                'title' => 'Test PSV License',
                'verification_source' => 'self_attestation',  // not allowed
            ]);
        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['verification_source']);

        // state_board IS allowed
        $resp = $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_definition_id' => $def->id,
                'credential_type' => 'license',
                'title' => 'Test PSV License',
                'verification_source' => 'state_board',
            ]);
        $resp->assertCreated();
    }

    public function test_free_form_credentials_skip_definition_rules(): void
    {
        // No definition_id : doc_required + PSV checks should not apply.
        $resp = $this->actingAs($this->itAdmin)
            ->postJson("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_type' => 'other',
                'title' => 'Some custom thing',
            ]);
        $resp->assertCreated();
    }

    public function test_pdf_upload_persisted_to_disk_and_path_recorded(): void
    {
        Storage::fake('local');

        $resp = $this->actingAs($this->itAdmin)
            ->post("/it-admin/users/{$this->nurse->id}/credentials", [
                'credential_type' => 'training',
                'title'           => 'HIPAA 2026 Training Certificate',
                'expires_at'      => now()->addYear()->toDateString(),
                'document'        => UploadedFile::fake()->create('hipaa-cert.pdf', 100, 'application/pdf'),
            ]);

        $resp->assertCreated();
        $cred = StaffCredential::where('user_id', $this->nurse->id)->latest()->first();
        $this->assertNotNull($cred->document_path);
        $this->assertEquals('hipaa-cert.pdf', $cred->document_filename);
        Storage::disk('local')->assertExists($cred->document_path);
    }

    public function test_compliance_matrix_includes_all_mandatory_defs(): void
    {
        $svc = app(CredentialComplianceService::class);
        $matrix = $svc->matrixForTenant($this->tenant->id);

        $this->assertGreaterThanOrEqual(8, count($matrix['rows']));
        $this->assertContains('primary_care', $matrix['departments']);
    }

    public function test_my_credentials_self_service_shows_own_data_only(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'finance']);
        StaffCredential::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $other->id,
            'credential_type' => 'license',
            'title'     => 'Other Person License',
            'cms_status' => 'active',
        ]);
        StaffCredential::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->nurse->id,
            'credential_type' => 'license',
            'title'     => 'My RN License',
            'cms_status' => 'active',
        ]);

        $resp = $this->actingAs($this->nurse)->get('/my-credentials');
        $resp->assertSuccessful();
        $resp->assertSee('My RN License');
        $resp->assertDontSee('Other Person License');
    }

    public function test_my_credentials_renewal_creates_new_versioned_row(): void
    {
        Storage::fake('local');

        $cred = StaffCredential::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $this->nurse->id,
            'credential_type' => 'license',
            'title'     => 'RN License',
            'expires_at' => now()->addDays(20),
            'cms_status' => 'active',
        ]);

        $resp = $this->actingAs($this->nurse)
            ->post("/my-credentials/{$cred->id}/renewal", [
                'expires_at' => now()->addYears(2)->toDateString(),
                'document'   => UploadedFile::fake()->create('rn-renewal.pdf', 100, 'application/pdf'),
            ]);

        $resp->assertSuccessful();

        // V2 versioning : original row preserved as audit history with forward
        // link to the new pending row. New row is the tip of the chain.
        $cred->refresh();
        $this->assertNotNull($cred->replaced_by_credential_id, 'old credential should be marked as superseded');
        $this->assertEquals('active', $cred->cms_status, 'old row keeps its prior status as audit record');

        $newCred = StaffCredential::find($cred->replaced_by_credential_id);
        $this->assertNotNull($newCred);
        $this->assertEquals('pending', $newCred->cms_status);
        $this->assertEquals('self_attestation', $newCred->verification_source);
        $this->assertNull($newCred->verified_at);
        $this->assertNull($newCred->replaced_by_credential_id, 'new row is the tip of the chain');
    }

    public function test_my_credentials_renewal_rejected_for_other_users_credential(): void
    {
        $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'department' => 'finance']);
        $cred = StaffCredential::create([
            'tenant_id' => $this->tenant->id,
            'user_id'   => $other->id,
            'credential_type' => 'license',
            'title'     => 'Not Mine',
            'cms_status' => 'active',
        ]);

        $this->actingAs($this->nurse)
            ->post("/my-credentials/{$cred->id}/renewal", [
                'document' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_job_title_crud_executive_only(): void
    {
        // Executive can list
        $this->actingAs($this->exec)->getJson('/executive/job-titles')->assertOk();
        // Nurse cannot
        $this->actingAs($this->nurse)->getJson('/executive/job-titles')->assertForbidden();

        // Create
        $this->actingAs($this->exec)
            ->postJson('/executive/job-titles', ['code' => 'custom_role', 'label' => 'Custom Role'])
            ->assertCreated();
        $this->assertDatabaseHas('emr_job_titles', ['code' => 'custom_role']);
    }

    public function test_weekly_digest_creates_alert_when_problems_exist(): void
    {
        // Set up 3 missing-required (nurse has nothing on file already covers it)
        // and verify the digest job creates a single dept alert.
        $job = new WeeklyCredentialDigestJob();
        $job->handle(
            app(\App\Services\AlertService::class),
            app(\App\Services\NotificationPreferenceService::class),
            app(CredentialDefinitionService::class),
        );

        $alert = Alert::where('tenant_id', $this->tenant->id)
            ->where('alert_type', 'credential_weekly_digest')
            ->first();
        $this->assertNotNull($alert);
        $this->assertContains('it_admin', $alert->target_departments);
        $this->assertContains('qa_compliance', $alert->target_departments);
    }
}

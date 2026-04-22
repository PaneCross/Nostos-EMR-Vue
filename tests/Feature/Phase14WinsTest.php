<?php

// ─── Phase14WinsTest ──────────────────────────────────────────────────────────
// Phase 14 — Short-term wins batch B. Covers:
//   14.1 Four printable PDFs (facesheet / care_plan / medication_list / allergy_list)
//   14.2 Standalone appointment detail Inertia page
//   14.7 Global search across 6+ entity types
//
// 14.3 photo display already working (verified during discovery; no test added).
// 14.8 seed depth not tested here — data-shape smoke only.
// 14.4/14.5/14.6 intentionally deferred (see backlog_ui_audits.md).
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Grievance;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase14WinsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $doctor;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'P14']);
        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)->forSite($this->site->id)->create([
                'first_name' => 'Ada', 'last_name' => 'Lovelace',
            ]);
    }

    // ── 14.1 PDFs ───────────────────────────────────────────────────────────

    public function test_facesheet_pdf_returns_pdf_bytes(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->get("/participants/{$this->participant->id}/pdf/facesheet");
        $r->assertOk();
        $r->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $r->getContent());
    }

    public function test_medication_list_pdf_returns_pdf_bytes(): void
    {
        Medication::create([
            'participant_id' => $this->participant->id,
            'tenant_id' => $this->tenant->id,
            'drug_name' => 'Lisinopril',
            'dose' => '10', 'dose_unit' => 'mg', 'route' => 'oral',
            'frequency' => 'daily', 'is_prn' => false, 'status' => 'active',
            'prescribed_date' => now()->toDateString(),
            'start_date' => now()->toDateString(),
        ]);
        $this->actingAs($this->doctor);
        $r = $this->get("/participants/{$this->participant->id}/pdf/medication_list");
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_allergy_list_pdf_returns_pdf_bytes(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->get("/participants/{$this->participant->id}/pdf/allergy_list");
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_care_plan_pdf_returns_pdf_bytes_even_when_no_plan_on_file(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->get("/participants/{$this->participant->id}/pdf/care_plan");
        $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_unknown_kind_returns_404(): void
    {
        $this->actingAs($this->doctor);
        $this->get("/participants/{$this->participant->id}/pdf/bogus")->assertNotFound();
    }

    public function test_pdf_endpoint_writes_audit_log(): void
    {
        $this->actingAs($this->doctor);
        $this->get("/participants/{$this->participant->id}/pdf/facesheet")->assertOk();
        $this->assertDatabaseHas('shared_audit_logs', [
            'action'      => 'participant.pdf_generated',
            'resource_id' => $this->participant->id,
        ]);
    }

    public function test_pdf_endpoint_blocks_cross_tenant(): void
    {
        $other = Tenant::factory()->create();
        $outsider = User::factory()->create([
            'tenant_id' => $other->id, 'department' => 'primary_care',
            'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($outsider);
        $this->get("/participants/{$this->participant->id}/pdf/facesheet")->assertNotFound();
    }

    // ── 14.2 Appointment detail page ────────────────────────────────────────

    public function test_appointment_show_standalone_renders_inertia_page(): void
    {
        $appt = Appointment::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'appointment_type' => 'clinic_visit',
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(2),
            'scheduled_end'   => now()->addHours(3),
            'created_by_user_id' => $this->doctor->id,
        ]);

        $this->actingAs($this->doctor);
        $r = $this->get("/appointments/{$appt->id}");
        $r->assertOk();
        // Inertia renders either HTML (assertInertia in a scoped assertion) or HTML shell; simplest smoke
        $this->assertStringContainsString('Appointment', $r->getContent());
    }

    public function test_appointment_show_blocks_cross_tenant(): void
    {
        $other = Tenant::factory()->create();
        $outsiderSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'OUT']);
        $appt = Appointment::create([
            'tenant_id' => $other->id,
            'site_id' => $outsiderSite->id,
            'participant_id' => $this->participant->id,
            'appointment_type' => 'clinic_visit',
            'status' => 'scheduled',
            'scheduled_start' => now()->addHours(2),
            'scheduled_end'   => now()->addHours(3),
            'created_by_user_id' => $this->doctor->id,
        ]);

        $this->actingAs($this->doctor);
        $this->get("/appointments/{$appt->id}")->assertNotFound();
    }

    // ── 14.7 Global search ──────────────────────────────────────────────────

    public function test_global_search_returns_participants_by_name(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->getJson('/search?q=Lovelace');
        $r->assertOk();
        $this->assertArrayHasKey('participants', $r->json('groups'));
        $names = collect($r->json('groups.participants'))->pluck('label')->all();
        $this->assertNotEmpty($names);
        $this->assertStringContainsString('Lovelace', implode(' ', $names));
    }

    public function test_global_search_returns_grievances_by_description(): void
    {
        Grievance::create([
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'category' => 'quality_of_care',
            'priority' => 'standard', 'status' => 'open',
            'filed_at' => now()->subDays(3),
            'description' => 'Interpreter shortage reported by participant.',
            'filed_by_type' => 'participant', 'filed_by_name' => 'Ada',
            'received_by_user_id' => $this->doctor->id,
        ]);

        $this->actingAs($this->doctor);
        $r = $this->getJson('/search?q=Interpreter');
        $r->assertOk();
        $this->assertNotEmpty($r->json('groups.grievances'));
    }

    public function test_global_search_returns_appointments_via_participant_name(): void
    {
        Appointment::create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id,
            'participant_id' => $this->participant->id,
            'appointment_type' => 'therapy_pt',
            'status' => 'scheduled',
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'created_by_user_id' => $this->doctor->id,
        ]);

        $this->actingAs($this->doctor);
        $r = $this->getJson('/search?q=Lovelace');
        $r->assertOk();
        $this->assertNotEmpty($r->json('groups.appointments'));
    }

    public function test_global_search_kind_filter_limits_groups(): void
    {
        $this->actingAs($this->doctor);
        $r = $this->getJson('/search?q=Lovelace&kinds=participants');
        $r->assertOk();
        $groups = $r->json('groups');
        $this->assertArrayHasKey('participants', $groups);
        $this->assertArrayNotHasKey('grievances', $groups);
    }

    public function test_global_search_requires_min_2_chars(): void
    {
        $this->actingAs($this->doctor);
        $this->getJson('/search?q=a')->assertStatus(422);
    }

    public function test_global_search_writes_audit_log(): void
    {
        $this->actingAs($this->doctor);
        $this->getJson('/search?q=Lovelace')->assertOk();
        $this->assertDatabaseHas('shared_audit_logs', [
            'action' => 'global.search',
        ]);
    }
}

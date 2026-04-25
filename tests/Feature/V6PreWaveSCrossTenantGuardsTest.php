<?php

// ─── Phase V6 — Cross-tenant guard tests on pre-Wave-S endpoints ───────────
// Audit-10 M3: Wave T1 added cross-tenant tests for Wave S endpoints, but
// pre-Wave-S controllers (Participant, Appointment, Medication, ClinicalNote,
// Iadl) had no regression tests for the paste-the-URL attack. This file
// closes that gap with one explicit cross-tenant test per controller.
// ─────────────────────────────────────────────────────────────────────────────
namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Medication;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class V6PreWaveSCrossTenantGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithUser(string $prefix, string $dept = 'primary_care'): array
    {
        $t = Tenant::factory()->create();
        $site = Site::factory()->create([
            'tenant_id' => $t->id,
            'mrn_prefix' => strtoupper(Str::random(3)),
        ]);
        $u = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $site->id,
            'department' => $dept, 'role' => 'admin', 'is_active' => true,
        ]);
        return [$t, $site, $u];
    }

    public function test_participant_show_cross_tenant_is_403_or_404(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithUser('PA');
        [$tB, $siteB, $uB] = $this->tenantWithUser('PB');
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();

        $r = $this->actingAs($uA)->get("/participants/{$pB->id}");
        $this->assertContains($r->getStatusCode(), [403, 404],
            'Participant show must reject cross-tenant access (403 abort_if or 404 model-bind miss).');
    }

    public function test_appointment_store_cross_tenant_participant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithUser('AA');
        [$tB, $siteB, $uB] = $this->tenantWithUser('AB');
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();

        // Tenant A user tries to book an appointment for tenant B's participant
        // with otherwise-valid data so the cross-tenant guard fires (not validation).
        $r = $this->actingAs($uA)->postJson("/participants/{$pB->id}/appointments", [
            'appointment_type'  => 'clinic_visit',
            'scheduled_start'   => now()->addDays(2)->toIso8601String(),
            'scheduled_end'     => now()->addDays(2)->addHour()->toIso8601String(),
        ]);
        $this->assertContains($r->getStatusCode(), [403, 404],
            "Expected 403/404; got {$r->getStatusCode()} body: " . $r->getContent());
        // Verify nothing was actually created in tenant B.
        $this->assertEquals(0, Appointment::where('participant_id', $pB->id)->count());
    }

    public function test_medication_store_cross_tenant_participant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithUser('MA');
        [$tB, $siteB, $uB] = $this->tenantWithUser('MB');
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();

        $r = $this->actingAs($uA)->postJson("/participants/{$pB->id}/medications", [
            'drug_name' => 'Lisinopril', 'dose' => 10, 'dose_unit' => 'mg',
            'route' => 'oral', 'frequency' => 'daily',
            'start_date' => now()->toDateString(), 'is_prn' => false,
        ]);
        $this->assertContains($r->getStatusCode(), [403, 404]);
    }

    public function test_clinical_notes_store_cross_tenant_participant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithUser('CA');
        [$tB, $siteB, $uB] = $this->tenantWithUser('CB');
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();

        $r = $this->actingAs($uA)->postJson("/participants/{$pB->id}/notes", [
            'note_type' => 'soap', 'visit_date' => now()->subDay()->toDateString(),
            'visit_type' => 'in_center', 'department' => 'primary_care',
            'subjective' => 'S', 'objective' => 'O', 'assessment' => 'A', 'plan' => 'P',
        ]);
        $this->assertContains($r->getStatusCode(), [403, 404],
            "Expected 403/404; got {$r->getStatusCode()} body: " . $r->getContent());
        $this->assertEquals(0, \App\Models\ClinicalNote::where('participant_id', $pB->id)->count());
    }

    public function test_iadl_store_cross_tenant_participant_is_403(): void
    {
        [$tA, $siteA, $uA] = $this->tenantWithUser('IA');
        [$tB, $siteB, $uB] = $this->tenantWithUser('IB');
        $pB = Participant::factory()->enrolled()->forTenant($tB->id)->forSite($siteB->id)->create();

        $r = $this->actingAs($uA)->postJson("/participants/{$pB->id}/iadl", [
            'telephone' => 1, 'shopping' => 1, 'food_preparation' => 1,
            'housekeeping' => 1, 'laundry' => 1, 'transportation' => 1,
            'medications' => 1, 'finances' => 1,
        ]);
        $this->assertContains($r->getStatusCode(), [403, 404]);
    }
}

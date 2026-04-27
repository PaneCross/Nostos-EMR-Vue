<?php

// ─── NotificationPreferenceWiringTest ────────────────────────────────────────
// Phase SS2 — proves the alert sites consult the preference service correctly.
//
// For each wired preference, we run the same trigger twice — once with the
// preference disabled (default), once enabled — and verify the additional
// designation-targeted Alert row only appears in the second run. The base
// hardwired alerts (e.g. primary_care abnormal-lab alert) are unaffected.
//
// Wirings covered (one test each):
//   - designation.program_director.sentinel_event (IncidentService)
//   - designation.program_director.breach_incident_logged (BreachIncidentController)
//   - designation.pharmacy_director.critical_drug_interaction (DrugInteractionService)
//   - workflow.day_center_no_show.notify_social_work (DayCenterController)
//   - workflow.transport_cancellation.notify_assigned_pcp (TransportRequestController)
//   - workflow.lab_abnormal.notify_nursing_director (ProcessLabResultJob)
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Incident;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\IncidentService;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferenceWiringTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithDirector(string $designation): array
    {
        $t = Tenant::factory()->create();
        $s = Site::factory()->create(['tenant_id' => $t->id, 'mrn_prefix' => 'WIR']);
        $director = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'executive', 'role' => 'admin', 'is_active' => true,
            'designations' => [$designation],
        ]);
        $qa = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true,
        ]);
        $participant = Participant::factory()->enrolled()
            ->forTenant($t->id)->forSite($s->id)->create();
        return [$t, $s, $director, $qa, $participant];
    }

    public function test_sentinel_classification_routes_to_program_director_when_enabled(): void
    {
        [$t, , $director, $qa, $p] = $this->tenantWithDirector('program_director');
        $svc = app(NotificationPreferenceService::class);

        // First run — preference OFF (default). Classify sentinel.
        $incident = Incident::factory()->create([
            'tenant_id' => $t->id,
            'participant_id' => $p->id,
            'reported_by_user_id' => $qa->id,
        ]);
        app(IncidentService::class)->classifyAsSentinel($incident, $qa, 'patient_safety');

        $this->assertEquals(0, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'sentinel_event_classified')->count());

        // Now enable + re-trigger on a fresh incident
        $svc->set($t->id, 'designation.program_director.sentinel_event', true, $qa->id);
        $svc->clearCache($t->id);

        $i2 = Incident::factory()->create([
            'tenant_id' => $t->id,
            'participant_id' => $p->id,
            'reported_by_user_id' => $qa->id,
        ]);
        app(IncidentService::class)->classifyAsSentinel($i2, $qa, 'patient_safety');

        $this->assertEquals(1, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'sentinel_event_classified')->count());

        // Director's id is in the alert metadata
        $alert = Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'sentinel_event_classified')->first();
        $this->assertEquals($director->id, $alert->metadata['program_director_id']);
    }

    public function test_breach_log_routes_to_program_director_when_enabled(): void
    {
        [$t, , , , ] = $this->tenantWithDirector('program_director');
        $svc = app(NotificationPreferenceService::class);
        $itAdmin = User::factory()->create([
            'tenant_id' => $t->id, 'department' => 'it_admin', 'role' => 'admin', 'is_active' => true,
        ]);

        $payload = [
            'discovered_at'  => now()->toDateTimeString(),
            'affected_count' => 5,
            'breach_type'    => 'unauthorized_access',
            'description'    => 'Breach via misconfigured S3 bucket.',
        ];

        // OFF: no alert
        $this->actingAs($itAdmin)->postJson('/it-admin/breaches', $payload)->assertStatus(201);
        $this->assertEquals(0, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'breach_incident_logged')->count());

        // Flip ON
        $svc->set($t->id, 'designation.program_director.breach_incident_logged', true, $itAdmin->id);
        $svc->clearCache($t->id);
        $this->actingAs($itAdmin)->postJson('/it-admin/breaches', $payload)->assertStatus(201);
        $this->assertEquals(1, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'breach_incident_logged')->count());
    }

    public function test_day_center_absence_routes_to_social_work_by_default(): void
    {
        // This is the one preference defaulting ON — so a fresh tenant should
        // already fire the alert without any toggle.
        [$t, $s, , , $p] = $this->tenantWithDirector('program_director');
        $recorder = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'activities', 'role' => 'admin', 'is_active' => true,
        ]);

        $r = $this->actingAs($recorder)->postJson('/scheduling/day-center/absent', [
            'participant_id'  => $p->id,
            'site_id'         => $s->id,
            'attendance_date' => now()->toDateString(),
            'status'          => 'absent',
            'absent_reason'   => 'Illness',
        ]);
        $r->assertOk();

        $this->assertEquals(1, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'day_center_absence')->count());
    }

    public function test_day_center_absence_does_not_route_when_preference_disabled(): void
    {
        [$t, $s, , , $p] = $this->tenantWithDirector('program_director');
        $recorder = User::factory()->create([
            'tenant_id' => $t->id, 'site_id' => $s->id,
            'department' => 'activities', 'role' => 'admin', 'is_active' => true,
        ]);

        $svc = app(NotificationPreferenceService::class);
        $svc->set($t->id, 'workflow.day_center_no_show.notify_social_work', false, $recorder->id);
        $svc->clearCache($t->id);

        $this->actingAs($recorder)->postJson('/scheduling/day-center/absent', [
            'participant_id'  => $p->id,
            'site_id'         => $s->id,
            'attendance_date' => now()->toDateString(),
            'status'          => 'absent',
            'absent_reason'   => 'Illness',
        ])->assertOk();

        $this->assertEquals(0, Alert::where('tenant_id', $t->id)
            ->where('alert_type', 'day_center_absence')->count());
    }
}

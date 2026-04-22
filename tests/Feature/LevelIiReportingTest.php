<?php

// ─── LevelIiReportingTest ─────────────────────────────────────────────────────
// Phase 3 (MVP roadmap) — CMS PACE Level I / Level II quarterly reporting.
// Covers per-indicator aggregators + end-to-end quarterly generation + auth.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Models\Immunization;
use App\Models\Incident;
use App\Models\LevelIiSubmission;
use App\Models\Participant;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WoundRecord;
use App\Services\LevelIiReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LevelIiReportingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qaUser;
    private Carbon $qStart;
    private Carbon $qEnd;
    private Carbon $beforePeriod;  // a date guaranteed outside the quarter

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'L2R']);
        $this->qaUser = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);

        // Q2 2026 (Apr 1 – Jun 30) for predictable fixtures.
        $this->qStart = Carbon::createFromDate(2026, 4, 1)->startOfDay();
        $this->qEnd   = Carbon::createFromDate(2026, 6, 30)->endOfDay();
        $this->beforePeriod = Carbon::createFromDate(2026, 1, 15)->startOfDay();
    }

    private function enrolledParticipant(array $overrides = []): Participant
    {
        return Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create(array_merge([
                'enrollment_date' => Carbon::createFromDate(2025, 1, 1)->toDateString(),
            ], $overrides));
    }

    private function incident(string $type, Carbon $at, array $extra = []): Incident
    {
        $p = $this->enrolledParticipant();
        return Incident::create(array_merge([
            'tenant_id'           => $this->tenant->id,
            'participant_id'      => $p->id,
            'incident_type'       => $type,
            'occurred_at'         => $at,
            'location_of_incident'=> 'Day Center',
            'reported_by_user_id' => $this->qaUser->id,
            'reported_at'         => $at,
            'description'         => 'Test event',
            'immediate_actions_taken' => 'Documented.',
            'injuries_sustained'  => false,
            'rca_required'        => false,
            'cms_reportable'      => false,
            'cms_notification_required' => false,
            'status'              => 'closed',
        ], $extra));
    }

    // ── Aggregator tests ─────────────────────────────────────────────────────

    public function test_count_deaths_respects_period_and_type(): void
    {
        Participant::factory()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'enrollment_status'  => 'disenrolled',
            'disenrollment_type' => 'death',
            'disenrollment_reason' => 'death',
            'disenrollment_date' => $this->qStart->copy()->addDays(10)->toDateString(),
        ]);
        // Outside period
        Participant::factory()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'enrollment_status'  => 'disenrolled',
            'disenrollment_type' => 'death',
            'disenrollment_reason' => 'death',
            'disenrollment_date' => $this->beforePeriod->toDateString(),
        ]);
        // Different type
        Participant::factory()->forTenant($this->tenant->id)->forSite($this->site->id)->create([
            'enrollment_status'  => 'disenrolled',
            'disenrollment_type' => 'voluntary',
            'disenrollment_reason' => 'voluntary_other',
            'disenrollment_date' => $this->qStart->copy()->addDays(5)->toDateString(),
        ]);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(1, $svc->countDeaths($this->tenant->id, $this->qStart, $this->qEnd));
    }

    public function test_falls_and_falls_with_injury_are_separated(): void
    {
        $this->incident('fall', $this->qStart->copy()->addDays(5));
        $this->incident('fall', $this->qStart->copy()->addDays(10), ['injuries_sustained' => true, 'injury_description' => 'Sprain']);
        $this->incident('fall', $this->qStart->copy()->addDays(15), ['injuries_sustained' => true]);
        // Outside period
        $this->incident('fall', $this->beforePeriod, ['injuries_sustained' => true]);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(3, $svc->countIncidents($this->tenant->id, 'fall', $this->qStart, $this->qEnd));
        $this->assertEquals(2, $svc->countFallsWithInjury($this->tenant->id, $this->qStart, $this->qEnd));
    }

    public function test_pressure_injury_stage_aggregations(): void
    {
        $p = $this->enrolledParticipant();

        WoundRecord::create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'participant_id' => $p->id,
            'documented_by_user_id' => $this->qaUser->id,
            'wound_type' => 'pressure_injury', 'location' => 'sacrum',
            'pressure_injury_stage' => 'stage_1',
            'first_identified_date' => $this->qStart->copy()->addDays(3)->toDateString(),
            'status' => 'open',
        ]);
        WoundRecord::create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'participant_id' => $p->id,
            'documented_by_user_id' => $this->qaUser->id,
            'wound_type' => 'pressure_injury', 'location' => 'heel',
            'pressure_injury_stage' => 'stage_2',
            'first_identified_date' => $this->qStart->copy()->addDays(10)->toDateString(),
            'status' => 'open',
        ]);
        WoundRecord::create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'participant_id' => $p->id,
            'documented_by_user_id' => $this->qaUser->id,
            'wound_type' => 'pressure_injury', 'location' => 'coccyx',
            'pressure_injury_stage' => 'stage_3',
            'first_identified_date' => $this->qStart->copy()->addDays(20)->toDateString(),
            'status' => 'open',
        ]);
        // Outside period
        WoundRecord::create([
            'tenant_id' => $this->tenant->id, 'site_id' => $this->site->id, 'participant_id' => $p->id,
            'documented_by_user_id' => $this->qaUser->id,
            'wound_type' => 'pressure_injury', 'location' => 'elbow',
            'pressure_injury_stage' => 'stage_4',
            'first_identified_date' => $this->beforePeriod->toDateString(),
            'status' => 'open',
        ]);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(3, $svc->countPressureInjuriesNew($this->tenant->id, $this->qStart, $this->qEnd));
        $this->assertEquals(2, $svc->countPressureInjuriesStage2p($this->tenant->id, $this->qStart, $this->qEnd));
        $this->assertEquals(1, $svc->countPressureInjuriesCritical($this->tenant->id, $this->qStart, $this->qEnd));
    }

    public function test_immunization_counts_and_rates(): void
    {
        // 5 enrolled ppts with flu shots in period
        for ($i = 0; $i < 5; $i++) {
            $p = $this->enrolledParticipant();
            Immunization::create([
                'tenant_id' => $this->tenant->id, 'participant_id' => $p->id,
                'vaccine_type' => 'influenza', 'vaccine_name' => 'Flu Quad',
                'administered_date' => $this->qStart->copy()->addDays(5 + $i)->toDateString(),
            ]);
        }
        // 2 pneumococcal
        for ($i = 0; $i < 2; $i++) {
            $p = $this->enrolledParticipant();
            Immunization::create([
                'tenant_id' => $this->tenant->id, 'participant_id' => $p->id,
                'vaccine_type' => 'pneumococcal_ppsv23', 'vaccine_name' => 'PPSV23',
                'administered_date' => $this->qStart->copy()->addDays(5 + $i)->toDateString(),
            ]);
        }
        // outside period — should not count
        $p = $this->enrolledParticipant();
        Immunization::create([
            'tenant_id' => $this->tenant->id, 'participant_id' => $p->id,
            'vaccine_type' => 'influenza', 'vaccine_name' => 'Flu Quad',
            'administered_date' => $this->beforePeriod->toDateString(),
        ]);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(5, $svc->countImmunizations($this->tenant->id, 'influenza', $this->qStart, $this->qEnd));
        $this->assertEquals(2, $svc->countPneumoImmunizations($this->tenant->id, $this->qStart, $this->qEnd));

        // Rate = given / avg census. 8 enrolled from the loops above.
        $census = $svc->averageDailyEnrolledCensus($this->tenant->id, $this->qStart, $this->qEnd);
        $this->assertGreaterThan(0, $census);
        $this->assertNotNull($svc->vaccinationRate($this->tenant->id, 'influenza', $this->qStart, $this->qEnd, $census));
    }

    public function test_burns_are_detected_by_narrative(): void
    {
        $this->incident('injury', $this->qStart->copy()->addDays(5), ['description' => 'Minor burn on forearm.']);
        $this->incident('injury', $this->qStart->copy()->addDays(6), ['injury_description' => 'Thermal BURN to hand.']);
        $this->incident('injury', $this->qStart->copy()->addDays(7), ['description' => 'Sprain to ankle.']);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(2, $svc->countBurns($this->tenant->id, $this->qStart, $this->qEnd));
    }

    public function test_hospitalizations_and_er_visits_count(): void
    {
        $this->incident('hospitalization', $this->qStart->copy()->addDays(5));
        $this->incident('hospitalization', $this->qStart->copy()->addDays(12));
        $this->incident('er_visit', $this->qStart->copy()->addDays(7));

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(2, $svc->countIncidents($this->tenant->id, 'hospitalization', $this->qStart, $this->qEnd));
        $this->assertEquals(1, $svc->countIncidents($this->tenant->id, 'er_visit', $this->qStart, $this->qEnd));
    }

    public function test_infectious_disease_counts(): void
    {
        $this->incident('infection', $this->qStart->copy()->addDays(5));
        $this->incident('infection', $this->qStart->copy()->addDays(12));

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(2, $svc->countIncidents($this->tenant->id, 'infection', $this->qStart, $this->qEnd));
    }

    public function test_tenant_isolation_on_aggregators(): void
    {
        $this->incident('fall', $this->qStart->copy()->addDays(5));

        $other = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $other->id, 'mrn_prefix' => 'OTH']);
        $op = Participant::factory()->enrolled()->forTenant($other->id)->forSite($otherSite->id)->create();
        Incident::create([
            'tenant_id' => $other->id, 'participant_id' => $op->id,
            'incident_type' => 'fall', 'occurred_at' => $this->qStart->copy()->addDays(7),
            'location_of_incident' => 'Other', 'reported_by_user_id' => $this->qaUser->id,
            'reported_at' => $this->qStart, 'description' => 'other tenant',
            'immediate_actions_taken' => 'x', 'injuries_sustained' => false,
            'rca_required' => false, 'cms_reportable' => false,
            'cms_notification_required' => false, 'status' => 'closed',
        ]);

        $svc = app(LevelIiReportingService::class);
        $this->assertEquals(1, $svc->countIncidents($this->tenant->id, 'fall', $this->qStart, $this->qEnd));
    }

    // ── End-to-end generation ────────────────────────────────────────────────

    public function test_generate_produces_submission_snapshot_and_csv(): void
    {
        $this->incident('fall', $this->qStart->copy()->addDays(5), ['injuries_sustained' => true]);
        $this->incident('hospitalization', $this->qStart->copy()->addDays(10));

        $svc = app(LevelIiReportingService::class);
        $submission = $svc->generate($this->tenant, 2026, 2, $this->qaUser);

        $this->assertEquals(2026, $submission->year);
        $this->assertEquals(2, $submission->quarter);
        $this->assertNotNull($submission->csv_path);
        $this->assertGreaterThan(0, $submission->csv_size_bytes);
        $this->assertEquals(1, $submission->indicators_snapshot['falls_total']);
        $this->assertEquals(1, $submission->indicators_snapshot['falls_with_injury']);
        $this->assertEquals(1, $submission->indicators_snapshot['hospital_admissions']);
    }

    public function test_regenerate_preserves_cms_submitted_stamp(): void
    {
        $svc = app(LevelIiReportingService::class);
        $s = $svc->generate($this->tenant, 2026, 2, $this->qaUser);
        $s = $svc->markCmsSubmitted($s, $this->qaUser, 'HPMS conf #ABC123');
        $this->assertNotNull($s->marked_cms_submitted_at);

        $s2 = $svc->generate($this->tenant, 2026, 2, $this->qaUser);
        $this->assertEquals($s->id, $s2->id);
        $this->assertNotNull($s2->marked_cms_submitted_at, 'Submission stamp should persist across regenerations.');
        $this->assertEquals('HPMS conf #ABC123', $s2->marked_cms_submitted_notes);
    }

    public function test_endpoint_generates_marks_downloads(): void
    {
        $this->actingAs($this->qaUser)
            ->postJson('/compliance/level-ii-reporting', ['year' => 2026, 'quarter' => 2])
            ->assertStatus(201)
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('quarter', 2);

        $sub = LevelIiSubmission::where('tenant_id', $this->tenant->id)->firstOrFail();

        $this->actingAs($this->qaUser)
            ->postJson("/compliance/level-ii-reporting/{$sub->id}/mark-submitted", ['notes' => 'Portal confirmed.'])
            ->assertOk()
            ->assertJsonPath('marked_cms_submitted_notes', 'Portal confirmed.');

        $this->actingAs($this->qaUser)
            ->get("/compliance/level-ii-reporting/{$sub->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_non_qa_finance_user_cannot_access(): void
    {
        $randomUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'department' => 'activities',
            'role' => 'standard',
            'is_active' => true,
        ]);

        $this->actingAs($randomUser)
            ->postJson('/compliance/level-ii-reporting', ['year' => 2026, 'quarter' => 2])
            ->assertStatus(403);
    }

    public function test_invalid_quarter_rejected(): void
    {
        $this->actingAs($this->qaUser)
            ->postJson('/compliance/level-ii-reporting', ['year' => 2026, 'quarter' => 7])
            ->assertStatus(422);
    }
}

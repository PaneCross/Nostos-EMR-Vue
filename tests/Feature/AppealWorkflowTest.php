<?php

// ─── AppealWorkflowTest ───────────────────────────────────────────────────────
// End-to-end coverage of the §460.122 denial + appeal workflow.
//
// Happy path: deny SDR → denial notice PDF → file appeal → acknowledge →
// begin review → decide upheld → request external review → close.
//
// Negative paths: invalid transitions, tenant isolation, appeal window expiry.
// ─────────────────────────────────────────────────────────────────────────────

namespace Tests\Feature;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Appeal;
use App\Models\AppealEvent;
use App\Models\Participant;
use App\Models\Sdr;
use App\Models\ServiceDenialNotice;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppealService;
use App\Services\ServiceDenialNoticeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppealWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Site $site;
    private User $qaAdmin;
    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->site   = Site::factory()->create(['tenant_id' => $this->tenant->id, 'mrn_prefix' => 'APL']);
        $this->qaAdmin = User::factory()->create([
            'tenant_id'  => $this->tenant->id,
            'site_id'    => $this->site->id,
            'department' => 'qa_compliance',
            'role'       => 'admin',
            'is_active'  => true,
        ]);
        $this->participant = Participant::factory()->enrolled()
            ->forTenant($this->tenant->id)
            ->forSite($this->site->id)
            ->create();
    }

    // ── Denial notice ─────────────────────────────────────────────────────────

    public function test_denying_an_sdr_issues_a_denial_notice_and_generates_pdf(): void
    {
        $sdr = $this->sdr();

        /** @var ServiceDenialNoticeService $svc */
        $svc = app(ServiceDenialNoticeService::class);
        $notice = $svc->issueForSdr($sdr, 'NOT_MEDICALLY_NECESSARY', 'Not consistent with current care plan.', $this->qaAdmin);

        $this->assertEquals('denied', $sdr->fresh()->status);
        $this->assertEquals('NOT_MEDICALLY_NECESSARY', $notice->reason_code);
        $this->assertEquals($this->qaAdmin->id, $notice->issued_by_user_id);
        $this->assertNotNull($notice->pdf_document_id, 'PDF document must be attached.');
        $this->assertTrue($notice->appeal_deadline_at->isAfter(now()->addDays(29)));
    }

    public function test_denial_notice_http_endpoint_requires_qa_enrollment_or_it_admin(): void
    {
        $randomUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'site_id'   => $this->site->id,
            'department' => 'activities',
            'role'      => 'standard',
            'is_active' => true,
        ]);
        $sdr = $this->sdr();

        $this->actingAs($randomUser)
            ->postJson("/sdrs/{$sdr->id}/deny", [
                'reason_code'      => 'XYZ',
                'reason_narrative' => 'n',
            ])->assertStatus(403);
    }

    public function test_denial_notice_endpoint_creates_notice_and_transitions_sdr(): void
    {
        $sdr = $this->sdr();

        $response = $this->actingAs($this->qaAdmin)
            ->postJson("/sdrs/{$sdr->id}/deny", [
                'reason_code'      => 'NOT_MEDICALLY_NECESSARY',
                'reason_narrative' => 'After IDT review, this service is not clinically indicated at this time.',
            ])
            ->assertStatus(201);

        $response->assertJsonPath('sdr.status', 'denied');
        $this->assertDatabaseCount('emr_service_denial_notices', 1);
        $this->assertEquals('denied', $sdr->fresh()->status);
    }

    // ── Appeal filing + clock ─────────────────────────────────────────────────

    public function test_filing_a_standard_appeal_sets_30_day_decision_due(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);

        Carbon::setTestNow('2026-05-01 10:00:00');
        $appeal = $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, 'wants service', false, $this->qaAdmin);

        $this->assertEquals(Appeal::STATUS_RECEIVED, $appeal->status);
        $this->assertEquals('2026-05-31 10:00:00', $appeal->internal_decision_due_at->format('Y-m-d H:i:s'));
    }

    public function test_filing_an_expedited_appeal_sets_72_hour_decision_due(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);

        Carbon::setTestNow('2026-05-01 10:00:00');
        $appeal = $svc->file($notice, Appeal::TYPE_EXPEDITED, 'representative', 'Jane Doe (daughter)', 'risk of harm', true, $this->qaAdmin);

        $this->assertEquals(Appeal::STATUS_RECEIVED, $appeal->status);
        $this->assertEquals('2026-05-04 10:00:00', $appeal->internal_decision_due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($appeal->continuation_of_benefits);
    }

    public function test_cannot_file_appeal_after_30_day_window_has_closed(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());

        Carbon::setTestNow($notice->appeal_deadline_at->copy()->addHours(1));

        $this->actingAs($this->qaAdmin)
            ->postJson('/appeals', [
                'service_denial_notice_id' => $notice->id,
                'type'                     => Appeal::TYPE_STANDARD,
                'filed_by'                 => 'participant',
                'continuation_of_benefits' => false,
            ])
            ->assertStatus(422);
    }

    // ── State machine ─────────────────────────────────────────────────────────

    public function test_full_happy_path_received_to_closed(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);

        $appeal = $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, false, $this->qaAdmin);
        $appeal = $svc->acknowledge($appeal, $this->qaAdmin);
        $this->assertEquals(Appeal::STATUS_ACKNOWLEDGED, $appeal->status);
        $this->assertNotNull($appeal->acknowledgment_pdf_document_id, 'Acknowledgment PDF should be attached.');

        $appeal = $svc->beginReview($appeal, $this->qaAdmin);
        $this->assertEquals(Appeal::STATUS_UNDER_REVIEW, $appeal->status);

        $appeal = $svc->decide($appeal, Appeal::STATUS_DECIDED_UPHELD, 'Service not clinically indicated per current assessment.', $this->qaAdmin);
        $this->assertEquals(Appeal::STATUS_DECIDED_UPHELD, $appeal->status);
        $this->assertNotNull($appeal->decision_pdf_document_id);
        $this->assertEquals($this->qaAdmin->id, $appeal->internal_decision_by_user_id);

        $appeal = $svc->requestExternalReview($appeal, $this->qaAdmin, 'Participant requests external review.');
        $this->assertEquals(Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED, $appeal->status);
        $this->assertEquals('pending', $appeal->external_review_outcome);

        $appeal = $svc->close($appeal, $this->qaAdmin, 'External review pending at state agency.');
        $this->assertEquals(Appeal::STATUS_CLOSED, $appeal->status);

        // Each action should produce an event
        $this->assertGreaterThanOrEqual(5, $appeal->events()->count());
    }

    public function test_invalid_transition_throws(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);
        $appeal = $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, false, $this->qaAdmin);

        $this->expectException(InvalidStateTransitionException::class);
        // Can't decide from 'received' — must go through acknowledged + under_review
        $svc->decide($appeal, Appeal::STATUS_DECIDED_UPHELD, 'x', $this->qaAdmin);
    }

    // ── Continuation of benefits ──────────────────────────────────────────────

    public function test_continuation_of_benefits_flag_is_persisted_and_surfaced(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);
        $appeal = $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, true, $this->qaAdmin);

        $this->assertTrue($appeal->fresh()->continuation_of_benefits);

        // Surfaced in the index JSON
        $this->actingAs($this->qaAdmin)
            ->getJson('/appeals')
            ->assertOk()
            ->assertJsonPath('data.0.continuation_of_benefits', true);
    }

    // ── Events append-only ────────────────────────────────────────────────────

    public function test_appeal_events_cannot_be_updated_or_deleted(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        /** @var AppealService $svc */
        $svc = app(AppealService::class);
        $appeal = $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, false, $this->qaAdmin);

        $event = AppealEvent::where('appeal_id', $appeal->id)->firstOrFail();
        $origNarr = $event->narrative;

        // Attempt update (DB rule blocks); Eloquent won't error but DB won't persist
        $event->narrative = 'tampered';
        $event->save();
        $this->assertEquals($origNarr, $event->fresh()->narrative);

        // Attempt delete — rule blocks
        $event->delete();
        $this->assertNotNull(AppealEvent::find($event->id), 'Event should still exist after delete attempt.');
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_cannot_view_appeal_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherSite = Site::factory()->create(['tenant_id' => $otherTenant->id, 'mrn_prefix' => 'OTH']);
        $otherParticipant = Participant::factory()->enrolled()->forTenant($otherTenant->id)->forSite($otherSite->id)->create();
        $otherSdr = Sdr::factory()->create([
            'tenant_id' => $otherTenant->id,
            'participant_id' => $otherParticipant->id,
            'status' => 'submitted',
        ]);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id, 'department' => 'qa_compliance', 'role' => 'admin', 'is_active' => true]);
        $notice = (app(ServiceDenialNoticeService::class))->issueForSdr($otherSdr, 'X', 'Y', $otherUser);
        $appeal = (app(AppealService::class))->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, false, $otherUser);

        $this->actingAs($this->qaAdmin)
            ->getJson("/appeals/{$appeal->id}")
            ->assertStatus(403);
    }

    // ── PDF downloads ─────────────────────────────────────────────────────────

    public function test_denial_notice_pdf_download_returns_file(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        $this->actingAs($this->qaAdmin)
            ->get("/denial-notices/{$notice->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    // ── QA dashboard widget ──────────────────────────────────────────────────

    public function test_qa_dashboard_appeals_widget_returns_open_appeals(): void
    {
        $notice = $this->issueNoticeFor($this->sdr());
        $svc = app(AppealService::class);
        $svc->file($notice, Appeal::TYPE_STANDARD, 'participant', null, null, false, $this->qaAdmin);

        $this->actingAs($this->qaAdmin)
            ->getJson('/dashboards/qa-compliance/appeals')
            ->assertOk()
            ->assertJsonStructure([
                'appeals' => [['id', 'type', 'status', 'due_at', 'window_pct', 'overdue', 'href']],
                'open_count',
                'overdue_count',
            ])
            ->assertJsonPath('open_count', 1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function sdr(): Sdr
    {
        return Sdr::factory()->create([
            'tenant_id'       => $this->tenant->id,
            'participant_id'  => $this->participant->id,
            'status'          => 'submitted',
        ]);
    }

    private function issueNoticeFor(Sdr $sdr): ServiceDenialNotice
    {
        return app(ServiceDenialNoticeService::class)->issueForSdr(
            $sdr, 'NOT_MEDICALLY_NECESSARY', 'Narrative.', $this->qaAdmin
        );
    }
}

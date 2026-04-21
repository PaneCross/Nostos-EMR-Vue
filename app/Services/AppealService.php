<?php

// ─── AppealService ────────────────────────────────────────────────────────────
// Owns the appeal lifecycle per 42 CFR §460.122.
//   - Standard (30d) and Expedited (72h) clocks computed on file().
//   - Status transitions validated.
//   - Acknowledgment + decision letter PDFs generated.
//   - Continuation-of-benefits flag surfaced on the related SDR.
//   - Every transition writes an append-only AppealEvent row.
//
// Uses DomPDF (barryvdh/laravel-dompdf).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Exceptions\InvalidStateTransitionException;
use App\Models\Appeal;
use App\Models\AppealEvent;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Participant;
use App\Models\ServiceDenialNotice;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AppealService
{
    /**
     * Valid forward transitions. Keys are current status, values are allowed
     * next statuses. Any other transition throws InvalidStateTransitionException.
     */
    public const ALLOWED_TRANSITIONS = [
        Appeal::STATUS_RECEIVED => [
            Appeal::STATUS_ACKNOWLEDGED,
            Appeal::STATUS_WITHDRAWN,
        ],
        Appeal::STATUS_ACKNOWLEDGED => [
            Appeal::STATUS_UNDER_REVIEW,
            Appeal::STATUS_WITHDRAWN,
        ],
        Appeal::STATUS_UNDER_REVIEW => [
            Appeal::STATUS_DECIDED_UPHELD,
            Appeal::STATUS_DECIDED_OVERTURNED,
            Appeal::STATUS_DECIDED_PARTIALLY_OVERTURNED,
            Appeal::STATUS_WITHDRAWN,
        ],
        Appeal::STATUS_DECIDED_UPHELD => [
            Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED,
            Appeal::STATUS_CLOSED,
        ],
        Appeal::STATUS_DECIDED_OVERTURNED          => [Appeal::STATUS_CLOSED],
        Appeal::STATUS_DECIDED_PARTIALLY_OVERTURNED => [
            Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED,
            Appeal::STATUS_CLOSED,
        ],
        Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED => [Appeal::STATUS_CLOSED],
        Appeal::STATUS_WITHDRAWN                 => [Appeal::STATUS_CLOSED],
        Appeal::STATUS_CLOSED                    => [],
    ];

    /**
     * File a new appeal against a ServiceDenialNotice.
     * Computes the decision-due timestamp from the appeal type (standard/expedited).
     */
    public function file(
        ServiceDenialNotice $notice,
        string $type,
        string $filedBy,
        ?string $filedByName,
        ?string $filingReason,
        bool $continuationOfBenefits,
        User $actor,
    ): Appeal {
        if (! in_array($type, Appeal::TYPES, true)) {
            throw new \InvalidArgumentException("Invalid appeal type: {$type}");
        }
        if (! in_array($filedBy, Appeal::FILED_BY_VALUES, true)) {
            throw new \InvalidArgumentException("Invalid filed_by value: {$filedBy}");
        }

        return DB::transaction(function () use ($notice, $type, $filedBy, $filedByName, $filingReason, $continuationOfBenefits, $actor) {
            $filedAt = now();
            $dueAt = $type === Appeal::TYPE_EXPEDITED
                ? $filedAt->copy()->addHours(Appeal::EXPEDITED_DECISION_WINDOW_HOURS)
                : $filedAt->copy()->addDays(Appeal::STANDARD_DECISION_WINDOW_DAYS);

            $appeal = Appeal::create([
                'tenant_id'                => $notice->tenant_id,
                'participant_id'           => $notice->participant_id,
                'service_denial_notice_id' => $notice->id,
                'type'                     => $type,
                'status'                   => Appeal::STATUS_RECEIVED,
                'filed_by'                 => $filedBy,
                'filed_by_name'            => $filedByName,
                'filing_reason'            => $filingReason,
                'filed_at'                 => $filedAt,
                'internal_decision_due_at' => $dueAt,
                'continuation_of_benefits' => $continuationOfBenefits,
            ]);

            $this->logEvent($appeal, AppealEvent::EVENT_FILED, null, $appeal->status, $filingReason, $actor);

            AuditLog::record(
                action:       'appeal.filed',
                tenantId:     $appeal->tenant_id,
                userId:       $actor->id,
                resourceType: 'appeal',
                resourceId:   $appeal->id,
                description:  "Appeal filed (type={$type}, continuation={$this->boolLabel($continuationOfBenefits)}) against denial notice {$notice->id}",
            );

            return $appeal->fresh();
        });
    }

    /** Record acknowledgment + generate acknowledgment letter PDF. */
    public function acknowledge(Appeal $appeal, User $actor): Appeal
    {
        $this->assertTransition($appeal, Appeal::STATUS_ACKNOWLEDGED);

        return DB::transaction(function () use ($appeal, $actor) {
            $from = $appeal->status;
            $appeal->update(['status' => Appeal::STATUS_ACKNOWLEDGED]);

            $doc = $this->renderAcknowledgmentPdf($appeal, $actor);
            $appeal->update(['acknowledgment_pdf_document_id' => $doc->id]);

            $this->logEvent($appeal, AppealEvent::EVENT_ACKNOWLEDGED, $from, Appeal::STATUS_ACKNOWLEDGED, null, $actor, [
                'pdf_document_id' => $doc->id,
            ]);

            return $appeal->fresh();
        });
    }

    /** Move to under_review. */
    public function beginReview(Appeal $appeal, User $actor): Appeal
    {
        return $this->genericTransition($appeal, Appeal::STATUS_UNDER_REVIEW, $actor, null);
    }

    /**
     * Record the internal decision. Outcome must be one of the three decided statuses.
     * Generates decision letter PDF and attaches it.
     */
    public function decide(Appeal $appeal, string $outcome, string $narrative, User $actor): Appeal
    {
        if (! in_array($outcome, Appeal::DECIDED_STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid decision outcome: {$outcome}");
        }
        $this->assertTransition($appeal, $outcome);

        return DB::transaction(function () use ($appeal, $outcome, $narrative, $actor) {
            $from = $appeal->status;
            $appeal->update([
                'status'                       => $outcome,
                'internal_decision_at'         => now(),
                'internal_decision_by_user_id' => $actor->id,
                'decision_narrative'           => $narrative,
            ]);

            $doc = $this->renderDecisionPdf($appeal, $outcome);
            $appeal->update(['decision_pdf_document_id' => $doc->id]);

            $this->logEvent($appeal, AppealEvent::EVENT_DECIDED, $from, $outcome, $narrative, $actor, [
                'decision_pdf_document_id' => $doc->id,
            ]);

            AuditLog::record(
                action:       'appeal.decided',
                tenantId:     $appeal->tenant_id,
                userId:       $actor->id,
                resourceType: 'appeal',
                resourceId:   $appeal->id,
                description:  "Appeal decided: {$outcome}",
            );

            return $appeal->fresh();
        });
    }

    /** Participant (or rep) requests external / independent review after internal upheld. */
    public function requestExternalReview(Appeal $appeal, User $actor, ?string $narrative = null): Appeal
    {
        $this->assertTransition($appeal, Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED);

        return DB::transaction(function () use ($appeal, $actor, $narrative) {
            $from = $appeal->status;
            $appeal->update([
                'status'                       => Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED,
                'external_review_requested_at' => now(),
                'external_review_outcome'      => 'pending',
                'external_review_narrative'    => $narrative,
            ]);

            $this->logEvent($appeal, AppealEvent::EVENT_EXTERNAL_REVIEW_REQUEST, $from, Appeal::STATUS_EXTERNAL_REVIEW_REQUESTED, $narrative, $actor);

            return $appeal->fresh();
        });
    }

    /** Record the external review outcome. Does not change status; caller closes separately. */
    public function recordExternalOutcome(Appeal $appeal, string $outcome, ?string $narrative, User $actor): Appeal
    {
        if (! in_array($outcome, Appeal::EXTERNAL_REVIEW_OUTCOMES, true)) {
            throw new \InvalidArgumentException("Invalid external review outcome: {$outcome}");
        }

        return DB::transaction(function () use ($appeal, $outcome, $narrative, $actor) {
            $appeal->update([
                'external_review_outcome'    => $outcome,
                'external_review_outcome_at' => now(),
                'external_review_narrative'  => $narrative,
            ]);

            $this->logEvent($appeal, AppealEvent::EVENT_EXTERNAL_REVIEW_OUTCOME, null, null, $narrative, $actor, [
                'outcome' => $outcome,
            ]);

            return $appeal->fresh();
        });
    }

    /** Participant withdraws the appeal. */
    public function withdraw(Appeal $appeal, User $actor, ?string $narrative = null): Appeal
    {
        return $this->genericTransition($appeal, Appeal::STATUS_WITHDRAWN, $actor, $narrative, AppealEvent::EVENT_WITHDRAWN);
    }

    /** Close the appeal — no further actions after this. */
    public function close(Appeal $appeal, User $actor, ?string $narrative = null): Appeal
    {
        return $this->genericTransition($appeal, Appeal::STATUS_CLOSED, $actor, $narrative, AppealEvent::EVENT_CLOSED);
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function genericTransition(Appeal $appeal, string $toStatus, User $actor, ?string $narrative, string $eventType = AppealEvent::EVENT_STATUS_CHANGED): Appeal
    {
        $this->assertTransition($appeal, $toStatus);

        return DB::transaction(function () use ($appeal, $toStatus, $actor, $narrative, $eventType) {
            $from = $appeal->status;
            $appeal->update(['status' => $toStatus]);
            $this->logEvent($appeal, $eventType, $from, $toStatus, $narrative, $actor);
            return $appeal->fresh();
        });
    }

    private function assertTransition(Appeal $appeal, string $toStatus): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$appeal->status] ?? [];
        if (! in_array($toStatus, $allowed, true)) {
            // InvalidStateTransitionException constructor is (fromStatus, toStatus).
            throw new InvalidStateTransitionException($appeal->status, $toStatus);
        }
    }

    private function logEvent(
        Appeal $appeal,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $narrative,
        User $actor,
        array $metadata = [],
    ): void {
        AppealEvent::create([
            'tenant_id'     => $appeal->tenant_id,
            'appeal_id'     => $appeal->id,
            'event_type'    => $eventType,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'narrative'     => $narrative,
            'metadata'      => $metadata ?: null,
            'actor_user_id' => $actor->id,
            'occurred_at'   => now(),
        ]);
    }

    private function renderAcknowledgmentPdf(Appeal $appeal, User $actor): Document
    {
        $participant = Participant::findOrFail($appeal->participant_id);
        $html = view('pdf.appeal_acknowledgment', [
            'appeal'      => $appeal,
            'participant' => $participant,
        ])->render();

        $pdfBinary = Pdf::loadHTML($html)->output();

        $filename = sprintf(
            'appeals/%d/APPEAL-%d-ACK-%s.pdf',
            $participant->id,
            $appeal->id,
            now()->format('Ymd-His'),
        );

        Storage::disk('local')->put($filename, $pdfBinary);

        return Document::create([
            'tenant_id'           => $appeal->tenant_id,
            'participant_id'      => $participant->id,
            'uploaded_by_user_id' => $actor->id,
            'file_path'           => $filename,
            'file_name'           => basename($filename),
            'file_type'           => 'pdf',
            'file_size_bytes'     => strlen($pdfBinary),
            'document_category'   => 'legal',
            'description'         => "Appeal Acknowledgment — APPEAL-{$appeal->id}",
        ]);
    }

    private function renderDecisionPdf(Appeal $appeal, string $outcome): Document
    {
        $participant = Participant::findOrFail($appeal->participant_id);
        $appeal->loadMissing('decidedBy');

        // Map internal status back to simple outcome key for the template
        $templateOutcome = match ($outcome) {
            Appeal::STATUS_DECIDED_UPHELD               => 'upheld',
            Appeal::STATUS_DECIDED_OVERTURNED           => 'overturned',
            Appeal::STATUS_DECIDED_PARTIALLY_OVERTURNED => 'partially_overturned',
        };

        $html = view('pdf.appeal_decision', [
            'appeal'      => $appeal,
            'participant' => $participant,
            'outcome'     => $templateOutcome,
        ])->render();

        $pdfBinary = Pdf::loadHTML($html)->output();

        $filename = sprintf(
            'appeals/%d/APPEAL-%d-DECISION-%s.pdf',
            $participant->id,
            $appeal->id,
            now()->format('Ymd-His'),
        );

        Storage::disk('local')->put($filename, $pdfBinary);

        return Document::create([
            'tenant_id'           => $appeal->tenant_id,
            'participant_id'      => $participant->id,
            'uploaded_by_user_id' => $appeal->internal_decision_by_user_id,
            'file_path'           => $filename,
            'file_name'           => basename($filename),
            'file_type'           => 'pdf',
            'file_size_bytes'     => strlen($pdfBinary),
            'document_category'   => 'legal',
            'description'         => "Appeal Decision ({$templateOutcome}) — APPEAL-{$appeal->id}",
        ]);
    }

    private function boolLabel(bool $v): string { return $v ? 'true' : 'false'; }
}

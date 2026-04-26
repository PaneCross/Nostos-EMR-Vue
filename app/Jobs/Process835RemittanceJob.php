<?php

// ─── Process835RemittanceJob ───────────────────────────────────────────────────
//
// Parses an uploaded X12 835 ERA file and stores the adjudication results as
// RemittanceClaim and RemittanceAdjustment records. Automatically creates
// DenialRecord entries for any denied claims.
//
// Queue: 'remittance' (configured in Horizon)
// Tries:  3 (standard retry pattern per project convention)
//
// Processing steps:
//   1. Set batch status to 'processing'
//   2. Parse raw EDI content via Remittance835ParserService
//   3. Attempt claim-to-encounter matching (matchToClaims)
//   4. Insert RemittanceClaim rows + RemittanceAdjustment rows per CAS segment
//   5. Create DenialRecord for each denied claim
//   6. Update batch aggregate counts (claim_count, paid_count, denied_count)
//   7. Set batch status to 'processed'
//   8. Create finance department alert summarizing the payment
//
// On failure after 3 tries: batch status → 'error'

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\DenialRecord;
use App\Models\RemittanceAdjustment;
use App\Models\RemittanceBatch;
use App\Models\RemittanceClaim;
use App\Services\AlertService;
use App\Services\Remittance835ParserService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Process835RemittanceJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /** Retry up to 3 times on transient failures (DB timeout, etc.). */
    public int $tries = 3;

    /**
     * Phase Y4 (Audit-13 M4): cap at 10 min — 835 files can be MB-scale and
     * the parser runs inside a transaction (see line ~90). Without a timeout
     * Laravel's worker default (60s) can kill mid-transaction on large files.
     */
    public int $timeout = 600;

    /**
     * Jittered exponential backoff so 3 retries don't hammer a flaky DB or
     * remittance source in lock-step. Seconds: ~1m, ~3m, ~6m with jitter.
     */
    public function backoff(): array
    {
        return [60, 180, 360];
    }

    /**
     * @param int $remittanceBatchId ID of the RemittanceBatch to process
     */
    public function __construct(
        public readonly int $remittanceBatchId
    ) {
        $this->onQueue('remittance');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // handle()
    // ─────────────────────────────────────────────────────────────────────────

    public function handle(
        Remittance835ParserService $parserService,
        AlertService $alertService
    ): void {
        $batch = RemittanceBatch::find($this->remittanceBatchId);

        if ($batch === null) {
            Log::warning("[Process835RemittanceJob] Batch {$this->remittanceBatchId} not found.");
            return;
        }

        // Mark as processing so the UI shows an in-progress indicator
        $batch->update(['status' => 'processing']);

        try {
            // ── Step 1: Parse the raw EDI content ────────────────────────────
            $parsed = $parserService->parse($batch->edi_835_content);

            // ── Step 2: Match claims to encounter log records ─────────────────
            $claims = $parserService->matchToClaims(
                $parsed['claims'],
                $batch->tenant_id
            );

            // ── Step 3: Insert all claims + adjustments in a transaction ──────
            $claimCount      = 0;
            $paidCount       = 0;
            $deniedCount     = 0;
            $adjustmentCount = 0;

            DB::transaction(function () use (
                $batch,
                $claims,
                $parserService,
                &$claimCount,
                &$paidCount,
                &$deniedCount,
                &$adjustmentCount
            ) {
                foreach ($claims as $claimData) {
                    // Create the RemittanceClaim record
                    $claim = RemittanceClaim::create([
                        'remittance_batch_id'    => $batch->id,
                        'tenant_id'              => $batch->tenant_id,
                        'edi_batch_id'           => $claimData['edi_batch_id'] ?? null,
                        'encounter_log_id'       => $claimData['encounter_log_id'] ?? null,
                        'patient_control_number' => $claimData['patient_control_number'],
                        'claim_status'           => $claimData['claim_status'],
                        'submitted_amount'       => $claimData['submitted_amount'],
                        'allowed_amount'         => $claimData['allowed_amount'],
                        'paid_amount'            => $claimData['paid_amount'],
                        'patient_responsibility' => $claimData['patient_responsibility'] ?? 0,
                        'payer_claim_number'     => $claimData['payer_claim_number'] ?? null,
                        'service_date_from'      => $claimData['service_date_from'] ?? null,
                        'service_date_to'        => $claimData['service_date_to'] ?? null,
                        'rendering_provider_npi' => $claimData['rendering_provider_npi'] ?? null,
                        'remittance_date'        => $claimData['remittance_date'],
                    ]);

                    $claimCount++;

                    // Track paid vs denied counts for batch summary
                    if ($claim->isPaid()) {
                        $paidCount++;
                    } elseif ($claim->isDenied()) {
                        $deniedCount++;
                    }

                    // Create CAS adjustment records
                    $adjustments = $claimData['adjustments'] ?? [];
                    foreach ($adjustments as $adjData) {
                        RemittanceAdjustment::create([
                            'remittance_claim_id'   => $claim->id,
                            'tenant_id'             => $batch->tenant_id,
                            'adjustment_group_code' => $adjData['adjustment_group_code'],
                            'reason_code'           => $adjData['reason_code'],
                            'adjustment_amount'     => $adjData['adjustment_amount'],
                            'adjustment_quantity'   => $adjData['adjustment_quantity'] ?? null,
                            'service_line_id'       => $adjData['service_line_id'] ?? null,
                        ]);
                        $adjustmentCount++;
                    }

                    // Auto-create DenialRecord for denied claims
                    if ($claim->isDenied()) {
                        $primaryCode = $parserService->getPrimaryReasonCode($adjustments);
                        $category    = $parserService->categorizeDenial($adjustments);

                        DenialRecord::create([
                            'remittance_claim_id' => $claim->id,
                            'tenant_id'           => $batch->tenant_id,
                            'encounter_log_id'    => $claim->encounter_log_id,
                            'denial_category'     => $category,
                            'status'              => 'open',
                            'denied_amount'       => $claim->submitted_amount,
                            'primary_reason_code' => $primaryCode,
                            'denial_reason'       => $this->buildDenialReason($primaryCode, $category),
                            'denial_date'         => $claim->remittance_date,
                            // CMS 120-day appeal deadline per 42 CFR §405.942
                            'appeal_deadline'     => $claim->remittance_date
                                ->addDays(DenialRecord::APPEAL_DEADLINE_DAYS)
                                ->toDateString(),
                        ]);
                    }
                }

                // ── Step 4: Update batch aggregate counts ──────────────────
                $batch->update([
                    'claim_count'      => $claimCount,
                    'paid_count'       => $paidCount,
                    'denied_count'     => $deniedCount,
                    'adjustment_count' => $adjustmentCount,
                    'status'           => 'processed',
                    'processed_at'     => now(),
                ]);
            });

            // ── Step 5: Alert finance department ──────────────────────────────
            $this->dispatchFinanceAlert($batch, $alertService, $paidCount, $deniedCount);

            // ── Step 6: Audit log ─────────────────────────────────────────────
            AuditLog::record(
                action:       'remittance_batch.processed',
                resourceType: 'RemittanceBatch',
                resourceId:   $batch->id,
                tenantId:     $batch->tenant_id,
                userId:       $batch->created_by_user_id,
                newValues:    [
                    'claim_count'  => $claimCount,
                    'paid_count'   => $paidCount,
                    'denied_count' => $deniedCount,
                ],
            );

            Log::info("[Process835RemittanceJob] Batch {$batch->id} processed: {$claimCount} claims, {$deniedCount} denied.");
        } catch (\Throwable $e) {
            // Mark batch as error so finance staff can investigate
            $batch->update(['status' => 'error']);

            Log::error("[Process835RemittanceJob] Failed to process batch {$batch->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            throw $e; // Re-throw so Horizon retries the job
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dispatch a finance department alert summarizing the processed payment.
     * Severity is 'warning' when denials exist, 'info' otherwise.
     */
    private function dispatchFinanceAlert(
        RemittanceBatch $batch,
        AlertService $alertService,
        int $paidCount,
        int $deniedCount
    ): void {
        $hasDenials = $deniedCount > 0;
        $severity   = $hasDenials ? 'warning' : 'info';

        $denialNote = $hasDenials
            ? " {$deniedCount} claim(s) denied — review required."
            : ' All claims processed successfully.';

        $alertService->create([
            'tenant_id'          => $batch->tenant_id,
            'source_module'      => 'remittance',
            'alert_type'         => 'remittance_processed',
            'title'              => "835 Remittance: {$batch->payer_name}",
            'message'            => "\${$batch->payment_amount} payment from {$batch->payer_name} ({$paidCount} claims paid).{$denialNote}",
            'severity'           => $severity,
            'target_departments' => ['finance'],
            'created_by_system'  => true,
            'created_by_user_id' => $batch->created_by_user_id,
            'metadata'           => [
                'remittance_batch_id' => $batch->id,
                'payment_amount'      => (float) $batch->payment_amount,
                'denied_count'        => $deniedCount,
            ],
        ]);
    }

    /**
     * Build a human-readable denial reason string from CARC code + category.
     * Used when no CARC description is available in the emr_carc_codes table.
     */
    private function buildDenialReason(?string $reasonCode, string $category): string
    {
        $categoryLabel = DenialRecord::CATEGORY_LABELS[$category] ?? 'Other';

        if ($reasonCode === null) {
            return "Claim denied ({$categoryLabel}).";
        }

        // Look up full CARC description from reference table
        $carc = \App\Models\CarcCode::findByCode($reasonCode);
        if ($carc !== null) {
            return "CARC {$reasonCode}: {$carc->description}";
        }

        return "Claim denied — reason code {$reasonCode} ({$categoryLabel}).";
    }
}

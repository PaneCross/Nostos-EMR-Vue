<?php

// ─── SdrDeadlineService ───────────────────────────────────────────────────────
// Enforces the 72-hour SDR completion deadline per PACE operational requirements.
// Called by SdrDeadlineEnforcementJob (runs every 15 minutes via Scheduler).
//
// Alert cadence for each open SDR:
//   > 24h remaining → info alert to assigned department ("SDR due in 24h")
//   > 8h remaining  → warning alert to assigned department ("SDR due in 8h")
//   Overdue         → critical alert to assigned dept + qa_compliance, mark escalated
//
// Deduplication: checks if a same-type alert already exists (is_active=true) for
// this SDR before creating a new one, to avoid alert spam on repeated job runs.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Events\SdrOverdueEvent;
use App\Models\AuditLog;
use App\Models\Sdr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SdrDeadlineService
{
    public function __construct(private readonly AlertService $alertService) {}

    /**
     * Process a collection of open SDRs and create appropriate alerts.
     * Returns an array with counts of alerts created.
     */
    public function processBatch(Collection $sdrs): array
    {
        $counts = ['info' => 0, 'warning' => 0, 'escalated' => 0];

        foreach ($sdrs as $sdr) {
            $result = $this->process($sdr);
            if ($result) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * Process a single SDR: check deadline and create alert if needed.
     * Returns the action taken ('info', 'warning', 'escalated') or null.
     */
    public function process(Sdr $sdr): ?string
    {
        $hoursRemaining = $sdr->hoursRemaining();

        // ── Overdue: escalate ────────────────────────────────────────────────
        if ($hoursRemaining <= 0 && ! $sdr->escalated) {
            return $this->escalate($sdr);
        }

        // Phase 2 (MVP roadmap): dual-clock thresholds per sdr_type.
        //   Standard (72h):  info at ≤24h, warning at ≤8h (≈33% and ≈11% of window)
        //   Expedited (24h): info at ≤8h,  warning at ≤3h
        $isExpedited  = $sdr->sdr_type === Sdr::TYPE_EXPEDITED;
        $warnThreshold = $isExpedited ? 3  : 8;
        $infoThreshold = $isExpedited ? 8  : 24;
        $warnKey = $isExpedited ? 'sdr_warning_3h'  : 'sdr_warning_8h';
        $infoKey = $isExpedited ? 'sdr_warning_8h'  : 'sdr_warning_24h';

        if ($hoursRemaining > 0 && $hoursRemaining <= $warnThreshold) {
            if ($this->alertAlreadyExists($sdr, $warnKey)) return null;
            $this->createDeadlineAlert($sdr, $warnKey, 'warning',
                "SDR due in less than {$warnThreshold} hours" . ($isExpedited ? ' (EXPEDITED)' : ''),
                'Service Delivery Request (' . ($isExpedited ? 'expedited 24h' : 'standard 72h')
                    . ") due within {$warnThreshold} hours — escalation imminent.",
            );
            return 'warning';
        }

        if ($hoursRemaining > $warnThreshold && $hoursRemaining <= $infoThreshold) {
            if ($this->alertAlreadyExists($sdr, $infoKey)) return null;
            $this->createDeadlineAlert($sdr, $infoKey, 'info',
                "SDR due in less than {$infoThreshold} hours" . ($isExpedited ? ' (EXPEDITED)' : ''),
                'Service Delivery Request (' . ($isExpedited ? 'expedited 24h' : 'standard 72h')
                    . ") due within {$infoThreshold} hours.",
            );
            return 'info';
        }

        return null;
    }

    /**
     * Escalate an overdue SDR:
     *  - Mark escalated = true on the SDR
     *  - Fire critical alert to assigned dept + qa_compliance
     *  - Broadcast SdrOverdueEvent
     *  - Log to audit trail
     */
    private function escalate(Sdr $sdr): string
    {
        $reason = sprintf(
            'SDR #%d was not completed within %d hours of submission (submitted: %s, due: %s)',
            $sdr->id,
            Sdr::DUE_WINDOW_HOURS,
            $sdr->submitted_at->toDateTimeString(),
            $sdr->due_at->toDateTimeString(),
        );

        // Mark the SDR as escalated (direct update to avoid re-triggering boot)
        Sdr::where('id', $sdr->id)->update([
            'escalated'          => true,
            'escalation_reason'  => $reason,
            'escalated_at'       => now(),
        ]);

        // Critical alert to assigned dept + QA/Compliance
        $targetDepts = array_unique([$sdr->assigned_department, 'qa_compliance']);

        $this->alertService->create([
            'tenant_id'          => $sdr->tenant_id,
            'participant_id'     => $sdr->participant_id,
            'source_module'      => 'sdr',
            'alert_type'         => 'sdr_overdue',
            'severity'           => 'critical',
            'title'              => 'SDR Overdue — Escalated',
            'message'            => $reason,
            'target_departments' => $targetDepts,
            'created_by_system'  => true,
        ]);

        // Broadcast for real-time cross-dept sync
        broadcast(new SdrOverdueEvent($sdr->refresh()))->toOthers();

        AuditLog::record(
            action: 'sdr_escalated',
            tenantId: $sdr->tenant_id,
            userId: null,
            resourceType: 'sdr',
            resourceId: $sdr->id,
            description: $reason,
        );

        Log::warning("[SdrDeadlineService] SDR #{$sdr->id} escalated — overdue", [
            'sdr_id'           => $sdr->id,
            'participant_id'   => $sdr->participant_id,
            'assigned_dept'    => $sdr->assigned_department,
            'hours_overdue'    => abs($sdr->hoursRemaining()),
        ]);

        return 'escalated';
    }

    /**
     * Create a deadline warning alert for an SDR.
     */
    private function createDeadlineAlert(
        Sdr    $sdr,
        string $alertType,
        string $severity,
        string $title,
        string $message,
    ): void {
        $this->alertService->create([
            'tenant_id'          => $sdr->tenant_id,
            'participant_id'     => $sdr->participant_id,
            'source_module'      => 'sdr',
            'alert_type'         => $alertType,
            'severity'           => $severity,
            'title'              => $title,
            'message'            => $message . " (SDR #{$sdr->id} — {$sdr->typeLabel()})",
            'target_departments' => [$sdr->assigned_department],
            'created_by_system'  => true,
        ]);
    }

    /**
     * Check if an active alert of this type already exists for this SDR.
     * Prevents duplicate alerts across repeated job runs.
     */
    private function alertAlreadyExists(Sdr $sdr, string $alertType): bool
    {
        return \App\Models\Alert::where('tenant_id', $sdr->tenant_id)
            ->where('participant_id', $sdr->participant_id)
            ->where('source_module', 'sdr')
            ->where('alert_type', $alertType)
            ->where('is_active', true)
            ->exists();
    }
}

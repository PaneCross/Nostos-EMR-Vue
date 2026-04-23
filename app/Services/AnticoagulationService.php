<?php

// ─── AnticoagulationService ──────────────────────────────────────────────────
// Phase B5. Anticoagulation plan + INR workflow:
//
//   recordInr()     — persists a new INR, pre-computes in_range, emits alert
//                     if out-of-range against the participant's active warfarin
//                     plan. Critical ranges escalate to the critical severity
//                     + additional target department (pharmacy).
//
//   activePlan()    — returns the currently-active plan for a participant, or
//                     null. If multiple exist, the most-recently-started wins
//                     (shouldn't happen with well-formed data).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AnticoagulationPlan;
use App\Models\AuditLog;
use App\Models\InrResult;
use App\Models\Participant;
use App\Models\User;
use Carbon\Carbon;

class AnticoagulationService
{
    public function __construct(private AlertService $alerts) {}

    public function activePlan(Participant $participant): ?AnticoagulationPlan
    {
        return AnticoagulationPlan::forTenant($participant->tenant_id)
            ->where('participant_id', $participant->id)
            ->active()
            ->orderByDesc('start_date')
            ->first();
    }

    /**
     * Record a new INR result. If the participant has an active warfarin plan,
     * the result is evaluated against its target range and the row is linked
     * to that plan. Out-of-range values emit warning/critical alerts.
     */
    public function recordInr(
        Participant $participant,
        float $value,
        Carbon $drawnAt,
        User $user,
        ?string $doseAdjustment = null,
        ?string $notes = null,
    ): InrResult {
        $plan = $this->activePlan($participant);
        // Only evaluate against plan if it's INR-monitored (warfarin).
        $evaluatingPlan = ($plan && $plan->requiresInr()) ? $plan : null;

        $status = $evaluatingPlan ? $evaluatingPlan->evaluateInr($value) : 'no_target';
        $inRange = $status === 'in_range';

        $inr = InrResult::create([
            'tenant_id'               => $participant->tenant_id,
            'participant_id'          => $participant->id,
            'anticoagulation_plan_id' => $evaluatingPlan?->id,
            'drawn_at'                => $drawnAt,
            'value'                   => $value,
            'in_range'                => $evaluatingPlan ? $inRange : null,
            'dose_adjustment_text'    => $doseAdjustment,
            'recorded_by_user_id'     => $user->id,
            'notes'                   => $notes,
        ]);

        AuditLog::record(
            action: 'anticoag.inr_recorded',
            tenantId: $participant->tenant_id,
            userId: $user->id,
            resourceType: 'inr_result',
            resourceId: $inr->id,
            description: "INR {$value} recorded for participant #{$participant->id} (status={$status}).",
        );

        if ($evaluatingPlan && $status !== 'in_range' && $status !== 'no_target') {
            $this->emitOutOfRangeAlert($participant, $evaluatingPlan, $inr, $status);
        }

        return $inr;
    }

    private function emitOutOfRangeAlert(
        Participant $participant,
        AnticoagulationPlan $plan,
        InrResult $inr,
        string $status,
    ): void {
        $isCritical = str_starts_with($status, 'critical_');

        $this->alerts->create([
            'tenant_id'          => $participant->tenant_id,
            'participant_id'     => $participant->id,
            'source_module'      => 'anticoag',
            'alert_type'         => 'inr_out_of_range',
            'severity'           => $isCritical ? 'critical' : 'warning',
            'title'              => $isCritical
                ? 'Critical INR result'
                : 'INR out of range',
            'message'            => sprintf(
                'Participant INR = %.1f (target %.1f–%.1f, status=%s). Clinical review required.',
                $inr->value,
                (float) $plan->target_inr_low,
                (float) $plan->target_inr_high,
                $status,
            ),
            'target_departments' => $isCritical
                ? ['primary_care', 'pharmacy', 'qa_compliance']
                : ['primary_care', 'pharmacy'],
            'metadata'           => [
                'inr_result_id' => $inr->id,
                'plan_id'       => $plan->id,
                'value'         => (float) $inr->value,
                'status'        => $status,
            ],
        ]);
    }
}

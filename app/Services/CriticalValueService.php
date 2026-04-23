<?php

// ─── CriticalValueService ────────────────────────────────────────────────────
// Phase B6. Evaluates a saved Vital against per-tenant thresholds
// (VitalThreshold::resolve). For each field out of range, creates a
// CriticalValueAcknowledgment row and emits an alert.
//
// Severity: critical > warning. Critical entries target
// primary_care + pharmacy + qa_compliance; warnings target primary_care only.
// Deadline: 2h for critical, 8h for warning.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CriticalValueAcknowledgment;
use App\Models\Vital;
use App\Models\VitalThreshold;

class CriticalValueService
{
    public function __construct(private AlertService $alerts) {}

    /**
     * Evaluate a newly-saved vital against tenant thresholds.
     * Returns the created acknowledgment rows (one per out-of-range field).
     *
     * @return \Illuminate\Support\Collection<int, CriticalValueAcknowledgment>
     */
    public function evaluateVital(Vital $vital): \Illuminate\Support\Collection
    {
        $acks = collect();
        foreach (VitalThreshold::FIELDS as $field) {
            $value = $vital->{$field};
            if ($value === null) continue;

            $t = VitalThreshold::resolve($vital->tenant_id, $field);
            $severity = null;
            $direction = null;

            if ($t['critical_low'] !== null && $value < $t['critical_low']) {
                $severity = 'critical'; $direction = 'low';
            } elseif ($t['critical_high'] !== null && $value > $t['critical_high']) {
                $severity = 'critical'; $direction = 'high';
            } elseif ($t['warning_low'] !== null && $value < $t['warning_low']) {
                $severity = 'warning'; $direction = 'low';
            } elseif ($t['warning_high'] !== null && $value > $t['warning_high']) {
                $severity = 'warning'; $direction = 'high';
            }

            if (! $severity) continue;

            $deadlineHours = $severity === 'critical'
                ? CriticalValueAcknowledgment::DEADLINE_HOURS_CRITICAL
                : CriticalValueAcknowledgment::DEADLINE_HOURS_WARNING;

            $ack = CriticalValueAcknowledgment::create([
                'tenant_id'      => $vital->tenant_id,
                'participant_id' => $vital->participant_id,
                'vital_id'       => $vital->id,
                'field_name'     => $field,
                'value'          => $value,
                'severity'       => $severity,
                'direction'      => $direction,
                'deadline_at'    => now()->addHours($deadlineHours),
            ]);

            $acks->push($ack);

            $this->emitAlert($ack);

            AuditLog::record(
                action: 'vital.critical_value_flagged',
                tenantId: $vital->tenant_id,
                userId: $vital->recorded_by_user_id,
                resourceType: 'vital',
                resourceId: $vital->id,
                description: "Vital {$field}={$value} flagged {$severity} ({$direction}). Ack deadline {$ack->deadline_at}.",
            );
        }

        return $acks;
    }

    private function emitAlert(CriticalValueAcknowledgment $ack): void
    {
        $isCritical = $ack->severity === 'critical';
        $this->alerts->create([
            'tenant_id'          => $ack->tenant_id,
            'participant_id'     => $ack->participant_id,
            'source_module'      => 'vital',
            'alert_type'         => 'critical_value_flagged',
            'severity'           => $isCritical ? 'critical' : 'warning',
            'title'              => $isCritical ? 'Critical vital value' : 'Abnormal vital value',
            'message'            => sprintf(
                'Participant #%d: %s = %s (%s %s). Acknowledge within %dh.',
                $ack->participant_id,
                $ack->field_name,
                $ack->value,
                $ack->direction,
                $ack->severity,
                $isCritical
                    ? CriticalValueAcknowledgment::DEADLINE_HOURS_CRITICAL
                    : CriticalValueAcknowledgment::DEADLINE_HOURS_WARNING,
            ),
            'target_departments' => $isCritical
                ? ['primary_care', 'pharmacy', 'qa_compliance']
                : ['primary_care'],
            'metadata'           => [
                'critical_value_ack_id' => $ack->id,
                'field_name'            => $ack->field_name,
                'value'                 => (float) $ack->value,
                'direction'             => $ack->direction,
            ],
        ]);
    }
}

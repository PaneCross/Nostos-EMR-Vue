<?php

// ─── CriticalValueEscalationJob ──────────────────────────────────────────────
// Phase B6. Hourly. Finds unacknowledged critical acks past their deadline_at,
// stamps escalated_at (idempotent — only stamps once), and emits a critical
// escalation alert to executive + qa_compliance.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\CriticalValueAcknowledgment;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CriticalValueEscalationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $overdue = CriticalValueAcknowledgment::query()
            ->overdue()
            ->where('severity', 'critical')
            ->whereNull('escalated_at')
            ->get();

        foreach ($overdue as $ack) {
            $ack->update(['escalated_at' => now()]);

            $alerts->create([
                'tenant_id'          => $ack->tenant_id,
                'participant_id'     => $ack->participant_id,
                'source_module'      => 'vital',
                'alert_type'         => 'critical_value_escalation',
                'severity'           => 'critical',
                'title'              => 'Critical value UNACKNOWLEDGED past deadline',
                'message'            => sprintf(
                    'Participant #%d: %s = %s (%s) flagged %s but not acknowledged within deadline (%s). Executive escalation.',
                    $ack->participant_id,
                    $ack->field_name,
                    $ack->value,
                    $ack->direction,
                    $ack->severity,
                    $ack->deadline_at?->toDateTimeString(),
                ),
                'target_departments' => ['executive', 'qa_compliance'],
                'metadata'           => [
                    'critical_value_ack_id' => $ack->id,
                    'field_name'            => $ack->field_name,
                    'participant_id'        => $ack->participant_id,
                ],
            ]);
        }
    }
}

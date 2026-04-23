<?php

// ─── SentinelEventDeadlineJob ────────────────────────────────────────────────
// Phase B3. Daily sweep at 07:30. Four alert rules per sentinel-classified
// incident:
//
//   1. CMS deadline approaching (T-2 days, not yet sent): warning → qa_compliance
//   2. CMS deadline MISSED (past, not yet sent): critical → qa_compliance + executive
//   3. RCA deadline approaching (T-5 days, RCA not complete): warning → qa_compliance
//   4. RCA deadline MISSED (past, RCA not complete): critical → qa_compliance + executive
//
// Dedup: each alert type dedupes per incident within the remaining window
// via metadata->>incident_id.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Incident;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SentinelEventDeadlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $sentinels = Incident::query()->sentinels()->get();

        foreach ($sentinels as $incident) {
            $this->evaluateCmsDeadline($alerts, $incident);
            $this->evaluateRcaDeadline($alerts, $incident);
        }
    }

    private function evaluateCmsDeadline(AlertService $alerts, Incident $i): void
    {
        if (! $i->sentinel_cms_5day_deadline) return;
        if ($i->cms_notification_sent_at !== null) return; // Already satisfied

        $hoursUntil = now()->diffInHours($i->sentinel_cms_5day_deadline, false);

        if ($hoursUntil < 0) {
            // MISSED
            if ($this->alreadyAlerted('sentinel_cms_deadline_missed', $i->id, hours: 24)) return;
            $alerts->create([
                'tenant_id'          => $i->tenant_id,
                'participant_id'     => $i->participant_id,
                'source_module'      => 'incident',
                'alert_type'         => 'sentinel_cms_deadline_missed',
                'title'              => 'Sentinel CMS 5-day deadline MISSED',
                'message'            => "Sentinel incident #{$i->id} has not had CMS notification sent. "
                    . 'Deadline ' . $i->sentinel_cms_5day_deadline->toDateTimeString() . ' is past. '
                    . 'Executive escalation required per 42 CFR §460.136.',
                'severity'           => 'critical',
                'target_departments' => ['qa_compliance', 'executive'],
                'metadata'           => ['incident_id' => $i->id, 'deadline_type' => 'cms_5day'],
            ]);
        } elseif ($hoursUntil <= 48) {
            // Approaching (T-2 days)
            if ($this->alreadyAlerted('sentinel_cms_deadline_approaching', $i->id, hours: 24)) return;
            $alerts->create([
                'tenant_id'          => $i->tenant_id,
                'participant_id'     => $i->participant_id,
                'source_module'      => 'incident',
                'alert_type'         => 'sentinel_cms_deadline_approaching',
                'title'              => 'Sentinel CMS 5-day deadline approaching',
                'message'            => "Sentinel incident #{$i->id} requires CMS notification by "
                    . $i->sentinel_cms_5day_deadline->toDateTimeString()
                    . " (~{$hoursUntil}h remaining).",
                'severity'           => 'warning',
                'target_departments' => ['qa_compliance'],
                'metadata'           => ['incident_id' => $i->id, 'deadline_type' => 'cms_5day'],
            ]);
        }
    }

    private function evaluateRcaDeadline(AlertService $alerts, Incident $i): void
    {
        if (! $i->sentinel_rca_30day_deadline) return;
        if ($i->rca_completed_at !== null) return;

        $hoursUntil = now()->diffInHours($i->sentinel_rca_30day_deadline, false);

        if ($hoursUntil < 0) {
            if ($this->alreadyAlerted('sentinel_rca_deadline_missed', $i->id, hours: 24)) return;
            $alerts->create([
                'tenant_id'          => $i->tenant_id,
                'participant_id'     => $i->participant_id,
                'source_module'      => 'incident',
                'alert_type'         => 'sentinel_rca_deadline_missed',
                'title'              => 'Sentinel RCA 30-day deadline MISSED',
                'message'            => "Sentinel incident #{$i->id} has no completed RCA. "
                    . 'Deadline ' . $i->sentinel_rca_30day_deadline->toDateTimeString() . ' is past.',
                'severity'           => 'critical',
                'target_departments' => ['qa_compliance', 'executive'],
                'metadata'           => ['incident_id' => $i->id, 'deadline_type' => 'rca_30day'],
            ]);
        } elseif ($hoursUntil <= 120) {
            // Approaching (T-5 days)
            if ($this->alreadyAlerted('sentinel_rca_deadline_approaching', $i->id, hours: 48)) return;
            $alerts->create([
                'tenant_id'          => $i->tenant_id,
                'participant_id'     => $i->participant_id,
                'source_module'      => 'incident',
                'alert_type'         => 'sentinel_rca_deadline_approaching',
                'title'              => 'Sentinel RCA 30-day deadline approaching',
                'message'            => "Sentinel incident #{$i->id} RCA due by "
                    . $i->sentinel_rca_30day_deadline->toDateTimeString() . '.',
                'severity'           => 'warning',
                'target_departments' => ['qa_compliance'],
                'metadata'           => ['incident_id' => $i->id, 'deadline_type' => 'rca_30day'],
            ]);
        }
    }

    private function alreadyAlerted(string $type, int $incidentId, int $hours): bool
    {
        return Alert::where('alert_type', $type)
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereRaw("(metadata->>'incident_id')::int = ?", [$incidentId])
            ->exists();
    }
}

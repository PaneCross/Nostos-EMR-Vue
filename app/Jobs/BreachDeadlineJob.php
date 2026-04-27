<?php

// ─── BreachDeadlineJob : Phase Q1 ───────────────────────────────────────────
// Daily sweep at 07:00. HIPAA §164.408 deadlines:
//   500+ affected: HHS within 60 calendar days of discovery.
//   <500 affected: HHS by Mar 1 of the year following discovery.
// Two alerts per open incident:
//   1. T-3d warning when deadline approaches → it_admin + qa_compliance
//   2. Missed deadline critical → executive + it_admin + qa_compliance
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\BreachIncident;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BreachDeadlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AlertService $alerts): void
    {
        $open = BreachIncident::open()->whereNotNull('hhs_deadline_at')->whereNull('hhs_notified_at')->get();
        foreach ($open as $b) {
            $remaining = (int) now()->diffInDays($b->hhs_deadline_at, false);
            if ($remaining < 0) {
                $this->emitOnce($alerts, $b, 'breach_hhs_deadline_missed', 'critical',
                    "Breach incident #{$b->id} ({$b->affected_count} affected) MISSED HHS notification deadline §164.408.");
            } elseif ($remaining <= 3) {
                $this->emitOnce($alerts, $b, 'breach_hhs_deadline_t3', 'warning',
                    "Breach #{$b->id} HHS deadline in {$remaining} days. Submit at https://ocrportal.hhs.gov/ocr/breach/.");
            }
        }
    }

    private function emitOnce(AlertService $alerts, BreachIncident $b, string $type, string $severity, string $message): void
    {
        $exists = Alert::where('tenant_id', $b->tenant_id)
            ->where('alert_type', $type)
            ->whereJsonContains('metadata->breach_incident_id', $b->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($exists) return;

        $depts = $severity === 'critical'
            ? ['executive', 'it_admin', 'qa_compliance']
            : ['it_admin', 'qa_compliance'];

        $alerts->create([
            'tenant_id'          => $b->tenant_id,
            'alert_type'         => $type,
            'severity'           => $severity,
            'title'              => 'HIPAA Breach Notification deadline',
            'message'            => $message,
            'target_departments' => $depts,
            'source_module'      => 'compliance',
            'metadata'           => ['breach_incident_id' => $b->id, 'hhs_deadline_at' => $b->hhs_deadline_at?->toIso8601String()],
            'created_by_system'  => true,
        ]);
    }
}

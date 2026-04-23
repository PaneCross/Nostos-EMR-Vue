<?php

// ─── RestraintMonitoringOverdueJob ───────────────────────────────────────────
// Phase B1. Runs every 15 minutes. Two checks per active restraint episode:
//
//   1. Monitoring overdue: last observation > 4h ago (or no observation yet
//      and initiation > 4h ago) → warning alert to nursing + medical_director
//
//   2. IDT review overdue: initiation > 24h ago AND idt_review_date is null →
//      critical alert to qa_compliance + medical_director
//
// Dedupe: both alert types use metadata->restraint_episode_id and only fire
// once per active window (not once per run).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\RestraintEpisode;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RestraintMonitoringOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $episodes = RestraintEpisode::active()->get();

        foreach ($episodes as $episode) {
            if ($episode->monitoringOverdue()) {
                $this->emitMonitoringAlert($alerts, $episode);
            }
            if ($episode->idtReviewOverdue()) {
                $this->emitIdtReviewAlert($alerts, $episode);
            }
        }
    }

    private function emitMonitoringAlert(AlertService $alerts, RestraintEpisode $e): void
    {
        if ($this->alreadyAlerted('restraint_monitoring_overdue', $e->id, hoursWindow: 4)) {
            return;
        }

        $mins = $e->minutesSinceLastObservation();
        $alerts->create([
            'tenant_id'          => $e->tenant_id,
            'participant_id'     => $e->participant_id,
            'source_module'      => 'restraint',
            'alert_type'         => 'restraint_monitoring_overdue',
            'title'              => 'Restraint monitoring overdue',
            'message'            => "Restraint episode #{$e->id} has no monitoring observation in {$mins} minutes "
                                   . '(threshold: ' . RestraintEpisode::MONITORING_OVERDUE_MIN . ' min). '
                                   . 'Active since ' . $e->initiated_at->toDateTimeString() . '.',
            'severity'           => 'warning',
            // No 'nursing' dept in shared_users_department_check; nurses are
            // under primary_care (clinic) or home_care (home visits).
            'target_departments' => ['primary_care', 'home_care'],
            'metadata'           => [
                'restraint_episode_id' => $e->id,
                'minutes_overdue'      => $mins - RestraintEpisode::MONITORING_OVERDUE_MIN,
            ],
        ]);
    }

    private function emitIdtReviewAlert(AlertService $alerts, RestraintEpisode $e): void
    {
        if ($this->alreadyAlerted('restraint_idt_review_overdue', $e->id, hoursWindow: 24)) {
            return;
        }

        $hours = (int) $e->initiated_at->diffInHours(now());
        $alerts->create([
            'tenant_id'          => $e->tenant_id,
            'participant_id'     => $e->participant_id,
            'source_module'      => 'restraint',
            'alert_type'         => 'restraint_idt_review_overdue',
            'title'              => 'Restraint IDT review overdue',
            'message'            => "Restraint episode #{$e->id} was initiated {$hours} hours ago with no IDT review. "
                                   . 'Required within 24 hours per 42 CFR §460 / CMS PACE Audit Protocol.',
            'severity'           => 'critical',
            'target_departments' => ['qa_compliance'],
            'metadata'           => [
                'restraint_episode_id' => $e->id,
                'hours_since_initiation' => $hours,
            ],
        ]);
    }

    /**
     * True if an alert of this type was already created for this episode
     * within the last N hours (dedupe window).
     */
    private function alreadyAlerted(string $type, int $episodeId, int $hoursWindow): bool
    {
        return Alert::where('alert_type', $type)
            ->where('created_at', '>=', now()->subHours($hoursWindow))
            ->whereRaw("(metadata->>'restraint_episode_id')::int = ?", [$episodeId])
            ->exists();
    }
}

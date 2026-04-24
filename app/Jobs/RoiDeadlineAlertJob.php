<?php

// ─── RoiDeadlineAlertJob ─────────────────────────────────────────────────────
// Phase B8b. Daily sweep at 07:15. For each open ROI request:
//   - Day-25+ (T-5 days to deadline): warning → qa_compliance
//   - Overdue (past due_by): critical → qa_compliance + executive
// Dedup 3d/request via metadata.roi_request_id.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\RoiRequest;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RoiDeadlineAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $open = RoiRequest::query()->open()->get();

        foreach ($open as $roi) {
            if (! $roi->due_by) continue;

            if ($roi->due_by->isPast()) {
                $this->emitOverdue($alerts, $roi);
            } elseif ($roi->due_by->lt(now()->addDays(5))) {
                $this->emitApproaching($alerts, $roi);
            }
        }
    }

    private function emitApproaching(AlertService $alerts, RoiRequest $roi): void
    {
        if ($this->alreadyAlerted('roi_deadline_approaching', $roi->id, hours: 72)) return;

        $days = max(0, (int) now()->diffInDays($roi->due_by, false));
        $alerts->create([
            'tenant_id'          => $roi->tenant_id,
            'participant_id'     => $roi->participant_id,
            'source_module'      => 'roi',
            'alert_type'         => 'roi_deadline_approaching',
            'severity'           => 'warning',
            'title'              => 'ROI response deadline approaching',
            'message'            => "ROI request #{$roi->id} from {$roi->requestor_name} "
                . "is due {$roi->due_by->toDateString()} ({$days}d remaining). HIPAA §164.524 30-day window.",
            'target_departments' => ['qa_compliance'],
            'metadata'           => ['roi_request_id' => $roi->id, 'days_remaining' => $days],
        ]);
    }

    private function emitOverdue(AlertService $alerts, RoiRequest $roi): void
    {
        if ($this->alreadyAlerted('roi_deadline_overdue', $roi->id, hours: 24)) return;

        $daysOver = (int) abs($roi->due_by->diffInDays(now()));
        $alerts->create([
            'tenant_id'          => $roi->tenant_id,
            'participant_id'     => $roi->participant_id,
            'source_module'      => 'roi',
            'alert_type'         => 'roi_deadline_overdue',
            'severity'           => 'critical',
            'title'              => 'ROI response OVERDUE',
            'message'            => "ROI request #{$roi->id} from {$roi->requestor_name} "
                . "was due {$roi->due_by->toDateString()} ({$daysOver}d overdue). "
                . 'HIPAA §164.524 response-window violation.',
            'target_departments' => ['qa_compliance', 'executive'],
            'metadata'           => ['roi_request_id' => $roi->id, 'days_overdue' => $daysOver],
        ]);
    }

    private function alreadyAlerted(string $type, int $roiId, int $hours): bool
    {
        return Alert::where('alert_type', $type)
            ->where('created_at', '>=', now()->subHours($hours))
            ->whereRaw("(metadata->>'roi_request_id')::int = ?", [$roiId])
            ->exists();
    }
}

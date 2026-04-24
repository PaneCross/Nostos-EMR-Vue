<?php

// ─── TbScreeningDueJob ───────────────────────────────────────────────────────
// Phase C2a. Daily. For each enrolled participant, finds the latest TB
// screening; if none OR next_due_date is ≤60 days out, emit alerts at the
// 60/30/0-day thresholds (info → warning → critical).
//
// Dedup per (participant, threshold) via metadata.participant_id + threshold.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\TbScreening;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TbScreeningDueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $participants = Participant::query()
            ->where('enrollment_status', 'enrolled')
            ->select('id', 'tenant_id', 'mrn', 'first_name', 'last_name')
            ->get();

        foreach ($participants as $p) {
            $latest = TbScreening::where('participant_id', $p->id)
                ->orderByDesc('performed_date')->first();

            $days = $latest?->daysUntilDue();

            // No screening ever recorded → treat as overdue.
            if (! $latest) {
                $this->emit($alerts, $p, 'overdue', null);
                continue;
            }

            if ($days === null) continue;

            if ($days < 0)       $this->emit($alerts, $p, 'overdue',      $latest);
            elseif ($days <= 0)  $this->emit($alerts, $p, 'due_today',    $latest);
            elseif ($days <= 30) $this->emit($alerts, $p, 'due_30',       $latest);
            elseif ($days <= 60) $this->emit($alerts, $p, 'due_60',       $latest);
        }
    }

    private function emit(AlertService $alerts, Participant $p, string $threshold, ?TbScreening $latest): void
    {
        if ($this->alreadyAlerted($p->id, $threshold)) return;

        $severity = match ($threshold) {
            'overdue'    => 'critical',
            'due_today'  => 'warning',
            'due_30'     => 'warning',
            'due_60'     => 'info',
        };

        $msg = match ($threshold) {
            'overdue'   => "TB screening overdue for participant #{$p->id} ({$p->mrn}). §460.71 requires annual TB screening.",
            'due_today' => "TB screening due today for participant #{$p->id} ({$p->mrn}).",
            'due_30'    => "TB screening due in ≤30 days for participant #{$p->id} ({$p->mrn}).",
            'due_60'    => "TB screening due in ≤60 days for participant #{$p->id} ({$p->mrn}).",
        };

        $alerts->create([
            'tenant_id'          => $p->tenant_id,
            'participant_id'     => $p->id,
            'source_module'      => 'tb_screening',
            'alert_type'         => "tb_screening_{$threshold}",
            'severity'           => $severity,
            'title'              => 'TB screening ' . str_replace('_', ' ', $threshold),
            'message'            => $msg,
            'target_departments' => $threshold === 'overdue'
                ? ['primary_care', 'qa_compliance']
                : ['primary_care'],
            'metadata'           => [
                'participant_id'       => $p->id,
                'threshold'            => $threshold,
                'latest_screening_id'  => $latest?->id,
            ],
        ]);
    }

    private function alreadyAlerted(int $participantId, string $threshold): bool
    {
        return Alert::where('alert_type', "tb_screening_{$threshold}")
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$participantId])
            ->exists();
    }
}

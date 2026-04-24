<?php

namespace App\Jobs;

use App\Models\AdverseDrugEvent;
use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AdeMedwatchReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $events = AdverseDrugEvent::query()
            ->whereIn('severity', AdverseDrugEvent::MEDWATCH_REQUIRED_SEVERITIES)
            ->whereNull('reported_to_medwatch_at')
            ->get();

        foreach ($events as $ade) {
            $daysRemaining = 15 - $ade->onset_date->diffInDays(now());
            $overdue = $daysRemaining < 0;

            $type = $overdue ? 'ade_medwatch_overdue' : 'ade_medwatch_reminder';
            if ($this->alreadyAlerted($ade->id, $type)) continue;

            $alerts->create([
                'tenant_id'          => $ade->tenant_id,
                'participant_id'     => $ade->participant_id,
                'source_module'      => 'ade',
                'alert_type'         => $type,
                'severity'           => $overdue ? 'critical' : 'warning',
                'title'              => $overdue ? 'MedWatch report OVERDUE' : 'MedWatch report due',
                'message'            => $overdue
                    ? "ADE #{$ade->id} ({$ade->severity}) — MedWatch 15-day deadline passed. FDA reporting required."
                    : "ADE #{$ade->id} ({$ade->severity}) requires MedWatch report. Deadline in {$daysRemaining} days.",
                'target_departments' => $overdue
                    ? ['qa_compliance', 'pharmacy', 'executive']
                    : ['qa_compliance', 'pharmacy'],
                'metadata'           => ['ade_id' => $ade->id, 'days_remaining' => $daysRemaining],
            ]);
        }
    }

    private function alreadyAlerted(int $adeId, string $type): bool
    {
        return Alert::where('alert_type', $type)
            ->where('created_at', '>=', now()->subDays(3))
            ->whereRaw("(metadata->>'ade_id')::int = ?", [$adeId])
            ->exists();
    }
}

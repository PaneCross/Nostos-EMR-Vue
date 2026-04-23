<?php

// ─── InrOverdueJob ───────────────────────────────────────────────────────────
// Phase B5. Daily sweep. For every active warfarin plan, checks whether the
// most recent INR was drawn more than (monitoring_interval_days OR default 30)
// days ago. If so, emits a warning alert to primary_care + pharmacy.
// Dedup 7d per plan via metadata->>'plan_id'.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\AnticoagulationPlan;
use App\Models\InrResult;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InrOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $plans = AnticoagulationPlan::query()->active()
            ->where('agent', 'warfarin')
            ->get();

        foreach ($plans as $plan) {
            $interval = $plan->monitoring_interval_days
                ?? AnticoagulationPlan::DEFAULT_WARFARIN_MONITOR_DAYS;

            $latest = InrResult::where('participant_id', $plan->participant_id)
                ->orderByDesc('drawn_at')
                ->value('drawn_at');

            $threshold = now()->subDays($interval);
            $overdue = ! $latest || $latest->lt($threshold);
            if (! $overdue) continue;

            if ($this->alreadyAlerted($plan->id)) continue;

            $daysSince = $latest ? (int) abs($latest->diffInDays(now())) : null;
            $alerts->create([
                'tenant_id'          => $plan->tenant_id,
                'participant_id'     => $plan->participant_id,
                'source_module'      => 'anticoag',
                'alert_type'         => 'inr_overdue',
                'severity'           => 'warning',
                'title'              => 'INR draw overdue',
                'message'            => $latest
                    ? "Last INR for participant #{$plan->participant_id} was {$daysSince} days ago (interval {$interval}d)."
                    : "No INR on record for participant #{$plan->participant_id} on active warfarin plan.",
                'target_departments' => ['primary_care', 'pharmacy'],
                'metadata'           => [
                    'plan_id'    => $plan->id,
                    'days_since' => $daysSince,
                ],
            ]);
        }
    }

    private function alreadyAlerted(int $planId): bool
    {
        return Alert::where('alert_type', 'inr_overdue')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereRaw("(metadata->>'plan_id')::int = ?", [$planId])
            ->exists();
    }
}

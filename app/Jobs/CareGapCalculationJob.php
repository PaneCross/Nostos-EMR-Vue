<?php

namespace App\Jobs;

use App\Models\CareGap;
use App\Models\Participant;
use App\Models\StaffTask;
use App\Services\CareGapService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CareGapCalculationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Minimum open gaps to trigger a scheduling task. */
    public const TASK_THRESHOLD = 3;

    public function handle(CareGapService $svc): void
    {
        $participants = Participant::where('enrollment_status', 'enrolled')->get();

        foreach ($participants as $p) {
            $svc->evaluate($p);

            $openCount = CareGap::forTenant($p->tenant_id)
                ->where('participant_id', $p->id)->open()->count();

            if ($openCount >= self::TASK_THRESHOLD) {
                // Dedup: don't open a new task if one is already pending for this participant.
                $exists = StaffTask::forTenant($p->tenant_id)
                    ->where('participant_id', $p->id)
                    ->where('related_to_type', 'care_gap')
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->exists();
                if ($exists) continue;
                StaffTask::create([
                    'tenant_id'              => $p->tenant_id,
                    'participant_id'         => $p->id,
                    'assigned_to_department' => 'primary_care',
                    'title'                  => "Care gaps: {$openCount} open for participant #{$p->id}",
                    'description'            => 'Preventive care gaps flagged by nightly care-gap calculator. Review and schedule/close.',
                    'priority'               => 'normal',
                    'status'                 => 'pending',
                    'related_to_type'        => 'care_gap',
                    'related_to_id'          => $p->id,
                ]);
            }
        }
    }
}

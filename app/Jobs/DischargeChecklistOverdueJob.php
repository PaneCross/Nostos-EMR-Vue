<?php

// ─── DischargeChecklistOverdueJob ────────────────────────────────────────────
// Phase C4. Daily. For each discharge event <30d old with any uncompleted
// overdue item, emit warning alert to the item's owner department. Dedup per
// (event, item_key) for 3 days.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\DischargeEvent;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DischargeChecklistOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $events = DischargeEvent::query()
            ->where('discharged_on', '>=', now()->subDays(30))->get();

        foreach ($events as $event) {
            foreach ($event->overdueItems() as $item) {
                if ($this->alreadyAlerted($event->id, $item['key'])) continue;

                $alerts->create([
                    'tenant_id'          => $event->tenant_id,
                    'participant_id'     => $event->participant_id,
                    'source_module'      => 'discharge',
                    'alert_type'         => 'discharge_checklist_overdue',
                    'severity'           => 'warning',
                    'title'              => 'Discharge checklist item overdue',
                    'message'            => "Overdue: \"{$item['label']}\" for participant #{$event->participant_id} (discharge {$event->discharged_on->toDateString()}).",
                    'target_departments' => [$item['owner_dept']],
                    'metadata'           => [
                        'discharge_event_id' => $event->id,
                        'item_key'           => $item['key'],
                    ],
                ]);
            }
        }
    }

    private function alreadyAlerted(int $eventId, string $key): bool
    {
        return Alert::where('alert_type', 'discharge_checklist_overdue')
            ->where('created_at', '>=', now()->subDays(3))
            ->whereRaw("(metadata->>'discharge_event_id')::int = ?", [$eventId])
            ->whereRaw("metadata->>'item_key' = ?", [$key])
            ->exists();
    }
}

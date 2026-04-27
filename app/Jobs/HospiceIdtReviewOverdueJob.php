<?php

// ─── HospiceIdtReviewOverdueJob ──────────────────────────────────────────────
// Phase C3. Daily. Finds hospice-enrolled participants whose last IDT review
// is older than HOSPICE_IDT_REVIEW_DAYS (180). Emits a warning alert to
// primary_care + social_work + qa_compliance : triggers re-certification
// conversation. Dedup 30d per participant.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HospiceIdtReviewOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $threshold = now()->subDays(Participant::HOSPICE_IDT_REVIEW_DAYS);

        $overdue = Participant::query()
            ->where('hospice_status', 'enrolled')
            ->where(function ($q) use ($threshold) {
                $q->whereNull('hospice_last_idt_review_at')
                  ->orWhere('hospice_last_idt_review_at', '<', $threshold);
            })
            ->get();

        foreach ($overdue as $p) {
            if ($this->alreadyAlerted($p->id)) continue;

            $daysSince = $p->hospice_last_idt_review_at
                ? (int) abs($p->hospice_last_idt_review_at->diffInDays(now()))
                : null;

            $alerts->create([
                'tenant_id'          => $p->tenant_id,
                'participant_id'     => $p->id,
                'source_module'      => 'hospice',
                'alert_type'         => 'hospice_idt_review_overdue',
                'severity'           => 'warning',
                'title'              => 'Hospice IDT review overdue',
                'message'            => $daysSince
                    ? "Hospice-enrolled participant #{$p->id} : last IDT review {$daysSince} days ago. Recertification conversation indicated."
                    : "Hospice-enrolled participant #{$p->id} has no IDT review on record. Schedule huddle.",
                'target_departments' => ['primary_care', 'social_work', 'qa_compliance'],
                'metadata'           => [
                    'participant_id' => $p->id,
                    'days_since'     => $daysSince,
                ],
            ]);
        }
    }

    private function alreadyAlerted(int $participantId): bool
    {
        return Alert::where('alert_type', 'hospice_idt_review_overdue')
            ->where('created_at', '>=', now()->subDays(30))
            ->whereRaw("(metadata->>'participant_id')::int = ?", [$participantId])
            ->exists();
    }
}

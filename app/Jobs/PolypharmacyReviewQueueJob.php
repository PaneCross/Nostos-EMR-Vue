<?php

// ─── PolypharmacyReviewQueueJob ──────────────────────────────────────────────
// Phase C6. Daily. For each enrolled participant with >=10 active meds and no
// PolypharmacyReview queued in the last 180 days, enqueues a new review row.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Participant;
use App\Models\PolypharmacyReview;
use App\Services\BeersCriteriaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PolypharmacyReviewQueueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(BeersCriteriaService $beers): void
    {
        $participants = Participant::query()
            ->where('enrollment_status', 'enrolled')->get();

        foreach ($participants as $p) {
            if (! $beers->isPolypharmacy($p)) continue;

            $lastQueue = PolypharmacyReview::where('participant_id', $p->id)
                ->orderByDesc('queued_at')->value('queued_at');

            if ($lastQueue && $lastQueue->gt(now()->subDays(PolypharmacyReview::REVIEW_INTERVAL_DAYS))) {
                continue;
            }

            $count = \App\Models\Medication::where('participant_id', $p->id)
                ->where('status', 'active')->count();

            PolypharmacyReview::create([
                'tenant_id' => $p->tenant_id,
                'participant_id' => $p->id,
                'active_med_count_at_queue' => $count,
                'queued_at' => now(),
            ]);
        }
    }
}

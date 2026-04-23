<?php

// ─── OutbreakDetectionJob ────────────────────────────────────────────────────
// Phase B2. Daily sweep: evaluates every tenant for (site, organism) clusters
// that meet the ≥3-cases-in-7-days threshold. OutbreakDetectionService
// handles the actual cluster math + alert emission; this job is just the
// per-tenant driver.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\OutbreakDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OutbreakDetectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(OutbreakDetectionService $svc): void
    {
        foreach (Tenant::query()->get(['id']) as $tenant) {
            $svc->evaluateTenant((int) $tenant->id);
        }
    }
}

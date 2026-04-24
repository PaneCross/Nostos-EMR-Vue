<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\QualityMeasureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class QualityMeasureSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function handle(QualityMeasureService $svc): void
    {
        foreach (Tenant::query()->get(['id']) as $t) {
            $svc->computeAll((int) $t->id);
        }
    }
}

<?php

// ─── DetectBcmaOverridePatternJob ────────────────────────────────────────────
// Daily sweep — finds nurses with concerning BCMA mismatch override frequency.
// Threshold + window tunable via Org Settings:
//   designation.nursing_director.bcma_override_pattern.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Services\PatternDetectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DetectBcmaOverridePatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public function backoff(): array { return [60, 180, 360]; }

    public function handle(PatternDetectionService $svc): void
    {
        $svc->runForKey(
            key:           'designation.nursing_director.bcma_override_pattern',
            designation:   'nursing_director',
            alertType:     'pattern_bcma_override',
            sourceModule:  'emar',
            targetDepts:   ['home_care'],
            countQuery: function (int $tenantId, Carbon $since): array {
                $rows = DB::table('emr_emar_records')
                    ->where('tenant_id', $tenantId)
                    ->whereNotNull('barcode_mismatch_overridden_by_user_id')
                    ->where('updated_at', '>=', $since)
                    ->selectRaw('barcode_mismatch_overridden_by_user_id as actor_id, COUNT(*) as cnt')
                    ->groupBy('barcode_mismatch_overridden_by_user_id')
                    ->get();
                return $rows->map(fn ($r) => [
                    'actor_id' => (int) $r->actor_id,
                    'count'    => (int) $r->cnt,
                    'metadata' => ['source' => 'bcma_override_pattern'],
                ])->all();
            },
            messageBuilder: function ($row, $count, $window, $actor, $recipient) {
                return "{$actor->first_name} {$actor->last_name} performed {$row['count']} BCMA mismatch overrides in the last {$window} days (your threshold: {$count}).";
            },
        );
    }
}

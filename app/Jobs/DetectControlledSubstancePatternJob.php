<?php

// ─── DetectControlledSubstancePatternJob ─────────────────────────────────────
// Daily sweep — flags prescribers writing concerning numbers of controlled-
// substance prescriptions within the org-tunable window.
// Threshold + window tunable via Org Settings:
//   designation.pharmacy_director.controlled_substance_pattern.
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

class DetectControlledSubstancePatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public function backoff(): array { return [60, 180, 360]; }

    public function handle(PatternDetectionService $svc): void
    {
        $svc->runForKey(
            key:           'designation.pharmacy_director.controlled_substance_pattern',
            designation:   'pharmacy_director',
            alertType:     'pattern_controlled_substance',
            sourceModule:  'pharmacy',
            targetDepts:   ['pharmacy'],
            countQuery: function (int $tenantId, Carbon $since): array {
                $rows = DB::table('emr_medications')
                    ->where('tenant_id', $tenantId)
                    ->where('is_controlled', true)
                    ->whereIn('controlled_schedule', ['II', 'III', 'IV', 'V'])
                    ->where('prescribed_date', '>=', $since)
                    ->whereNotNull('prescribing_provider_user_id')
                    ->selectRaw('prescribing_provider_user_id as actor_id, COUNT(*) as cnt')
                    ->groupBy('prescribing_provider_user_id')
                    ->get();
                return $rows->map(fn ($r) => [
                    'actor_id' => (int) $r->actor_id,
                    'count'    => (int) $r->cnt,
                    'metadata' => ['source' => 'controlled_substance_pattern'],
                ])->all();
            },
            messageBuilder: function ($row, $count, $window, $actor, $recipient) {
                return "{$actor->first_name} {$actor->last_name} prescribed {$row['count']} controlled-substance medications in the last {$window} days (your threshold: {$count}).";
            },
        );
    }
}

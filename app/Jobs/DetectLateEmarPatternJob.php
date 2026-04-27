<?php

// ─── DetectLateEmarPatternJob ────────────────────────────────────────────────
// Daily sweep : groups EMAR records by administering nurse, finds nurses who
// triggered a concerning number of LATE doses within the org-tunable window.
// Threshold + window are tenant-customizable via Org Settings:
//   designation.nursing_director.late_emar_pattern → kind=numeric_threshold.
//
// Schedule: daily 06:30 (see routes/console.php).
// Dedupe: PatternDetectionService skips actors already alerted in the window.
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

class DetectLateEmarPatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public function backoff(): array { return [60, 180, 360]; }

    public function handle(PatternDetectionService $svc): void
    {
        $svc->runForKey(
            key:           'designation.nursing_director.late_emar_pattern',
            designation:   'nursing_director',
            alertType:     'pattern_late_emar',
            sourceModule:  'emar',
            targetDepts:   ['home_care'],
            countQuery: function (int $tenantId, Carbon $since): array {
                $rows = DB::table('emr_emar_records')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'late')
                    ->where('administered_at', '>=', $since)
                    ->whereNotNull('administered_by_user_id')
                    ->selectRaw('administered_by_user_id as actor_id, COUNT(*) as cnt')
                    ->groupBy('administered_by_user_id')
                    ->get();
                return $rows->map(fn ($r) => [
                    'actor_id' => (int) $r->actor_id,
                    'count'    => (int) $r->cnt,
                    'metadata' => ['source' => 'late_emar_pattern'],
                ])->all();
            },
            messageBuilder: function ($row, $count, $window, $actor, $recipient) {
                return "{$actor->first_name} {$actor->last_name} has {$row['count']} late medication doses in the last {$window} days (your threshold: {$count}).";
            },
        );
    }
}

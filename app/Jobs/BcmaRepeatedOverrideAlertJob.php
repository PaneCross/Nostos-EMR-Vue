<?php

// ─── BcmaRepeatedOverrideAlertJob ────────────────────────────────────────────
// Phase B4. Daily sweep. When a single user overrides 3+ BCMA mismatches
// within 7 days, emit a warning alert to qa_compliance + pharmacy + executive.
// Individual overrides already alert in real time inside BcmaService; this job
// catches the aggregate pattern that indicates training or hardware issues.
//
// Dedup: one alert per (tenant, user) per 7 days via metadata->>'user_id'.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\EmarRecord;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class BcmaRepeatedOverrideAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const THRESHOLD_COUNT = 3;
    public const WINDOW_DAYS     = 7;
    public int $tries = 3;

    public function handle(AlertService $alerts): void
    {
        $since = now()->subDays(self::WINDOW_DAYS);

        $rows = EmarRecord::query()
            ->whereNotNull('barcode_mismatch_overridden_by_user_id')
            ->where('barcode_scanned_participant_at', '>=', $since)
            ->select('tenant_id', 'barcode_mismatch_overridden_by_user_id AS user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('tenant_id', 'barcode_mismatch_overridden_by_user_id')
            ->havingRaw('COUNT(*) >= ?', [self::THRESHOLD_COUNT])
            ->get();

        foreach ($rows as $row) {
            if ($this->alreadyAlerted((int) $row->user_id)) continue;
            $user = User::find($row->user_id);
            if (! $user) continue;

            $alerts->create([
                'tenant_id'          => $row->tenant_id,
                'source_module'      => 'emar',
                'alert_type'         => 'bcma_repeated_override',
                'severity'           => 'warning',
                'title'              => 'Repeated BCMA mismatch overrides detected',
                'message'            => "User {$user->first_name} {$user->last_name} has overridden "
                    . "{$row->cnt} BCMA mismatches in the last " . self::WINDOW_DAYS . ' days. '
                    . 'Review for training or scanner hardware issue.',
                'target_departments' => ['qa_compliance', 'pharmacy', 'executive'],
                'metadata'           => [
                    'user_id'           => (int) $row->user_id,
                    'override_count'    => (int) $row->cnt,
                    'window_days'       => self::WINDOW_DAYS,
                ],
            ]);
        }
    }

    private function alreadyAlerted(int $userId): bool
    {
        return Alert::where('alert_type', 'bcma_repeated_override')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereRaw("(metadata->>'user_id')::int = ?", [$userId])
            ->exists();
    }
}

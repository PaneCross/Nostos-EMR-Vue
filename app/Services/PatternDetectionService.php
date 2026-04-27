<?php

// ─── PatternDetectionService ─────────────────────────────────────────────────
// Shared helper for "actor X did N events in M days" pattern alerts.
//
// PLAIN-ENGLISH PURPOSE: Several Org Settings preferences want an alert
// when the same nurse / prescriber repeatedly triggers a concerning event
// (late EMAR doses, BCMA overrides, controlled-substance Rx). The shape
// is identical for each: GROUP BY actor in a time window, find actors
// at-or-above the threshold, dedupe against alerts already created today,
// fire one alert per actor.
//
// The 4 pattern-detector jobs in `app/Jobs/` consume this service:
//   - DetectLateEmarPatternJob
//   - DetectBcmaOverridePatternJob
//   - DetectControlledSubstancePatternJob
//   - DetectUnackedCriticalValueJob (degenerate case : count=1, window=hours)
//
// Each job:
//   1. Reads the org-level threshold via thresholdValue() : events_count + window_days
//   2. Builds a GROUP BY actor query over the right base table
//   3. Calls `dispatchPatternAlerts()` to fan out alerts + dedupe
//
// Service consumes the org-level preference (no per-site cascade for
// pattern jobs : cron runs tenant-wide). Future enhancement: per-site
// pattern detection if customer demand surfaces.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\Alert;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PatternDetectionService
{
    public function __construct(
        private readonly NotificationPreferenceService $prefs,
    ) {}

    /**
     * Run a pattern detection sweep across all tenants for one preference key.
     *
     * @param string         $key             Preference key (must be KIND_NUMERIC_THRESHOLD)
     * @param string         $designation     Recipient designation slug (e.g. 'nursing_director')
     * @param string         $alertType       Alert.alert_type for dedupe + UI grouping
     * @param string         $sourceModule    Alert.source_module (used by dashboards)
     * @param array          $targetDepts     Alert.target_departments
     * @param callable       $countQuery      function(int $tenantId, Carbon $since): array
     *                                          → [['actor_id' => int, 'count' => int, 'metadata' => array], ...]
     * @param callable|null  $messageBuilder  function(array $row, int $threshold, int $window, User $actor, User $recipient): string
     */
    public function runForKey(
        string $key,
        string $designation,
        string $alertType,
        string $sourceModule,
        array $targetDepts,
        callable $countQuery,
        ?callable $messageBuilder = null,
    ): int {
        $created = 0;
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            // Skip tenants that haven't enabled this preference at the org level.
            if (! $this->prefs->shouldNotify((int) $tenantId, $key)) {
                continue;
            }
            $threshold = $this->prefs->thresholdValue((int) $tenantId, $key);
            if (! $threshold) continue;

            $events_count = (int) $threshold['events_count'];
            $window_days  = (int) $threshold['window_days'];
            $since = Carbon::now()->subDays($window_days);

            $rows = $countQuery((int) $tenantId, $since);
            foreach ($rows as $row) {
                if (($row['count'] ?? 0) < $events_count) continue;

                // Find recipient
                $recipient = User::where('tenant_id', $tenantId)
                    ->withDesignation($designation)
                    ->where('is_active', true)
                    ->first();
                if (! $recipient) continue;

                // Dedupe: don't re-create the same actor's alert within the window.
                $existing = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', $alertType)
                    ->where('created_at', '>=', $since)
                    ->whereJsonContains('metadata->actor_user_id', (int) $row['actor_id'])
                    ->exists();
                if ($existing) continue;

                $actor = User::find($row['actor_id']);
                if (! $actor) continue;

                $message = $messageBuilder
                    ? $messageBuilder($row, $events_count, $window_days, $actor, $recipient)
                    : "{$actor->first_name} {$actor->last_name} triggered {$row['count']} events in the last {$window_days} days (threshold: {$events_count}).";

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'source_module'      => $sourceModule,
                    'alert_type'         => $alertType,
                    'severity'           => 'warning',
                    'title'              => "Pattern alert ({$designation})",
                    'message'            => $message,
                    'target_departments' => $targetDepts,
                    'created_by_system'  => true,
                    'metadata'           => array_merge($row['metadata'] ?? [], [
                        'actor_user_id'      => (int) $row['actor_id'],
                        'event_count'        => (int) $row['count'],
                        'threshold_count'    => $events_count,
                        'window_days'        => $window_days,
                        'recipient_user_id'  => $recipient->id,
                    ]),
                ]);
                $created++;
            }
        }

        return $created;
    }
}

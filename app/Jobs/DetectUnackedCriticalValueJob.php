<?php

// ─── DetectUnackedCriticalValueJob ───────────────────────────────────────────
// Hourly sweep — escalates critical lab values that haven't been acknowledged
// within the tenant-tunable hour window. Re-uses the threshold infrastructure
// (events_count is unused here; window_days is repurposed as window_hours).
//
// Preference key:
//   designation.nursing_director.critical_value_unacked
//   threshold_default_count = 1, threshold_default_window = 4 (hours).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DetectUnackedCriticalValueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public function backoff(): array { return [60, 180, 360]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'designation.nursing_director.critical_value_unacked';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;
            $threshold = $prefs->thresholdValue((int) $tenantId, $key);
            if (! $threshold) continue;

            // window_days is repurposed as hours for this preference (per catalog
            // entry's threshold_event_unit hint). Convert to a Carbon cutoff.
            $hours = (int) $threshold['window_days'];
            $cutoff = Carbon::now()->subHours($hours);

            $unacked = DB::table('emr_critical_value_acknowledgments')
                ->where('tenant_id', $tenantId)
                ->whereNull('acknowledged_at')
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($unacked as $cv) {
                // Dedupe — skip if we've already escalated this acknowledgment row
                $exists = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', 'critical_value_unacked_escalation')
                    ->whereJsonContains('metadata->ack_id', (int) $cv->id)
                    ->exists();
                if ($exists) continue;

                $director = User::where('tenant_id', $tenantId)
                    ->withDesignation('nursing_director')->where('is_active', true)->first();
                if (! $director) continue;

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'participant_id'     => $cv->participant_id ?? null,
                    'source_module'      => 'lab',
                    'alert_type'         => 'critical_value_unacked_escalation',
                    'severity'           => 'critical',
                    'title'              => 'Critical lab value unacknowledged',
                    'message'            => "Critical lab value pending >= {$hours}h without acknowledgment. Nursing oversight escalation.",
                    'target_departments' => ['home_care'],
                    'created_by_system'  => true,
                    'metadata'           => [
                        'ack_id'              => (int) $cv->id,
                        'window_hours'        => $hours,
                        'nursing_director_id' => $director->id,
                    ],
                ]);
            }
        }
    }
}

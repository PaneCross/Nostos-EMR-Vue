<?php

// ─── AdvDirectiveRenewalWarningJob ───────────────────────────────────────────
// Daily : finds advance directives with renewal_date approaching within the
// org-tunable window (numericValue from preference). Routes to Social Work.
//
// Preference: workflow.advance_directive.renewal_warning_days
//   kind=numeric; default 60 days. Each org chooses its lead-time.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AdvDirectiveRenewalWarningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;
    public function backoff(): array { return [60, 300]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'workflow.advance_directive.renewal_warning_days';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;

            $days = $prefs->numericValue((int) $tenantId, $key);
            if (! $days) continue;

            // Defensive: if the schema doesn't have advance_directive_renewal_date
            // on participants, skip this tenant gracefully.
            if (! \Illuminate\Support\Facades\Schema::hasColumn('emr_participants', 'advance_directive_renewal_date')) {
                continue;
            }

            $cutoffDate = now()->addDays($days)->toDateString();

            $candidates = Participant::where('tenant_id', $tenantId)
                ->where('enrollment_status', 'enrolled')
                ->whereNotNull('advance_directive_renewal_date')
                ->where('advance_directive_renewal_date', '<=', $cutoffDate)
                ->where('advance_directive_renewal_date', '>=', now()->toDateString())
                ->get(['id', 'first_name', 'last_name', 'mrn', 'advance_directive_renewal_date']);

            foreach ($candidates as $p) {
                $exists = Alert::where('tenant_id', $tenantId)
                    ->where('participant_id', $p->id)
                    ->where('alert_type', 'adv_directive_renewal_warning')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                if ($exists) continue;

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'participant_id'     => $p->id,
                    'source_module'      => 'advance_directive',
                    'alert_type'         => 'adv_directive_renewal_warning',
                    'severity'           => 'info',
                    'title'              => 'Advance directive renewal approaching',
                    'message'            => "{$p->first_name} {$p->last_name}'s advance directive renewal is on " . optional($p->advance_directive_renewal_date)->toDateString() . " (within your {$days}-day warning window).",
                    'target_departments' => ['social_work'],
                    'created_by_system'  => true,
                    'metadata'           => [
                        'window_days' => $days,
                    ],
                ]);
            }
        }
    }
}

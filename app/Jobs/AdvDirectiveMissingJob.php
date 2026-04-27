<?php

// ─── AdvDirectiveMissingJob ──────────────────────────────────────────────────
// Daily : flags participants enrolled 30+ days without a DPOA or advance
// directive on file. Routes to Social Work Supervisor.
//
// Preference: designation.social_work_supervisor.adv_directive_missing_at_admit
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class AdvDirectiveMissingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;
    public function backoff(): array { return [60, 300]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'designation.social_work_supervisor.adv_directive_missing_at_admit';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;

            $cutoff = now()->subDays(30)->toDateString();

            // Find enrolled participants past the 30-day mark with no advance
            // directive document. We look for any document tagged as
            // 'advance_directive' or 'dpoa' / 'molst' / 'polst' on the participant.
            $candidates = Participant::where('tenant_id', $tenantId)
                ->where('enrollment_status', 'enrolled')
                ->where('enrollment_date', '<=', $cutoff)
                ->whereDoesntHave('documents', function ($q) {
                    $q->whereIn('document_category', ['advance_directive', 'dpoa', 'molst', 'polst']);
                })
                ->get(['id', 'first_name', 'last_name', 'mrn']);

            foreach ($candidates as $p) {
                // Dedupe : at most one alert per participant per week
                $exists = Alert::where('tenant_id', $tenantId)
                    ->where('participant_id', $p->id)
                    ->where('alert_type', 'adv_directive_missing')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                if ($exists) continue;

                $supervisor = User::where('tenant_id', $tenantId)
                    ->withDesignation('social_work_supervisor')->where('is_active', true)->first();
                if (! $supervisor) continue;

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'participant_id'     => $p->id,
                    'source_module'      => 'social_work',
                    'alert_type'         => 'adv_directive_missing',
                    'severity'           => 'info',
                    'title'              => 'Advance directive missing 30+ days post-enrollment',
                    'message'            => "{$p->first_name} {$p->last_name} (MRN {$p->mrn}) is 30+ days post-enrollment with no advance directive on file.",
                    'target_departments' => ['social_work'],
                    'created_by_system'  => true,
                    'metadata'           => [
                        'social_work_supervisor_id' => $supervisor->id,
                    ],
                ]);
            }
        }
    }
}

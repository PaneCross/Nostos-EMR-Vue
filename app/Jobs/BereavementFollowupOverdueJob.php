<?php

// ─── BereavementFollowupOverdueJob ───────────────────────────────────────────
// Daily — finds bereavement family-contact follow-ups not made within 14 days
// of the bereavement event. Routes to Social Work Supervisor if opted in.
//
// Preference: designation.social_work_supervisor.bereavement_followup_missed.
// Note: schema may vary by tenant — the job uses defensive table existence
// checks since bereavement workflow shipped in Phase C3.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\User;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class BereavementFollowupOverdueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public function backoff(): array { return [60, 300]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'designation.social_work_supervisor.bereavement_followup_missed';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;

            // Defensive: skip if schema doesn't include bereavement table.
            // The Phase C3 schema landed under various table names depending on
            // environment; we look for the canonical one.
            if (! DB::getSchemaBuilder()->hasTable('emr_bereavement_followups')) continue;

            $cutoff = now()->subDays(14);
            $overdue = DB::table('emr_bereavement_followups')
                ->where('tenant_id', $tenantId)
                ->whereNull('contacted_at')
                ->where('opened_at', '<=', $cutoff)
                ->get(['id', 'participant_id']);

            foreach ($overdue as $row) {
                $exists = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', 'bereavement_followup_overdue')
                    ->whereJsonContains('metadata->followup_id', (int) $row->id)
                    ->whereDate('created_at', now()->toDateString())
                    ->exists();
                if ($exists) continue;

                $supervisor = User::where('tenant_id', $tenantId)
                    ->withDesignation('social_work_supervisor')->where('is_active', true)->first();
                if (! $supervisor) continue;

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'participant_id'     => $row->participant_id,
                    'source_module'      => 'social_work',
                    'alert_type'         => 'bereavement_followup_overdue',
                    'severity'           => 'warning',
                    'title'              => 'Bereavement follow-up overdue',
                    'message'            => "Bereavement family-contact has been pending >14 days. Outreach overdue.",
                    'target_departments' => ['social_work'],
                    'created_by_system'  => true,
                    'metadata'           => [
                        'followup_id'                => (int) $row->id,
                        'social_work_supervisor_id'  => $supervisor->id,
                    ],
                ]);
            }
        }
    }
}

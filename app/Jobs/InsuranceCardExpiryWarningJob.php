<?php

// ─── InsuranceCardExpiryWarningJob ───────────────────────────────────────────
// Daily — finds insurance coverage records with termination_date approaching
// within the org-tunable window. Routes to Finance.
//
// Preference: workflow.insurance_card.expiry_warning
//   kind=numeric; default 30 days. Each org chooses its lead-time.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\InsuranceCoverage;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class InsuranceCardExpiryWarningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;
    public function backoff(): array { return [60, 300]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'workflow.insurance_card.expiry_warning';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;

            $days = $prefs->numericValue((int) $tenantId, $key);
            if (! $days) continue;

            $cutoffDate = now()->addDays($days)->toDateString();

            $rows = InsuranceCoverage::query()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('termination_date')
                ->where('termination_date', '<=', $cutoffDate)
                ->where('termination_date', '>=', now()->toDateString())
                ->with('participant:id,first_name,last_name,mrn')
                ->get();

            foreach ($rows as $cov) {
                $exists = Alert::where('tenant_id', $tenantId)
                    ->where('alert_type', 'insurance_card_expiry_warning')
                    ->whereJsonContains('metadata->coverage_id', (int) $cov->id)
                    ->where('created_at', '>=', now()->subDays(7))
                    ->exists();
                if ($exists) continue;

                $name = $cov->participant
                    ? $cov->participant->first_name . ' ' . $cov->participant->last_name
                    : 'A participant';

                Alert::create([
                    'tenant_id'          => (int) $tenantId,
                    'participant_id'     => $cov->participant_id,
                    'source_module'      => 'finance',
                    'alert_type'         => 'insurance_card_expiry_warning',
                    'severity'           => 'info',
                    'title'              => 'Insurance card approaching expiration',
                    'message'            => "{$name}'s insurance coverage ends " . $cov->termination_date->toDateString() . " (within your {$days}-day warning window). Plan renewal/replacement.",
                    'target_departments' => ['finance', 'enrollment'],
                    'created_by_system'  => true,
                    'metadata'           => [
                        'coverage_id' => (int) $cov->id,
                        'window_days' => $days,
                    ],
                ]);
            }
        }
    }
}

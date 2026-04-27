<?php

// ─── PriorAuthQueueDigestJob ─────────────────────────────────────────────────
// Daily — finds prior-auth requests pending >3 days; sends a single digest
// alert per tenant to the Pharmacy Director if the org opted in.
//
// Preference: designation.pharmacy_director.prior_auth_queue_oversight
// Threshold: hardcoded at >3 days (could become tunable later).
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

class PriorAuthQueueDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
    public function backoff(): array { return [60, 300]; }

    public function handle(NotificationPreferenceService $prefs): void
    {
        $key = 'designation.pharmacy_director.prior_auth_queue_oversight';
        $tenants = DB::table('shared_tenants')->where('is_active', true)->pluck('id');

        foreach ($tenants as $tenantId) {
            if (! $prefs->shouldNotify((int) $tenantId, $key)) continue;

            // Schema check: skip if the prior_auth table doesn't exist in this env yet
            if (! DB::getSchemaBuilder()->hasTable('emr_prior_auth_requests')) continue;

            $cutoff = now()->subDays(3);
            $pending = DB::table('emr_prior_auth_requests')
                ->where('tenant_id', $tenantId)
                ->whereIn('status', ['draft', 'submitted'])
                ->where('created_at', '<=', $cutoff)
                ->count();
            if ($pending === 0) continue;

            // Dedupe: one digest per day per tenant.
            $existing = Alert::where('tenant_id', $tenantId)
                ->where('alert_type', 'prior_auth_queue_digest')
                ->whereDate('created_at', now()->toDateString())
                ->exists();
            if ($existing) continue;

            $director = User::where('tenant_id', $tenantId)
                ->withDesignation('pharmacy_director')->where('is_active', true)->first();
            if (! $director) continue;

            Alert::create([
                'tenant_id'          => (int) $tenantId,
                'source_module'      => 'pharmacy',
                'alert_type'         => 'prior_auth_queue_digest',
                'severity'           => 'warning',
                'title'              => "Prior-auth queue: {$pending} pending >3 days",
                'message'            => "{$pending} prior-auth requests have been pending more than 3 days.",
                'target_departments' => ['pharmacy'],
                'created_by_system'  => true,
                'metadata'           => [
                    'pending_count'        => $pending,
                    'pharmacy_director_id' => $director->id,
                ],
            ]);
        }
    }
}

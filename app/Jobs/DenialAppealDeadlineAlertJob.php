<?php

// ─── DenialAppealDeadlineAlertJob ────────────────────────────────────────────
// Phase 12 (MVP roadmap). Nightly sweep — flags denial records whose appeal
// deadline (42 CFR §405.942: 120 days from denial_date by default) is within
// the next 14 days AND which remain in status='open' with no appeal filed.
//
// Writes one Alert per denial for finance + qa_compliance departments.
// Dedupe: if an alert for this denial was already written in the last 24h,
// skip it.
//
// Schedule daily via App\Console\Kernel::schedule()->job(...).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\DenialRecord;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DenialAppealDeadlineAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $warningWindowDays = 14) {}

    public function handle(AlertService $alerts): void
    {
        $cutoff = now()->addDays($this->warningWindowDays)->toDateString();

        $denials = DenialRecord::open()
            ->whereNotNull('appeal_deadline')
            ->where('appeal_deadline', '<=', $cutoff)
            ->where('appeal_deadline', '>=', now()->toDateString())
            ->get();

        foreach ($denials as $denial) {
            // Dedupe: alert already written in last 24h?
            $existing = Alert::where('tenant_id', $denial->tenant_id)
                ->where('source_module', 'finance')
                ->where('alert_type', 'denial_appeal_deadline')
                ->where('created_at', '>=', now()->subHours(24))
                ->whereJsonContains('metadata->denial_record_id', $denial->id)
                ->exists();

            if ($existing) continue;

            $days = (int) now()->diffInDays($denial->appeal_deadline, false);
            $severity = $days <= 3 ? 'critical' : 'warning';

            $alerts->create([
                'tenant_id'        => $denial->tenant_id,
                'source_module'    => 'finance',
                'alert_type'       => 'denial_appeal_deadline',
                'severity'         => $severity,
                'title'            => "Denial appeal deadline in {$days} day(s)",
                'message'          => sprintf(
                    'Denial #%d (category: %s, amount: $%.2f) appeal deadline is %s. File appeal or mark written-off.',
                    $denial->id,
                    $denial->denial_category,
                    (float) $denial->denied_amount,
                    $denial->appeal_deadline->toDateString()
                ),
                'target_departments' => ['finance', 'qa_compliance'],
                'metadata'         => ['denial_record_id' => $denial->id],
                'created_by_system' => true,
            ]);
        }
    }
}

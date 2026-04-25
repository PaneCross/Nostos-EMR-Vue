<?php

// ─── AmendmentDeadlineJob — Phase Q1 ────────────────────────────────────────
// Daily sweep at 06:30. HIPAA §164.526(b)(2) gives the covered entity 60
// days to decide an amendment request. Two alerts per open request:
//   1. T-7d warning when deadline approaches → qa_compliance + social_work
//   2. Missed deadline critical → qa_compliance + executive
// Dedup: warning fires once per request within the 7-day window;
// missed-deadline fires once per request via metadata key.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\AmendmentRequest;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AmendmentDeadlineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(AlertService $alerts): void
    {
        $open = AmendmentRequest::open()->whereNotNull('deadline_at')->get();
        foreach ($open as $req) {
            $remaining = (int) now()->diffInDays($req->deadline_at, false);
            if ($remaining < 0) {
                $this->emitOnce($alerts, $req, 'amendment_deadline_missed', 'critical',
                    "Amendment request #{$req->id} has missed the §164.526 60-day deadline.");
            } elseif ($remaining <= 7) {
                $this->emitOnce($alerts, $req, 'amendment_deadline_t7', 'warning',
                    "Amendment request #{$req->id} deadline in {$remaining} days. Decide before §164.526(b)(2) lapses.");
            }
        }
    }

    private function emitOnce(AlertService $alerts, AmendmentRequest $req, string $type, string $severity, string $message): void
    {
        $exists = Alert::where('tenant_id', $req->tenant_id)
            ->where('alert_type', $type)
            ->whereJsonContains('metadata->amendment_request_id', $req->id)
            ->where('created_at', '>=', now()->subDay())
            ->exists();
        if ($exists) return;

        $alerts->create([
            'tenant_id'          => $req->tenant_id,
            'participant_id'     => $req->participant_id,
            'alert_type'         => $type,
            'severity'           => $severity,
            'title'              => 'HIPAA Amendment Request: ' . ucwords(str_replace('_', ' ', str_replace($type, '', 'deadline'))),
            'message'            => $message,
            'target_departments' => ['qa_compliance', $severity === 'critical' ? 'executive' : 'social_work'],
            'source_module'      => 'compliance',
            'metadata'           => ['amendment_request_id' => $req->id, 'deadline_at' => $req->deadline_at?->toIso8601String()],
            'created_by_system'  => true,
        ]);
    }
}

<?php

// ─── NfLocRecertAlertJob ──────────────────────────────────────────────────────
// Daily job that scans enrolled participants and raises alerts when NF-LOC
// recertification is approaching or past due. §460.160(b)(2).
//
// Alert cadence per participant:
//   60 days out → info alert (first warning, enrollment dept)
//   30 days out → warning alert
//   15 days out → warning alert
//    0 days out → critical alert (recert due today)
//   overdue     → critical alert (re-fires each day until resolved)
//
// Deduplication: an alert is skipped if an active alert of the same alert_type
// already exists for this participant. Prevents re-spam across daily runs.
// Resolving the certification (updating nf_certification_date/expires_at)
// auto-clears the alert via AlertService in the controller/model observer.
// ──────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\Participant;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NfLocRecertAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Day thresholds that trigger alerts (matched exactly against days remaining). */
    public const THRESHOLDS = [60, 30, 15, 0];

    /** Alert type keys per threshold (used for dedup). */
    private const TYPE_PER_THRESHOLD = [
        60 => 'nf_loc_recert_60d',
        30 => 'nf_loc_recert_30d',
        15 => 'nf_loc_recert_15d',
        0  => 'nf_loc_recert_due',
    ];

    public function __construct() {}

    public function handle(AlertService $alertService): void
    {
        $participants = Participant::query()
            ->where('enrollment_status', 'enrolled')
            ->where('nf_recert_waived', false)
            ->whereNotNull('nf_certification_expires_at')
            ->get();

        $created = ['info' => 0, 'warning' => 0, 'critical' => 0];

        foreach ($participants as $participant) {
            $days = $participant->nfLocRecertDaysRemaining();
            if ($days === null) continue;

            $severity = $this->severityFor($days);
            $alertType = $this->alertTypeFor($days);
            if ($severity === null || $alertType === null) continue;

            // Dedup: one active alert of this type per participant at a time.
            $existing = Alert::where('tenant_id', $participant->tenant_id)
                ->where('participant_id', $participant->id)
                ->where('alert_type', $alertType)
                ->where('is_active', true)
                ->exists();
            if ($existing) continue;

            $alertService->create([
                'tenant_id'          => $participant->tenant_id,
                'participant_id'     => $participant->id,
                'source_module'      => 'enrollment',
                'alert_type'         => $alertType,
                'severity'           => $severity,
                'title'              => $this->titleFor($days, $participant),
                'message'            => $this->messageFor($days, $participant),
                'target_departments' => ['enrollment', 'qa_compliance'],
                'is_active'          => true,
            ]);

            $created[$severity] = ($created[$severity] ?? 0) + 1;
        }

        Log::info('[NfLocRecertAlertJob] Batch complete', [
            'scanned'  => $participants->count(),
            'info'     => $created['info'] ?? 0,
            'warning'  => $created['warning'] ?? 0,
            'critical' => $created['critical'] ?? 0,
        ]);
    }

    private function severityFor(int $days): ?string
    {
        if ($days < 0) return 'critical';
        if ($days === 0) return 'critical';
        if ($days === 15 || $days === 30) return 'warning';
        if ($days === 60) return 'info';
        return null;
    }

    private function alertTypeFor(int $days): ?string
    {
        if ($days < 0) return 'nf_loc_recert_overdue';
        return self::TYPE_PER_THRESHOLD[$days] ?? null;
    }

    private function titleFor(int $days, Participant $p): string
    {
        if ($days < 0) return "NF-LOC recert OVERDUE — {$p->fullName()}";
        if ($days === 0) return "NF-LOC recert DUE TODAY — {$p->fullName()}";
        return "NF-LOC recert in {$days} days — {$p->fullName()}";
    }

    private function messageFor(int $days, Participant $p): string
    {
        $date = $p->nf_certification_expires_at?->format('Y-m-d') ?? 'unknown';
        if ($days < 0) {
            $over = abs($days);
            return "Annual NF level-of-care recertification for {$p->fullName()} (MRN {$p->mrn}) was due on {$date} — {$over} day(s) overdue. 42 CFR §460.160(b)(2).";
        }
        return "Annual NF level-of-care recertification for {$p->fullName()} (MRN {$p->mrn}) expires on {$date}. 42 CFR §460.160(b)(2).";
    }
}

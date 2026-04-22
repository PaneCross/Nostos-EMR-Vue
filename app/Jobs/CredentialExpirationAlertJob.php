<?php

// ─── CredentialExpirationAlertJob ─────────────────────────────────────────────
// Daily scan of staff credentials. Creates alerts at 60 / 30 / 14 / 0 / overdue.
// Dedup: one active alert per (credential_id, alert_type) at a time.
// Target dept: it_admin + qa_compliance (compliance owner per §460.71).
//
// Phase 4 (MVP roadmap).
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Models\Alert;
use App\Models\StaffCredential;
use App\Services\AlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CredentialExpirationAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const TYPE_PER_DAYS = [
        60 => 'staff_credential_60d',
        30 => 'staff_credential_30d',
        14 => 'staff_credential_14d',
        0  => 'staff_credential_due',
    ];

    public function handle(AlertService $alertService): void
    {
        $credentials = StaffCredential::whereNotNull('expires_at')
            ->with('user:id,first_name,last_name,department,tenant_id')
            ->get();

        $created = ['info' => 0, 'warning' => 0, 'critical' => 0];

        foreach ($credentials as $credential) {
            $days = $credential->daysUntilExpiration();
            if ($days === null) continue;

            $severity  = $this->severityFor($days);
            $alertType = $this->alertTypeFor($days);
            if ($severity === null || $alertType === null) continue;

            // Dedup: one active alert of this (alert_type, credential) at a time.
            $existing = Alert::where('tenant_id', $credential->tenant_id)
                ->where('alert_type', $alertType)
                ->where('is_active', true)
                ->whereJsonContains('metadata->staff_credential_id', $credential->id)
                ->exists();
            if ($existing) continue;

            $u = $credential->user;
            if (! $u) continue;

            $label = "{$credential->title} ({$u->first_name} {$u->last_name})";

            $alertService->create([
                'tenant_id'          => $credential->tenant_id,
                'source_module'      => 'it_admin',
                'alert_type'         => $alertType,
                'severity'           => $severity,
                'title'              => $this->titleFor($days, $label),
                'message'            => $this->messageFor($days, $credential, $u),
                'target_departments' => ['it_admin', 'qa_compliance'],
                'is_active'          => true,
                'metadata'           => ['staff_credential_id' => $credential->id, 'user_id' => $u->id],
            ]);

            $created[$severity] = ($created[$severity] ?? 0) + 1;
        }

        Log::info('[CredentialExpirationAlertJob] Batch complete', [
            'scanned'  => $credentials->count(),
            'info'     => $created['info']     ?? 0,
            'warning'  => $created['warning']  ?? 0,
            'critical' => $created['critical'] ?? 0,
        ]);
    }

    private function severityFor(int $days): ?string
    {
        if ($days < 0 || $days === 0) return 'critical';
        if ($days === 14 || $days === 30) return 'warning';
        if ($days === 60) return 'info';
        return null;
    }

    private function alertTypeFor(int $days): ?string
    {
        if ($days < 0) return 'staff_credential_expired';
        return self::TYPE_PER_DAYS[$days] ?? null;
    }

    private function titleFor(int $days, string $label): string
    {
        if ($days < 0)  return "Staff credential EXPIRED — {$label}";
        if ($days === 0) return "Staff credential DUE TODAY — {$label}";
        return "Staff credential expires in {$days} days — {$label}";
    }

    private function messageFor(int $days, StaffCredential $c, $u): string
    {
        $when = $c->expires_at?->format('Y-m-d') ?? 'unknown';
        $who  = "{$u->first_name} {$u->last_name} ({$u->department})";
        if ($days < 0) {
            return "Credential '{$c->title}' for {$who} expired {$when}. §460.71 requires current credentials for all direct-care staff.";
        }
        return "Credential '{$c->title}' for {$who} expires {$when}. §460.71.";
    }
}

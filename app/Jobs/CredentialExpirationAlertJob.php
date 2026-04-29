<?php

// ─── CredentialExpirationAlertJob ─────────────────────────────────────────────
// Daily scan of staff credentials. Layered escalation per cadence step:
//
//   90 / 60 / 30 days out  → email user (gated by 'credential_self_reminder')
//   14 days out            → email user + their supervisor (also gated)
//   0 / overdue            → email user + supervisor + QA Compliance dept
//                            (the QA dept alert is REQUIRED, cannot opt out)
//
// Per-definition cadence : if the credential is linked to a definition with a
// custom reminder_cadence_days array, only those steps fire. Free-form (no
// definition) credentials fall back to the default [90, 30, 14, 0].
//
// Dedup: one active alert per (credential_id, alert_type) per cadence step.
//
// Phase 4 originally; rewritten in Credentials V1 for layered escalation.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Jobs;

use App\Mail\CredentialExpiringMail;
use App\Models\Alert;
use App\Models\StaffCredential;
use App\Models\User;
use App\Services\AlertService;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CredentialExpirationAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Default cadence for free-form credentials (no definition_id). */
    public const DEFAULT_CADENCE = [90, 60, 30, 14, 0];

    /** Days at which we ALSO email the supervisor. */
    public const SUPERVISOR_CC_AT = [14, 0];

    /** Days at which we ALSO alert QA Compliance department. */
    public const QA_ESCALATE_AT = [0]; // and any negative value (overdue)

    public int $tries = 3;
    public int $timeout = 600;

    public function handle(
        AlertService $alertService,
        ?NotificationPreferenceService $prefService = null
    ): void {
        $prefService = $prefService ?? app(NotificationPreferenceService::class);

        // Filter to "tip of chain" rows : a credential that's been replaced by
        // a newer pending/active row (replaced_by_credential_id set) is audit
        // history only and must not generate fresh reminders. Without this,
        // users get spammed about credentials they already renewed.
        $credentials = StaffCredential::whereNotNull('expires_at')
            ->whereNull('deleted_at')
            ->whereNull('replaced_by_credential_id')
            ->with(['user', 'definition', 'user.supervisor'])
            ->get();

        $alertsCreated = 0;
        $emailsSent = 0;

        // G4 : batch emails per user. Build queues keyed by user_id (and a
        // separate one for supervisors), flush at the end as either a single
        // CredentialExpiringMail (1 item) or CredentialDigestMail (2+ items).
        $userQueue = [];        // [user_id => array of items]
        $supervisorQueue = [];  // [supervisor_id => array of items]

        foreach ($credentials as $credential) {
            $days = $credential->daysUntilExpiration();
            if ($days === null) continue;
            if ($credential->isInvalidStatus()) continue; // suspended/revoked has its own flow

            $cadence = $credential->definition?->effectiveCadence() ?? self::DEFAULT_CADENCE;
            $cadenceStep = $this->matchedCadenceStep($days, $cadence);
            if ($cadenceStep === null) continue;

            $u = $credential->user;
            if (! $u) continue;

            $alertType = $this->alertTypeFor($cadenceStep);

            // Dedup : skip if alert for this (credential, type) already active
            $existing = Alert::where('tenant_id', $credential->tenant_id)
                ->where('alert_type', $alertType)
                ->where('is_active', true)
                ->whereJsonContains('metadata->staff_credential_id', $credential->id)
                ->exists();
            if ($existing) continue;

            // G4 : queue emails instead of sending immediately so we can batch
            // multiple items per user into a single digest at the end.
            if ($this->shouldEmailUser($prefService, $u, $credential)) {
                $userQueue[$u->id] ??= ['user' => $u, 'items' => []];
                $userQueue[$u->id]['items'][] = ['credential' => $credential, 'days_remaining' => $days, 'is_supervisor_copy' => false];
            }

            if (in_array($cadenceStep, self::SUPERVISOR_CC_AT, true) || $days < 0) {
                if ($u->supervisor && $this->shouldEmailSupervisor($prefService, $u->supervisor, $credential)) {
                    $supId = $u->supervisor->id;
                    $supervisorQueue[$supId] ??= ['user' => $u->supervisor, 'items' => []];
                    $supervisorQueue[$supId]['items'][] = ['credential' => $credential, 'days_remaining' => $days, 'is_supervisor_copy' => true];
                }
            }

            // ── QA Compliance dept-level alert (in-app feed) at 0 / overdue
            $targetDepts = ['it_admin'];
            if (in_array($cadenceStep, self::QA_ESCALATE_AT, true) || $days < 0) {
                $targetDepts[] = 'qa_compliance';
            }

            $alertService->create([
                'tenant_id'          => $credential->tenant_id,
                'source_module'      => 'it_admin',
                'alert_type'         => $alertType,
                'severity'           => $this->severityFor($days),
                'title'              => $this->titleFor($days, $credential, $u),
                'message'            => $this->messageFor($days, $credential, $u),
                'target_departments' => $targetDepts,
                'is_active'          => true,
                'metadata'           => [
                    'staff_credential_id' => $credential->id,
                    'user_id'             => $u->id,
                    'cadence_step'        => $cadenceStep,
                ],
            ]);

            $alertsCreated++;
        }

        // G4 : flush queued emails. Single-item users get the original
        // CredentialExpiringMail ; multi-item users get the new digest.
        foreach ([$userQueue, $supervisorQueue] as $queue) {
            foreach ($queue as $entry) {
                $items = $entry['items'];
                $recipient = $entry['user'];
                try {
                    if (count($items) === 1) {
                        $i = $items[0];
                        Mail::to($recipient->email)->queue(
                            new CredentialExpiringMail($recipient, $i['credential'], $i['days_remaining'], $i['is_supervisor_copy'])
                        );
                    } else {
                        Mail::to($recipient->email)->queue(
                            new \App\Mail\CredentialDigestMail($recipient, $items)
                        );
                    }
                    $emailsSent++;
                } catch (\Throwable $e) {
                    Log::warning('[CredentialExpirationAlertJob] flush mail failed', [
                        'user_id' => $recipient->id, 'item_count' => count($items), 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('[CredentialExpirationAlertJob] Batch complete', [
            'scanned'        => $credentials->count(),
            'alerts_created' => $alertsCreated,
            'emails_sent'    => $emailsSent,
        ]);
    }

    /** Find which cadence step (if any) the current days-out matches. */
    private function matchedCadenceStep(int $days, array $cadence): ?int
    {
        // Sort descending so we pick the highest matching step (e.g. day-of triggers
        // the 0 step, not -7).
        rsort($cadence);

        // Exact match only (fires once per step) : avoids daily duplicates.
        if (in_array($days, $cadence, true)) return $days;

        // For overdue, fire on the first negative cadence value reached, OR if no
        // negative steps configured, treat it as a synthetic overdue step (-1)
        // so we always re-alert daily on overdue credentials (deduped via Alert).
        if ($days < 0) {
            foreach ($cadence as $step) {
                if ($step < 0 && $days <= $step) return $step;
            }
            return -1; // synthetic overdue step
        }
        return null;
    }

    private function alertTypeFor(int $step): string
    {
        if ($step < 0)  return 'staff_credential_overdue';
        if ($step === 0) return 'staff_credential_due';
        return "staff_credential_{$step}d";
    }

    private function severityFor(int $days): string
    {
        if ($days < 0 || $days === 0) return 'critical';
        if ($days <= 14) return 'warning';
        return 'info';
    }

    private function titleFor(int $days, StaffCredential $c, User $u): string
    {
        $label = "{$c->title} ({$u->first_name} {$u->last_name})";
        if ($days < 0)  return "Staff credential OVERDUE : {$label}";
        if ($days === 0) return "Staff credential DUE TODAY : {$label}";
        return "Staff credential expires in {$days} days : {$label}";
    }

    private function messageFor(int $days, StaffCredential $c, User $u): string
    {
        $when = $c->expires_at?->format('Y-m-d') ?? 'unknown';
        $who  = "{$u->first_name} {$u->last_name} ({$u->department})";
        if ($days < 0) {
            $overdue = abs($days);
            return "Credential '{$c->title}' for {$who} expired {$when} ({$overdue}d overdue). §460.71 requires current credentials for all direct-care staff.";
        }
        return "Credential '{$c->title}' for {$who} expires {$when}. §460.71.";
    }

    /** Honor user prefs : default ON. */
    private function shouldEmailUser(NotificationPreferenceService $prefs, User $u, StaffCredential $c): bool
    {
        // Per-user preference would be tracked in shared_users.notification_preferences ;
        // for v1 we trust the org-level toggle on the catalog. NotificationPreferenceService
        // returns true by default if no row exists.
        return $prefs->shouldNotify($c->tenant_id, 'credential_self_reminder', $u->site_id) !== false;
    }

    /** Supervisor CC at 14 days + overdue. Default ON. */
    private function shouldEmailSupervisor(NotificationPreferenceService $prefs, User $supervisor, StaffCredential $c): bool
    {
        return $prefs->shouldNotify($c->tenant_id, 'credential_supervisor_cc_14d', $supervisor->site_id) !== false;
    }
}

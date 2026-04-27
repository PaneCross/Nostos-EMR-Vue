<?php

// ─── NotificationPreferenceService ────────────────────────────────────────────
// The single source of truth for "should this OPTIONAL notification fire for
// this tenant?". Drives the Org Settings page (/executive/org-settings) and
// is consumed by alert-dispatching jobs/services across the EMR.
//
// PLAIN-ENGLISH PURPOSE: Different PACE organizations want different people
// notified for different events. CMS dictates the minimum (Required keys);
// everything else is org preference. This service caches per-tenant settings
// in-request, returns sensible defaults when no row exists, and short-circuits
// to TRUE for hardwired Required keys regardless of stored state.
//
// Design + full preference catalog: docs/internal/org-settings-design.md
//
// Hot path: shouldNotify() is called from every alert dispatch site that
//   deals with an optional preference. Cache is per-tenant, in-request only.
// ─────────────────────────────────────────────────────────────────────────────

namespace App\Services;

use App\Models\AuditLog;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

class NotificationPreferenceService
{
    /**
     * Per-tenant in-request cache: tenant_id => [key => bool].
     * Cleared by clearCache() when settings are mutated.
     */
    private array $cache = [];

    // ── Status constants for the catalog ──────────────────────────────────────
    public const STATUS_REQUIRED = 'required'; // hardwired by CMS, locked-on in UI
    public const STATUS_OPTIONAL = 'optional'; // tenant choice; toggle works
    public const STATUS_RESERVED = 'reserved'; // planned; setting saves but no code wired yet

    // ── Hot-path query ────────────────────────────────────────────────────────

    /**
     * Should this preference fire for this tenant?
     *
     * Returns true unconditionally for Required keys (hardwired by CMS).
     * For Optional/Reserved keys, returns the stored value or the catalog
     * default if no row exists.
     */
    public function shouldNotify(int $tenantId, string $key): bool
    {
        $catalog = self::catalog();
        $entry = $catalog[$key] ?? null;

        // Required keys always fire — this is the regulatory floor.
        if ($entry && $entry['status'] === self::STATUS_REQUIRED) {
            return true;
        }

        // Look up tenant-cached value (one query per tenant per request).
        if (! isset($this->cache[$tenantId])) {
            $this->cache[$tenantId] = NotificationPreference::query()
                ->where('tenant_id', $tenantId)
                ->pluck('enabled', 'preference_key')
                ->map(fn ($v) => (bool) $v)
                ->all();
        }

        if (array_key_exists($key, $this->cache[$tenantId])) {
            return $this->cache[$tenantId][$key];
        }

        // No row → fall back to catalog default.
        return (bool) ($entry['default'] ?? false);
    }

    // ── Mutation ──────────────────────────────────────────────────────────────

    /**
     * Set a single preference. Records an AuditLog entry on every flip so the
     * IT Admin audit page shows "{key}: enabled by Brian" history.
     */
    public function set(int $tenantId, string $key, bool $enabled, int $byUserId): void
    {
        // Required keys cannot be flipped off — silently no-op the attempt.
        $entry = self::catalog()[$key] ?? null;
        if ($entry && $entry['status'] === self::STATUS_REQUIRED) {
            return;
        }

        $existing = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('preference_key', $key)
            ->first();

        $previous = $existing?->enabled;

        NotificationPreference::updateOrCreate(
            ['tenant_id' => $tenantId, 'preference_key' => $key],
            [
                'enabled'             => $enabled,
                'updated_by_user_id'  => $byUserId,
            ],
        );

        if ($previous !== $enabled) {
            AuditLog::record(
                action:       'org_settings.preference_changed',
                tenantId:     $tenantId,
                userId:       $byUserId,
                resourceType: 'NotificationPreference',
                description:  sprintf('%s → %s', $key, $enabled ? 'enabled' : 'disabled'),
                oldValues:    $previous === null ? null : ['enabled' => $previous],
                newValues:    ['enabled' => $enabled],
            );
        }

        $this->clearCache($tenantId);
    }

    /**
     * Bulk-update many preferences in a single transaction. Used by the Site
     * Settings page save button. Records one AuditLog row per actually-changed
     * preference so the audit trail is precise.
     */
    public function bulkSet(int $tenantId, array $changes, int $byUserId): int
    {
        $changed = 0;
        DB::transaction(function () use ($tenantId, $changes, $byUserId, &$changed) {
            foreach ($changes as $key => $enabled) {
                $entry = self::catalog()[$key] ?? null;
                if (! $entry) continue;                                 // unknown key — ignore
                if ($entry['status'] === self::STATUS_REQUIRED) continue; // locked, never write

                $existing = NotificationPreference::query()
                    ->where('tenant_id', $tenantId)
                    ->where('preference_key', $key)
                    ->first();
                $previous = $existing?->enabled;

                if ($previous === (bool) $enabled) continue;            // no-op

                NotificationPreference::updateOrCreate(
                    ['tenant_id' => $tenantId, 'preference_key' => $key],
                    ['enabled' => (bool) $enabled, 'updated_by_user_id' => $byUserId],
                );

                AuditLog::record(
                    action:       'org_settings.preference_changed',
                    tenantId:     $tenantId,
                    userId:       $byUserId,
                    resourceType: 'NotificationPreference',
                    description:  sprintf('%s → %s', $key, $enabled ? 'enabled' : 'disabled'),
                    oldValues:    $previous === null ? null : ['enabled' => $previous],
                    newValues:    ['enabled' => (bool) $enabled],
                );
                $changed++;
            }
        });

        $this->clearCache($tenantId);
        return $changed;
    }

    /**
     * Seed default rows for a freshly-onboarded tenant. Idempotent — only
     * inserts rows that don't already exist. Safe to call repeatedly.
     */
    public function seedDefaults(int $tenantId): int
    {
        $created = 0;
        foreach (self::catalog() as $key => $entry) {
            if ($entry['status'] === self::STATUS_REQUIRED) continue;  // never store required
            $exists = NotificationPreference::query()
                ->where('tenant_id', $tenantId)
                ->where('preference_key', $key)
                ->exists();
            if ($exists) continue;

            NotificationPreference::create([
                'tenant_id'      => $tenantId,
                'preference_key' => $key,
                'enabled'        => (bool) ($entry['default'] ?? false),
            ]);
            $created++;
        }
        $this->clearCache($tenantId);
        return $created;
    }

    public function clearCache(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$tenantId]);
        }
    }

    // ── Read API for the Settings page ────────────────────────────────────────

    /**
     * Effective state for every catalog key for a given tenant. Drives the
     * Org Settings page render.
     *
     * @return array<string, array{status:string, group:string, label:string, description:string, cms_ref:?string, default:bool, enabled:bool}>
     */
    public function effectiveSettingsForTenant(int $tenantId): array
    {
        $stored = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->pluck('enabled', 'preference_key')
            ->map(fn ($v) => (bool) $v)
            ->all();

        $out = [];
        foreach (self::catalog() as $key => $entry) {
            $enabled = $entry['status'] === self::STATUS_REQUIRED
                ? true
                : ($stored[$key] ?? (bool) ($entry['default'] ?? false));

            $out[$key] = array_merge($entry, ['enabled' => $enabled]);
        }
        return $out;
    }

    // ── The catalog ───────────────────────────────────────────────────────────

    /**
     * Canonical map of every preference the system knows about.
     *
     * Each entry: {
     *   group:       human grouping for the UI (Designation name or "Workflow")
     *   label:       short toggle label
     *   description: 1-2 sentences on what this controls in plain English
     *   status:      required | optional | reserved
     *   default:     boolean default if no row exists (ignored for required)
     *   cms_ref:     optional CFR citation (shown as a tooltip)
     *   wired:       true when alert code actually consults the preference;
     *                false when the key is reserved for future code
     * }
     *
     * MAINTENANCE CONTRACT: when a new alert path adopts the service, flip
     * `wired` to true and set `status` from reserved → optional (or required
     * if it's a CMS floor). When you add a brand-new preference, add it here
     * and re-run seedDefaults across tenants in a one-shot artisan command.
     */
    public static function catalog(): array
    {
        return [
            // ── Medical Director ──────────────────────────────────────────
            'designation.medical_director.restraint_observation_overdue' => [
                'group'       => 'Medical Director',
                'label'       => 'Restraint observation overdue (>4h)',
                'description' => 'Critical alert when a restraint episode goes more than 4 hours without an observation entry.',
                'status'      => self::STATUS_REQUIRED,
                'default'     => true,
                'cms_ref'     => '42 CFR §483.13 (patient safety)',
                'wired'       => true,
            ],
            'designation.medical_director.restraint_idt_review_overdue' => [
                'group'       => 'Medical Director',
                'label'       => 'Restraint IDT review overdue (>24h)',
                'description' => 'Critical alert when a restraint episode IDT review is more than 24 hours overdue.',
                'status'      => self::STATUS_REQUIRED,
                'default'     => true,
                'cms_ref'     => '42 CFR §483.13',
                'wired'       => true,
            ],
            'designation.medical_director.appeal_decision_required' => [
                'group'       => 'Medical Director',
                'label'       => 'Appeal awaiting decision',
                'description' => 'Notify when a §460.122 appeal is filed and pending a decide() call. In addition to the QA Compliance dept-broadcast.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Compliance Officer ────────────────────────────────────────
            'designation.compliance_officer.urgent_grievance_filed' => [
                'group'       => 'Compliance Officer',
                'label'       => 'Urgent grievance filed (72h CMS clock)',
                'description' => 'Named on every urgent grievance the moment it is filed. CMS §460.120(c) drives the 72h resolution clock.',
                'status'      => self::STATUS_REQUIRED,
                'default'     => true,
                'cms_ref'     => '42 CFR §460.120(c)',
                'wired'       => true,
            ],
            'designation.compliance_officer.grievance_escalated' => [
                'group'       => 'Compliance Officer',
                'label'       => 'Grievance escalated (auto-assignee fallback)',
                'description' => 'Auto-assigned as the escalation reviewer when a grievance is escalated without a specific reviewer.',
                'status'      => self::STATUS_REQUIRED,
                'default'     => true,
                'cms_ref'     => '42 CFR §460.120',
                'wired'       => true,
            ],
            'designation.compliance_officer.grievance_overdue' => [
                'group'       => 'Compliance Officer',
                'label'       => 'Grievance overdue (day-25 alert)',
                'description' => 'Named in overdue-grievance reminders when day 25 of the 30-day clock approaches.',
                'status'      => self::STATUS_REQUIRED,
                'default'     => true,
                'cms_ref'     => '42 CFR §460.120',
                'wired'       => true,
            ],
            'designation.compliance_officer.cms_reportable_event' => [
                'group'       => 'Compliance Officer',
                'label'       => 'CMS-reportable incident logged',
                'description' => 'Notify when an incident is flagged cms_reportable=true. Broader than the regulatory minimum.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Nursing Director ──────────────────────────────────────────
            'designation.nursing_director.fall_risk_threshold' => [
                'group'       => 'Nursing Director',
                'label'       => 'Fall-risk threshold crossed',
                'description' => 'Alert when a participant\'s Morse fall scale crosses 45+ (high-risk threshold). Detection logic still pending — preference is saved and will activate when the assessment-save hook is wired.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false, // SS2 deferred — assessment trigger not yet hooked
            ],
            'designation.nursing_director.pressure_injury_staging' => [
                'group'       => 'Nursing Director',
                'label'       => 'Pressure-injury stage progression',
                'description' => 'Alert when a wound progresses to a higher NPUAP stage. Detection logic pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.nursing_director.late_emar_pattern' => [
                'group'       => 'Nursing Director',
                'label'       => 'Late EMAR-pass pattern',
                'description' => 'Alert when the same nurse triggers ≥3 late medication doses within 7 days. Pattern-detection job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.nursing_director.bcma_override_pattern' => [
                'group'       => 'Nursing Director',
                'label'       => 'BCMA override pattern',
                'description' => 'Alert when the same nurse triggers ≥3 barcode-medication-administration overrides within 7 days. Pattern-detection job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.nursing_director.critical_value_unacked' => [
                'group'       => 'Nursing Director',
                'label'       => 'Critical lab value unacknowledged',
                'description' => 'Escalation alert when a critical lab value has not been acknowledged within policy hours. Escalation timer pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Pharmacy Director ─────────────────────────────────────────
            'designation.pharmacy_director.critical_drug_interaction' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'Critical drug-interaction surfaced',
                'description' => 'Alert when a major-severity drug-drug interaction is flagged at the point of prescribe.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // SS2 wired in DrugInteractionService
            ],
            'designation.pharmacy_director.controlled_substance_pattern' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'Controlled-substance prescribing pattern',
                'description' => 'Alert when a single prescriber issues ≥5 controlled-substance prescriptions in 14 days. Pattern-detection job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.pharmacy_director.bcma_override_review' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'BCMA override on controlled substance',
                'description' => 'Pharmacy review alert when a BCMA override involves a Schedule II/III controlled substance. Hook pending in BCMA scan flow.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.pharmacy_director.prior_auth_queue_oversight' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'Prior-auth queue oversight (>3 days pending)',
                'description' => 'Daily digest of prior-auth requests pending more than 3 days. Daily-digest job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Social Work Supervisor ────────────────────────────────────
            'designation.social_work_supervisor.sdoh_critical' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Critical SDOH flag at intake',
                'description' => 'Alert when housing instability or food insecurity is flagged high-severity on intake. SDOH-store hook pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.social_work_supervisor.bereavement_followup_missed' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Bereavement follow-up missed',
                'description' => 'Alert when a bereavement family-contact follow-up has not been made within 14 days. Daily-check job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'designation.social_work_supervisor.adv_directive_missing_at_admit' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Advance directive missing 30 days post-enrollment',
                'description' => 'Alert when a participant has been enrolled 30+ days with no DPOA or advance directive on file. Daily-check job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Program Director ──────────────────────────────────────────
            'designation.program_director.sentinel_event' => [
                'group'       => 'Program Director',
                'label'       => 'Sentinel event classified',
                'description' => 'Alert when an incident is classified as a sentinel event.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // SS2 wired in IncidentService::classifyAsSentinel
            ],
            'designation.program_director.breach_incident_logged' => [
                'group'       => 'Program Director',
                'label'       => 'HIPAA breach incident logged',
                'description' => 'Additional copy on HIPAA breach notifications (the IT Admin chain still fires regardless).',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => '45 CFR §164.404',
                'wired'       => true, // SS2 wired in BreachIncidentController::store
            ],
            'designation.program_director.cms_reportable_grievance' => [
                'group'       => 'Program Director',
                'label'       => 'CMS-reportable grievance escalated',
                'description' => 'Additional copy on grievance escalations flagged cms_reportable=true. Hook pending in escalation path.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],

            // ── Workflow preferences (not designation-tied) ───────────────
            'workflow.transport_cancellation.notify_assigned_pcp' => [
                'group'       => 'Workflow',
                'label'       => 'Transport cancellation copies the assigned PCP',
                'description' => 'When a transport leg is cancelled, also notify the participant\'s assigned Primary Care Provider.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // SS2 wired in TransportRequestController::cancel
            ],
            'workflow.appointment_no_show.notify_pcp' => [
                'group'       => 'Workflow',
                'label'       => 'Appointment no-show notifies the PCP',
                'description' => 'When a participant misses an appointment, alert their assigned PCP (in addition to the scheduling dept). Hook pending in appointment status update.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'workflow.day_center_no_show.notify_social_work' => [
                'group'       => 'Workflow',
                'label'       => 'Day-center no-show notifies Social Work',
                'description' => 'When a participant scheduled for the day center fails to attend, alert Social Work.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => true,
                'cms_ref'     => null,
                'wired'       => true, // SS2 wired in DayCenterController::storeAttendance
            ],
            'workflow.lab_abnormal.notify_nursing_director' => [
                'group'       => 'Workflow',
                'label'       => 'Abnormal labs copy the Nursing Director',
                'description' => 'On abnormal-flag lab results, also alert the Nursing Director (the ordering provider is always notified).',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // SS2 wired in ProcessLabResultJob
            ],
            'workflow.advance_directive.renewal_warning_days' => [
                'group'       => 'Workflow',
                'label'       => 'Advance directive renewal warning',
                'description' => 'Days before the annual advance-directive renewal to start surfacing reminders.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => true, // simple boolean reminder; the day-count lives in `value` later
                'cms_ref'     => null,
                'wired'       => false,
            ],
            'workflow.insurance_card.expiry_warning' => [
                'group'       => 'Workflow',
                'label'       => 'Insurance card expiry reminders',
                'description' => 'Surface a reminder when a participant\'s insurance card is approaching expiration.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => true,
                'cms_ref'     => null,
                'wired'       => false,
            ],
        ];
    }
}

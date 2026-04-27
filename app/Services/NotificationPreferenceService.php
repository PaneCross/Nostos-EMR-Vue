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
     * Per-tenant in-request cache. Shape:
     *   tenant_id => [
     *       site_id_or_zero => [ preference_key => ['enabled' => bool, 'value' => array|null] ]
     *   ]
     * Site_id_or_zero = the site_id, or 0 for the org-level row (NULL site_id).
     * Cleared by clearCache() when settings are mutated.
     */
    private array $cache = [];

    // ── Status constants for the catalog ──────────────────────────────────────
    public const STATUS_REQUIRED = 'required'; // hardwired by CMS, locked-on in UI
    public const STATUS_OPTIONAL = 'optional'; // tenant choice; toggle works
    public const STATUS_RESERVED = 'reserved'; // planned; setting saves but no code wired yet

    // ── Preference kinds ──────────────────────────────────────────────────────
    public const KIND_BOOLEAN           = 'boolean';            // simple on/off toggle (default)
    public const KIND_NUMERIC           = 'numeric';            // boolean enable + numeric `value.days`
    public const KIND_NUMERIC_THRESHOLD = 'numeric_threshold';  // boolean enable + value.events_count + value.window_days
                                                                // (used by pattern detectors so each org can tune
                                                                //  what counts as "concerning frequency" for them)

    /** Internal sentinel for the org-level row (since SQL NULL can't be an array key). */
    private const ORG_LEVEL = 0;

    // ── Hot-path query ────────────────────────────────────────────────────────

    /**
     * Should this preference fire for this tenant + (optional) site?
     *
     * Cascade order : first hit wins:
     *   1. Required keys → always true (regulatory floor).
     *   2. Per-site override (if $siteId != null and a row exists for it).
     *   3. Org-level row (site_id NULL).
     *   4. Catalog default.
     *
     * The site cascade lets a site Director override an org-wide default
     * without affecting other sites.
     */
    public function shouldNotify(int $tenantId, string $key, ?int $siteId = null): bool
    {
        $catalog = self::catalog();
        $entry = $catalog[$key] ?? null;

        if ($entry && $entry['status'] === self::STATUS_REQUIRED) {
            return true;
        }

        $this->primeCache($tenantId);

        // Site-level override beats org-level.
        if ($siteId !== null) {
            $siteRow = $this->cache[$tenantId][$siteId][$key] ?? null;
            if ($siteRow !== null) {
                return (bool) $siteRow['enabled'];
            }
        }

        // Fall back to org-level row.
        $orgRow = $this->cache[$tenantId][self::ORG_LEVEL][$key] ?? null;
        if ($orgRow !== null) {
            return (bool) $orgRow['enabled'];
        }

        // Final fallback: catalog default.
        return (bool) ($entry['default'] ?? false);
    }

    /**
     * For numeric preferences (KIND_NUMERIC) : return the stored day-count
     * for this tenant + (optional) site. Same cascade as shouldNotify().
     * Returns the catalog `numeric_default` if no row exists.
     *
     * If the preference is NOT numeric, returns null. If the preference is
     * numeric but disabled (enabled=false), returns null too : callers should
     * gate on shouldNotify() first.
     */
    public function numericValue(int $tenantId, string $key, ?int $siteId = null): ?int
    {
        $catalog = self::catalog();
        $entry = $catalog[$key] ?? null;
        if (! $entry || ($entry['kind'] ?? self::KIND_BOOLEAN) !== self::KIND_NUMERIC) {
            return null;
        }
        if (! $this->shouldNotify($tenantId, $key, $siteId)) {
            return null; // disabled : no value
        }

        $this->primeCache($tenantId);

        // Site-level override
        if ($siteId !== null) {
            $siteRow = $this->cache[$tenantId][$siteId][$key] ?? null;
            if ($siteRow !== null && isset($siteRow['value']['days'])) {
                return (int) $siteRow['value']['days'];
            }
        }
        // Org-level
        $orgRow = $this->cache[$tenantId][self::ORG_LEVEL][$key] ?? null;
        if ($orgRow !== null && isset($orgRow['value']['days'])) {
            return (int) $orgRow['value']['days'];
        }
        // Catalog default
        return (int) ($entry['numeric_default'] ?? 0);
    }

    /**
     * For KIND_NUMERIC_THRESHOLD prefs : return the tuned (events_count,
     * window_days) for this tenant + (optional) site. Same cascade as
     * shouldNotify(). Returns null when the pref is disabled or not the
     * threshold kind.
     *
     * Pattern detectors should call shouldNotify() first to gate, then call
     * this for the threshold values.
     *
     * @return array{events_count:int, window_days:int}|null
     */
    public function thresholdValue(int $tenantId, string $key, ?int $siteId = null): ?array
    {
        $catalog = self::catalog();
        $entry = $catalog[$key] ?? null;
        if (! $entry || ($entry['kind'] ?? self::KIND_BOOLEAN) !== self::KIND_NUMERIC_THRESHOLD) {
            return null;
        }
        if (! $this->shouldNotify($tenantId, $key, $siteId)) {
            return null;
        }

        $this->primeCache($tenantId);

        // Site-level override
        if ($siteId !== null) {
            $siteRow = $this->cache[$tenantId][$siteId][$key] ?? null;
            if ($siteRow !== null && is_array($siteRow['value'] ?? null)) {
                $v = $siteRow['value'];
                if (isset($v['events_count'], $v['window_days'])) {
                    return ['events_count' => (int) $v['events_count'], 'window_days' => (int) $v['window_days']];
                }
            }
        }
        // Org-level
        $orgRow = $this->cache[$tenantId][self::ORG_LEVEL][$key] ?? null;
        if ($orgRow !== null && is_array($orgRow['value'] ?? null)) {
            $v = $orgRow['value'];
            if (isset($v['events_count'], $v['window_days'])) {
                return ['events_count' => (int) $v['events_count'], 'window_days' => (int) $v['window_days']];
            }
        }
        // Catalog default
        return [
            'events_count' => (int) ($entry['threshold_default_count']  ?? 3),
            'window_days'  => (int) ($entry['threshold_default_window'] ?? 7),
        ];
    }

    /** Load every row for a tenant into the in-request cache. */
    private function primeCache(int $tenantId): void
    {
        if (isset($this->cache[$tenantId])) return;

        $rows = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->get(['site_id', 'preference_key', 'enabled', 'value']);

        $cache = [];
        foreach ($rows as $r) {
            $bucket = $r->site_id ?? self::ORG_LEVEL;
            $cache[$bucket][$r->preference_key] = [
                'enabled' => (bool) $r->enabled,
                'value'   => $r->value,
            ];
        }
        $this->cache[$tenantId] = $cache;
    }

    // ── Mutation ──────────────────────────────────────────────────────────────

    /**
     * Set a single preference at the org level (site_id NULL) or at a specific
     * site. Records an AuditLog entry on actual flips. For numeric prefs,
     * pass `$value = ['days' => N]` alongside the boolean.
     *
     * @param int        $tenantId
     * @param string     $key
     * @param bool       $enabled
     * @param int        $byUserId
     * @param int|null   $siteId  NULL = org-level row; non-null = per-site override
     * @param array|null $value   for numeric prefs: ['days' => int]
     */
    public function set(
        int $tenantId,
        string $key,
        bool $enabled,
        int $byUserId,
        ?int $siteId = null,
        ?array $value = null,
    ): void {
        $entry = self::catalog()[$key] ?? null;
        if ($entry && $entry['status'] === self::STATUS_REQUIRED) return;

        $existing = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)   // matches NULL via PHP null
            ->where('preference_key', $key)
            ->first();

        $previousEnabled = $existing?->enabled;
        $previousValue   = $existing?->value;

        NotificationPreference::updateOrCreate(
            ['tenant_id' => $tenantId, 'site_id' => $siteId, 'preference_key' => $key],
            [
                'enabled'            => $enabled,
                'value'              => $value,
                'updated_by_user_id' => $byUserId,
            ],
        );

        $changedEnabled = $previousEnabled !== $enabled;
        $changedValue   = $value !== null && $previousValue !== $value;
        if ($changedEnabled || $changedValue) {
            AuditLog::record(
                action:       'org_settings.preference_changed',
                tenantId:     $tenantId,
                userId:       $byUserId,
                resourceType: 'NotificationPreference',
                description:  sprintf(
                    '%s%s → %s%s',
                    $key,
                    $siteId ? " (site #{$siteId})" : '',
                    $enabled ? 'enabled' : 'disabled',
                    $value !== null ? ' value=' . json_encode($value) : '',
                ),
                oldValues:    $previousEnabled === null ? null : ['enabled' => $previousEnabled, 'value' => $previousValue],
                newValues:    ['enabled' => $enabled, 'value' => $value],
            );
        }

        $this->clearCache($tenantId);
    }

    /**
     * Bulk-update many preferences in a single transaction. Used by the Org
     * Settings page save button. Records one AuditLog row per actually-changed
     * preference so the audit trail is precise.
     *
     * Each $changes entry can be:
     *   - bool             → simple toggle (boolean kind)
     *   - ['enabled' => bool, 'value' => array]   → numeric kind
     *
     * @param int|null $siteId  NULL = saving org-level; non-null = per-site override tab
     */
    public function bulkSet(int $tenantId, array $changes, int $byUserId, ?int $siteId = null): int
    {
        $changed = 0;
        DB::transaction(function () use ($tenantId, $changes, $byUserId, $siteId, &$changed) {
            foreach ($changes as $key => $payload) {
                $entry = self::catalog()[$key] ?? null;
                if (! $entry) continue;
                if ($entry['status'] === self::STATUS_REQUIRED) continue;

                // Normalize payload shape : bool or {enabled, value}
                if (is_array($payload)) {
                    $newEnabled = (bool) ($payload['enabled'] ?? false);
                    $newValue   = $payload['value'] ?? null;
                } else {
                    $newEnabled = (bool) $payload;
                    $newValue   = null;
                }

                $existing = NotificationPreference::query()
                    ->where('tenant_id', $tenantId)
                    ->where('site_id', $siteId)
                    ->where('preference_key', $key)
                    ->first();
                $previousEnabled = $existing?->enabled;
                $previousValue   = $existing?->value;

                $changedEnabled = $previousEnabled !== $newEnabled;
                $changedValue   = $newValue !== null && $previousValue !== $newValue;
                if (! $changedEnabled && ! $changedValue) continue;

                NotificationPreference::updateOrCreate(
                    ['tenant_id' => $tenantId, 'site_id' => $siteId, 'preference_key' => $key],
                    ['enabled' => $newEnabled, 'value' => $newValue, 'updated_by_user_id' => $byUserId],
                );

                AuditLog::record(
                    action:       'org_settings.preference_changed',
                    tenantId:     $tenantId,
                    userId:       $byUserId,
                    resourceType: 'NotificationPreference',
                    description:  sprintf(
                        '%s%s → %s',
                        $key,
                        $siteId ? " (site #{$siteId})" : '',
                        $newEnabled ? 'enabled' : 'disabled',
                    ),
                    oldValues:    $previousEnabled === null ? null : ['enabled' => $previousEnabled, 'value' => $previousValue],
                    newValues:    ['enabled' => $newEnabled, 'value' => $newValue],
                );
                $changed++;
            }
        });

        $this->clearCache($tenantId);
        return $changed;
    }

    /**
     * Remove a per-site override row. Site falls back to inheriting the
     * org-level default. No-op if no override row exists.
     */
    public function clearSiteOverride(int $tenantId, int $siteId, string $key, int $byUserId): bool
    {
        $existing = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where('site_id', $siteId)
            ->where('preference_key', $key)
            ->first();
        if (! $existing) return false;

        $existing->delete();

        AuditLog::record(
            action:       'org_settings.preference_changed',
            tenantId:     $tenantId,
            userId:       $byUserId,
            resourceType: 'NotificationPreference',
            description:  sprintf('%s (site #%d) override cleared (now inherits org default)', $key, $siteId),
            oldValues:    ['enabled' => $existing->enabled, 'value' => $existing->value],
            newValues:    null,
        );

        $this->clearCache($tenantId);
        return true;
    }

    /**
     * Seed default rows at the ORG level (site_id NULL) for a fresh tenant.
     * Idempotent : skips keys with an existing org-level row. Required keys
     * are never stored.
     */
    public function seedDefaults(int $tenantId): int
    {
        $created = 0;
        foreach (self::catalog() as $key => $entry) {
            if ($entry['status'] === self::STATUS_REQUIRED) continue;
            $exists = NotificationPreference::query()
                ->where('tenant_id', $tenantId)
                ->whereNull('site_id')
                ->where('preference_key', $key)
                ->exists();
            if ($exists) continue;

            $row = [
                'tenant_id'      => $tenantId,
                'site_id'        => null,
                'preference_key' => $key,
                'enabled'        => (bool) ($entry['default'] ?? false),
            ];
            $kind = $entry['kind'] ?? self::KIND_BOOLEAN;
            if ($kind === self::KIND_NUMERIC) {
                $row['value'] = ['days' => (int) ($entry['numeric_default'] ?? 0)];
            } elseif ($kind === self::KIND_NUMERIC_THRESHOLD) {
                $row['value'] = [
                    'events_count' => (int) ($entry['threshold_default_count']  ?? 3),
                    'window_days'  => (int) ($entry['threshold_default_window'] ?? 7),
                ];
            }
            NotificationPreference::create($row);
            $created++;
        }
        $this->clearCache($tenantId);
        return $created;
    }

    /** Catalog-default value payload by kind : used by effectiveSettingsForTenant. */
    private function catalogDefaultValue(array $entry, string $kind): ?array
    {
        if ($kind === self::KIND_NUMERIC) {
            return ['days' => (int) ($entry['numeric_default'] ?? 0)];
        }
        if ($kind === self::KIND_NUMERIC_THRESHOLD) {
            return [
                'events_count' => (int) ($entry['threshold_default_count']  ?? 3),
                'window_days'  => (int) ($entry['threshold_default_window'] ?? 7),
            ];
        }
        return null;
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
     * Effective state for every catalog key. Drives the Org Settings page render.
     *
     * If $siteId is null, returns ORG-level effective state (no override layer).
     *
     * If $siteId is non-null, returns the site's effective state via cascade:
     * site_row → org_row → catalog_default. The result also includes per-key
     * `inherits_from_org` (true when the site has no override row of its own)
     * so the UI can show "Inherits" vs "Site override" badges.
     */
    public function effectiveSettingsForTenant(int $tenantId, ?int $siteId = null): array
    {
        // Pull the rows once
        $rows = NotificationPreference::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($siteId) {
                $q->whereNull('site_id');
                if ($siteId !== null) {
                    $q->orWhere('site_id', $siteId);
                }
            })
            ->get();

        $orgByKey  = [];
        $siteByKey = [];
        foreach ($rows as $r) {
            if ($r->site_id === null) {
                $orgByKey[$r->preference_key] = ['enabled' => (bool) $r->enabled, 'value' => $r->value];
            } else {
                $siteByKey[$r->preference_key] = ['enabled' => (bool) $r->enabled, 'value' => $r->value];
            }
        }

        $out = [];
        foreach (self::catalog() as $key => $entry) {
            $kind = $entry['kind'] ?? self::KIND_BOOLEAN;

            // Determine effective values per cascade
            $inheritsFromOrg = true;
            $value = null;

            if ($entry['status'] === self::STATUS_REQUIRED) {
                $enabled = true;
                $value = $this->catalogDefaultValue($entry, $kind);
            } elseif ($siteId !== null && isset($siteByKey[$key])) {
                $enabled = $siteByKey[$key]['enabled'];
                $value   = $siteByKey[$key]['value'] ?? $this->catalogDefaultValue($entry, $kind);
                $inheritsFromOrg = false;
            } elseif (isset($orgByKey[$key])) {
                $enabled = $orgByKey[$key]['enabled'];
                $value   = $orgByKey[$key]['value'] ?? $this->catalogDefaultValue($entry, $kind);
            } else {
                $enabled = (bool) ($entry['default'] ?? false);
                $value = $this->catalogDefaultValue($entry, $kind);
            }

            $out[$key] = array_merge($entry, [
                'enabled'           => $enabled,
                'value'             => $value,
                'inherits_from_org' => $inheritsFromOrg,
            ]);
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
                'description' => 'Notify the Medical Director when a §460.122 appeal is filed (in addition to the standard QA Compliance dept-broadcast).',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: AppealService::file
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
                'description' => 'Notify the Compliance Officer the moment an incident is flagged cms_reportable=true at intake. Broader than the regulatory minimum.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: IncidentService::createIncident
            ],

            // ── Nursing Director ──────────────────────────────────────────
            'designation.nursing_director.fall_risk_threshold' => [
                'group'       => 'Nursing Director',
                'label'       => 'Fall-risk threshold crossed',
                'description' => 'Alert when a participant\'s Morse fall scale crosses 45+ (High Risk band) on a fall_risk_morse assessment. Routed in addition to the standard primary_care/idt alert.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,  // W1 wired in AssessmentController::store via maybeNotifyNursingDirectorOnFallRisk
            ],
            'designation.nursing_director.pressure_injury_staging' => [
                'group'       => 'Nursing Director',
                'label'       => 'Pressure-injury stage progression',
                'description' => 'Alert when a wound deteriorates (closest analog to NPUAP stage progression today). Routed alongside the existing primary_care alert.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: WoundService::addAssessment
            ],
            'designation.nursing_director.late_emar_pattern' => [
                'group'       => 'Nursing Director',
                'label'       => 'Late EMAR-pass pattern',
                'description' => 'Alert when the same nurse triggers a concerning number of late medication doses within a recent window. You set "concerning" : see threshold controls.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'                       => self::KIND_NUMERIC_THRESHOLD,
                'threshold_default_count'    => 3,
                'threshold_default_window'   => 7,
                'threshold_count_min'        => 1,
                'threshold_count_max'        => 50,
                'threshold_window_min'       => 1,
                'threshold_window_max'       => 90,
                'threshold_event_unit'       => 'late doses',
            ],
            'designation.nursing_director.bcma_override_pattern' => [
                'group'       => 'Nursing Director',
                'label'       => 'BCMA override pattern',
                'description' => 'Alert when the same nurse triggers a concerning number of barcode-medication-administration overrides within a recent window. Tune both numbers below.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'                       => self::KIND_NUMERIC_THRESHOLD,
                'threshold_default_count'    => 3,
                'threshold_default_window'   => 7,
                'threshold_count_min'        => 1,
                'threshold_count_max'        => 50,
                'threshold_window_min'       => 1,
                'threshold_window_max'       => 90,
                'threshold_event_unit'       => 'overrides',
            ],
            'designation.nursing_director.critical_value_unacked' => [
                'group'       => 'Nursing Director',
                'label'       => 'Critical lab value unacknowledged',
                'description' => 'Escalation alert when a critical lab value has not been acknowledged within a tunable window. Set how many hours to wait before escalating to nursing leadership.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'                       => self::KIND_NUMERIC_THRESHOLD,
                // For this one, "events_count" is unused (always 1 event = one
                // unacked lab); window_days is repurposed as window_hours via
                // the unit label. Job logic just reads window_days as hours.
                'threshold_default_count'    => 1,
                'threshold_default_window'   => 4,    // hours
                'threshold_count_min'        => 1,
                'threshold_count_max'        => 1,
                'threshold_window_min'       => 1,
                'threshold_window_max'       => 24,
                'threshold_event_unit'       => 'unacked critical (hours window)',
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
                'description' => 'Alert when a single prescriber issues a concerning number of controlled-substance prescriptions within a recent window. Tune both numbers below.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'                       => self::KIND_NUMERIC_THRESHOLD,
                'threshold_default_count'    => 5,
                'threshold_default_window'   => 14,
                'threshold_count_min'        => 1,
                'threshold_count_max'        => 100,
                'threshold_window_min'       => 1,
                'threshold_window_max'       => 90,
                'threshold_event_unit'       => 'controlled-Rx',
            ],
            'designation.pharmacy_director.bcma_override_review' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'BCMA override on controlled substance',
                'description' => 'Pharmacy review alert when a BCMA override involves a Schedule II/III controlled substance. Routed alongside the existing qa_compliance + pharmacy alert.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: BcmaService scan-override path
            ],
            'designation.pharmacy_director.prior_auth_queue_oversight' => [
                'group'       => 'Pharmacy Director',
                'label'       => 'Prior-auth queue oversight (>3 days pending)',
                'description' => 'Daily digest of prior-auth requests pending more than 3 days. Daily-digest job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
            ],

            // ── Social Work Supervisor ────────────────────────────────────
            'designation.social_work_supervisor.sdoh_critical' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Critical SDOH flag at intake',
                'description' => 'Alert when housing-instability (unstable/homeless) or food-insecurity is flagged on a SDOH intake screening.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: SocialDeterminantController::store
            ],
            'designation.social_work_supervisor.bereavement_followup_missed' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Bereavement follow-up missed',
                'description' => 'Alert when a bereavement family-contact follow-up has not been made within 14 days. Daily-check job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
            ],
            'designation.social_work_supervisor.adv_directive_missing_at_admit' => [
                'group'       => 'Social Work Supervisor',
                'label'       => 'Advance directive missing 30 days post-enrollment',
                'description' => 'Alert when a participant has been enrolled 30+ days with no DPOA or advance directive on file. Daily-check job pending.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true,
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
                'description' => 'Additional named-recipient on grievance escalations flagged cms_reportable=true. Hardwired Compliance chain still fires.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: GrievanceService::transitionStatus escalation path
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
                'description' => 'When a participant misses an appointment, alert their assigned PCP (in addition to the existing transportation+enrollment alert).',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => false,
                'cms_ref'     => null,
                'wired'       => true, // W2-tier1: AppointmentController::noShow
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
                'description' => 'Surface reminders ahead of a participant\'s annual advance-directive renewal. Choose how many days in advance the reminder fires.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => true,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'           => self::KIND_NUMERIC,
                'numeric_default'=> 60,
                'numeric_min'    => 7,
                'numeric_max'    => 365,
                'numeric_unit'   => 'days before renewal',
            ],
            'workflow.insurance_card.expiry_warning' => [
                'group'       => 'Workflow',
                'label'       => 'Insurance card expiry reminders',
                'description' => 'Surface reminders ahead of insurance-card expiration. Choose how many days in advance the reminder fires.',
                'status'      => self::STATUS_OPTIONAL,
                'default'     => true,
                'cms_ref'     => null,
                'wired'       => true,
                'kind'           => self::KIND_NUMERIC,
                'numeric_default'=> 30,
                'numeric_min'    => 7,
                'numeric_max'    => 180,
                'numeric_unit'   => 'days before expiry',
            ],
        ];
    }
}

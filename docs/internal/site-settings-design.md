# Site Settings — Notification Preferences (design)

**Status:** Active build (Phase SS1-SS4)
**Audience:** Brian (dev team lead) + future contributors
**Last updated:** 2026-04-26

## What problem this solves

PACE organizations have wildly different internal workflows. One org wants every urgent grievance to copy the Compliance Officer; another wants only the assigned reviewer to be notified. CMS regulations dictate the *minimum* notification path; everything else is org preference.

Until now, alert routing was hardcoded. This feature gives each PACE org a checklist of optional notification + workflow preferences they can toggle on or off. It's also a sales differentiator — most PACE EMRs hardcode everything and require a vendor ticket to change a recipient.

## Three categories of routing

Every alert/notification path falls into one of three buckets:

| Bucket | Behavior | UI representation |
|---|---|---|
| **Required by CMS** (hardwired) | Always fires regardless of settings. Examples: §460.121 SDR 72-hour deadline alerts to QA Compliance, §460.404 breach notification chain. | Shown in UI as "Required — locked on" with a CFR citation tooltip. Toggle disabled. |
| **Optional preference** (toggleable) | Fires based on the org's setting. Default state varies — see "Defaults" below. | Standard toggle; admins flip on/off. |
| **Reserved** (planned but not yet wired in code) | Doesn't fire today. The setting is recorded so when code wires it up, behavior matches stored preference. | Toggle works but a hint reads: "Wires up when this alert is built — your preference is saved." |

The split matters because the goal is **transparency** — admins should never wonder "did this notification fire because we wanted it to, or because CMS forced us?"

## Data model

Single table `emr_notification_preferences`:

```
id (pk)
tenant_id (fk shared_tenants, indexed)
preference_key (string, indexed)        -- e.g. "designation.nursing_director.fall_risk"
enabled (boolean)
updated_at (timestamp)
updated_by_user_id (fk shared_users, nullable)
```

Composite unique index on `(tenant_id, preference_key)`.

Why a row-per-key vs. a JSON blob: we want the IT-Admin audit log to track which specific preference flipped + by whom, and we want to add new keys over time without juggling a JSON migration. Row-per-key is queryable, indexable, and cheap (a typical tenant will have ~30-50 rows).

## Service layer

`App\Services\NotificationPreferenceService`:

```php
public function shouldNotify(int $tenantId, string $key, bool $default = false): bool
public function set(int $tenantId, string $key, bool $enabled, int $byUserId): void
public function bulkSet(int $tenantId, array $changes, int $byUserId): void  // for the settings page save
public function defaults(): array  // canonical key → default-enabled map
public function catalog(): array   // canonical key → metadata (label, group, status, description, cms_ref)
```

`shouldNotify()` is the hot path called from every alert dispatch site. It caches per-tenant in-request (Laravel container singleton with a static map). At cold-cache, one query returns all rows for the tenant.

Existing alert services that route to designations get a 1-2 line change:

```php
// Before
$director = User::where('tenant_id', $tenantId)
    ->withDesignation('nursing_director')->where('is_active', true)->first();

// After
if ($prefs->shouldNotify($tenantId, 'designation.nursing_director.fall_risk')) {
    $director = User::where('tenant_id', $tenantId)
        ->withDesignation('nursing_director')->where('is_active', true)->first();
    // ...
}
```

## Preference catalog (v1)

Roughly 25 keys grouped by designation + workflow area. CFR/regulatory anchors are noted where they apply. **R = Required, O = Optional, X = Reserved.**

### Medical Director
- `designation.medical_director.restraint_observation_overdue` — **R** — restraint episodes >4h without observation. CMS §483.13 patient safety; locked on.
- `designation.medical_director.restraint_idt_review_overdue` — **R** — restraint IDT review >24h overdue. Locked on.
- `designation.medical_director.appeal_decision_required` — **O** — alert when an appeal is filed and awaiting decision (separate from the QA Compliance dept-broadcast).

### Compliance Officer
- `designation.compliance_officer.urgent_grievance_filed` — **R** — every urgent grievance per §460.120(c) 72h clock. Locked on.
- `designation.compliance_officer.grievance_escalated` — **R** — auto-assignment fallback. Locked on.
- `designation.compliance_officer.grievance_overdue` — **R** — day-25 reminder per §460.120. Locked on.
- `designation.compliance_officer.cms_reportable_event` — **O** — incidents flagged cms_reportable=true (broader than required minimum).

### Nursing Director
- `designation.nursing_director.fall_risk_threshold` — **X→O** — Morse fall scale crosses 45+ (high risk). Off by default.
- `designation.nursing_director.pressure_injury_staging` — **X→O** — wound stage progresses (e.g., stage 2 → stage 3). Off by default.
- `designation.nursing_director.late_emar_pattern` — **X→O** — same nurse triggers ≥3 late doses in 7 days. Off by default.
- `designation.nursing_director.bcma_override_pattern` — **X→O** — same nurse triggers ≥3 BCMA overrides in 7 days. Off by default.
- `designation.nursing_director.critical_value_unacked` — **X→O** — critical lab value not acknowledged within policy. Off by default.

### Pharmacy Director
- `designation.pharmacy_director.critical_drug_interaction` — **X→O** — major-severity drug interaction surfaced at prescribe. Off by default.
- `designation.pharmacy_director.controlled_substance_pattern` — **X→O** — same prescriber issues N controlled-substance Rx in M days (config below). Off by default.
- `designation.pharmacy_director.bcma_override_review` — **X→O** — pharmacy review of BCMA overrides involving controlled substances. Off by default.
- `designation.pharmacy_director.prior_auth_queue_oversight` — **X→O** — daily digest of pending PAs >3 days old. Off by default.

### Social Work Supervisor
- `designation.social_work_supervisor.sdoh_critical` — **X→O** — SDOH intake flags housing-instability or food-insecurity high-severity. Off by default.
- `designation.social_work_supervisor.bereavement_followup_missed` — **X→O** — bereavement family contact not made within 14 days. Off by default.
- `designation.social_work_supervisor.adv_directive_missing_at_admit` — **X→O** — new participant enrolled without DPOA/advance directive on file after 30 days. Off by default.

### Program Director
- `designation.program_director.sentinel_event` — **X→O** — when an incident is classified as sentinel. Off by default.
- `designation.program_director.breach_incident_logged` — **X→O** — when a HIPAA breach is logged (in addition to the required IT Admin chain). Off by default.
- `designation.program_director.cms_reportable_grievance` — **X→O** — additional copy on cms_reportable grievance escalations. Off by default.

### Workflow preferences (not designation-tied)
These are routing decisions where the org wants control over WHICH role hears about an event:

- `workflow.day_center_no_show.recipient` — **O** — multi-value: `social_work` (default) vs `activities` vs `both`.
- `workflow.transport_cancellation.notify_assigned_pcp` — **O** — adds the participant's PCP to dispatcher cancellation alerts. Off by default.
- `workflow.appointment_no_show.notify_pcp` — **O** — alert PCP on missed appointments (in addition to scheduling dept). Off by default.
- `workflow.lab_abnormal.notify_nursing_director` — **O** — abnormal labs route to nursing_director if held. Off by default.
- `workflow.advance_directive_renewal.warning_days` — **O** — numeric: warn N days before annual renewal. Default 60. (Numeric pref; UI shows a small select.)
- `workflow.insurance_card_expiry.warning_days` — **O** — same shape. Default 30.

A handful of these are non-boolean (multi-value or numeric). The schema supports it: `enabled` is the boolean default; an additional `value` JSON column is added for the few that need richer state. For v1 most prefs are pure booleans.

## Defaults

`NotificationPreferenceService::defaults()` returns the canonical default map. Tenant onboarding (`TenantOnboardingService`) calls this at create time to seed every key. Existing tenants get backfilled by a one-shot migration script (idempotent — won't override existing rows).

Everything currently wired in code (Required + already-firing optional alerts) defaults **on**. Everything Reserved defaults **off** so onboarding tenants don't get a flood of alerts the first day from features they may not use.

## UI

Settings page at `/executive/site-settings`:
- Grouped by designation + workflow area
- Each toggle shows: label, 1-line description, status badge (Required/Optional/Reserved), CFR ref tooltip if applicable
- Save commits a single bulk-update transaction; AuditLog row per changed key (so the IT Admin audit log shows "Compliance Officer urgent_grievance_filed: enabled by Brian")
- Only the `executive` department + `super_admin` role can view / save (PermissionService gating)

## Backward compat

Existing alert services that already route to medical_director and compliance_officer for the **Required** keys keep working unchanged — the service short-circuits and returns true for hardwired Required keys regardless of stored preference. The only behavior change for existing wired paths is the AuditLog audit trail when an Optional preference is flipped.

## What this does NOT cover (intentional v1 scope)

- Per-user notification preferences (e.g., "this nurse wants emails not in-app"). That's a separate setting surface; tenant settings come first.
- Per-site overrides. Future enhancement; the schema's `tenant_id` column would be joined by an optional `site_id` column with a fallback rule.
- Time-window preferences (e.g., "do not alert overnight"). Future enhancement.
- Real email/SMS/push delivery channel choice. The current Alert system creates in-app alerts + (where wired) emails; channel choice is global.

## Phases

- **SS1** — this doc + migration + model + service + defaults (one commit)
- **SS2** — wire all listed Reserved + Workflow keys through the service (one commit; multiple files)
- **SS3** — Inertia Site Settings page, controller, nav item, permission seed (one commit)
- **SS4** — full test sweep + verification (folded into SS3 or split if scope demands)

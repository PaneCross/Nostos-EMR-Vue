# Org Settings — wiring backlog

**Status:** Active follow-up after Phase OS3.
**Audience:** Brian / dev team.
**Last updated:** 2026-04-26

The Org Settings page (`/executive/org-settings`) ships with a catalog of ~27 preferences. As of OS3 + W1, **8 keys are wired** end-to-end (the alert dispatch consults `NotificationPreferenceService::shouldNotify()`); **17 remain reserved** with their UI toggle saving but no alert behavior.

The pattern for wiring is established. This doc lists every remaining key, its target file, and the insertion shape, so the team can chip away at them as customers ask.

## The canonical wiring pattern (already in code)

`AssessmentController::maybeNotifyNursingDirectorOnFallRisk()` is the cleanest example. The shape is always:

```php
private function maybeNotifyXOnY(...): void
{
    // 1. Early return if the trigger condition isn't met
    if (! triggerConditionMet) return;

    // 2. Consult the service (with site_id cascade if event has a site context)
    $prefs = app(\App\Services\NotificationPreferenceService::class);
    $key = 'designation.X.Y'; // matches catalog
    if (! $prefs->shouldNotify($tenantId, $key, $siteId)) return;

    // 3. Find the named recipient
    $director = \App\Models\User::where('tenant_id', $tenantId)
        ->withDesignation('X')
        ->where('is_active', true)
        ->first();
    if (! $director) return;

    // 4. Dispatch the additional alert
    Alert::create([...]);
}
```

After wiring, flip the catalog entry's `wired: false` → `wired: true`. The Site Settings UI auto-updates.

For preferences that need pattern detection across time windows (e.g. "≥3 events in 7 days"), the recommended shape is a daily artisan command + a Job that uses the same service-consultation pattern. Same for daily-digest jobs.

---

## Wiring backlog (17 keys)

### Tier 1 — simple event-trigger checks (≈30 min each)

| Key | File / method to edit | Trigger | Recipient | Alert type |
|---|---|---|---|---|
| `designation.nursing_director.pressure_injury_staging` | `WoundController::storeAssessment` | new WoundAssessment with `stage > previous stage` for same participant | `nursing_director` | `nursing_director_wound_progression` |
| `designation.pharmacy_director.bcma_override_review` | `BcmaController::recordScan` (or wherever `emar_records.bcma_override` is set) | scan_override = true AND associated medication.is_controlled = true AND controlled_schedule in {II, III} | `pharmacy_director` | `pharmacy_director_bcma_override` |
| `designation.social_work_supervisor.sdoh_critical` | `SocialDeterminantController::store` | severity = 'high' AND category in {housing, food} | `social_work_supervisor` | `social_work_supervisor_sdoh_critical` |
| `designation.program_director.cms_reportable_grievance` | `GrievanceService::transitionStatus` (escalation path) | new status = 'escalated' AND grievance.cms_reportable = true | `program_director` | `program_director_cms_reportable_grievance` |
| `designation.medical_director.appeal_decision_required` | `AppealService::file` | always after appeal create | `medical_director` | `medical_director_appeal_pending` |
| `designation.compliance_officer.cms_reportable_event` | `IncidentService::createIncident` | incident.cms_reportable = true | `compliance_officer` | `compliance_officer_cms_reportable_event` |
| `workflow.appointment_no_show.notify_pcp` | `AppointmentController::updateStatus` (or model observer) | status transition to `no_show`; participant has `primary_provider_user_id` | participant's PCP user | `appointment_no_show_pcp_copy` |

Each test pattern: trigger event with the preference OFF, assert no alert. Toggle ON, retrigger, assert alert exists.

### Tier 2 — pattern-detection jobs (≈3 hours each, plus a shared helper)

These need a daily artisan command sweeping a time window for "actor X did N events in M days." Recommend a shared `Concerns\\DetectsPatternAlerts` trait or `PatternDetectionService` that takes (eventQuery, windowDays, threshold, recipientDesignation, alertShape). Then four small commands:

| Key | Time window | Threshold | Source table | Job class to create |
|---|---|---|---|---|
| `designation.nursing_director.late_emar_pattern` | 7 days | ≥3 late doses by same nurse | `emar_records` | `DetectLateEmarPatternJob` |
| `designation.nursing_director.bcma_override_pattern` | 7 days | ≥3 overrides by same nurse | `emar_records` | `DetectBcmaOverridePatternJob` |
| `designation.pharmacy_director.controlled_substance_pattern` | 14 days | ≥5 Schedule II/III Rx by same prescriber | `emr_medications` (joined to user) | `DetectControlledSubstancePatternJob` |
| `designation.nursing_director.critical_value_unacked` | per-event escalation, not a sweep | unacknowledged > policy_hours | `critical_value_acknowledgments` | `EscalateUnackedCriticalValuesJob` |

Schedule each daily at 06:30 in `routes/console.php`.

### Tier 3 — daily-check / daily-digest jobs (≈1 hour each)

Same shape as Tier 2 but simpler — one query per day per tenant.

| Key | What to query | Recipient | Job class |
|---|---|---|---|
| `designation.pharmacy_director.prior_auth_queue_oversight` | `emr_prior_auth_requests` where pending > 3 days | `pharmacy_director` | `PriorAuthQueueDigestJob` |
| `designation.social_work_supervisor.bereavement_followup_missed` | `bereavement_followups` where contacted_at IS NULL AND opened_at < now()-14d | `social_work_supervisor` | `BereavementFollowupOverdueJob` |
| `designation.social_work_supervisor.adv_directive_missing_at_admit` | `emr_participants` where enrollment_date < now()-30d AND no advance_directive | `social_work_supervisor` | `AdvDirectiveMissingJob` |

### Tier 4 — numeric-pref daily jobs (≈1 hour each)

For numeric preferences, use `numericValue($tenantId, $key, $siteId)` to read the day-count.

| Key | Logic | Recipient | Job class |
|---|---|---|---|
| `workflow.advance_directive.renewal_warning_days` | each day, find participants whose advance directive expires within `numericValue()` days; alert their PCP + assigned social worker | participant team | `AdvDirectiveRenewalWarningJob` |
| `workflow.insurance_card.expiry_warning` | same pattern; insurance card expiry within `numericValue()` days; alert finance | finance dept | `InsuranceCardExpiryWarningJob` |

---

## Maintenance contract

When you wire a key:
1. Add the dispatch site (controller / service / job) consulting `shouldNotify()` per the canonical pattern
2. Flip `wired: false` → `wired: true` in `NotificationPreferenceService::catalog()`
3. Add a wiring test to `tests/Feature/NotificationPreferenceWiringTest.php` (toggle off → no alert, toggle on → alert)
4. Update this doc by removing the key from its tier and adding it to "Wired to date"

## Wired to date

| Key | Phase | Where |
|---|---|---|
| `designation.medical_director.restraint_observation_overdue` | (existing, Required) | `RestraintMonitoringOverdueJob` |
| `designation.medical_director.restraint_idt_review_overdue` | (existing, Required) | `RestraintMonitoringOverdueJob` |
| `designation.compliance_officer.urgent_grievance_filed` | (existing, Required) | `GrievanceService::store` |
| `designation.compliance_officer.grievance_escalated` | (existing, Required) | `GrievanceService::transitionStatus` |
| `designation.compliance_officer.grievance_overdue` | (existing, Required) | `GrievanceService::checkOverdue` |
| `designation.program_director.sentinel_event` | SS2 | `IncidentService::classifyAsSentinel` |
| `designation.program_director.breach_incident_logged` | SS2 | `BreachIncidentController::store` |
| `designation.pharmacy_director.critical_drug_interaction` | SS2 | `DrugInteractionService::checkInteractions` |
| `workflow.day_center_no_show.notify_social_work` | SS2 | `DayCenterController::markAbsent` |
| `workflow.transport_cancellation.notify_assigned_pcp` | SS2 | `TransportRequestController::cancel` |
| `workflow.lab_abnormal.notify_nursing_director` | SS2 | `ProcessLabResultJob::handle` |
| `designation.nursing_director.fall_risk_threshold` | W1 | `AssessmentController::maybeNotifyNursingDirectorOnFallRisk` |

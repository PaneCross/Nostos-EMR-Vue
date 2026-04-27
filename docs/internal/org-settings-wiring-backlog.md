# Org Settings — wiring backlog

**Status:** **CLEARED.** All 27 catalog keys are wired as of W2-tier4.
**Audience:** Brian / dev team.
**Last updated:** 2026-04-26

## Summary

The Org Settings catalog has 27 preferences. **All 27 are wired** as of W2-tier4. The page at `/executive/org-settings` is fully feature-complete: every toggle drives real alert behavior; every threshold input drives real pattern-detection logic.

Use this doc as a *reference* of what each key does and where to find the wiring code, not as a backlog anymore.

## Wired keys map

| Key | Where | Phase |
|---|---|---|
| `designation.medical_director.restraint_observation_overdue` | `RestraintMonitoringOverdueJob` | (existing, REQUIRED) |
| `designation.medical_director.restraint_idt_review_overdue` | `RestraintMonitoringOverdueJob` | (existing, REQUIRED) |
| `designation.medical_director.appeal_decision_required` | `AppealService::file` | W2-tier1 |
| `designation.compliance_officer.urgent_grievance_filed` | `GrievanceService::store` | (existing, REQUIRED) |
| `designation.compliance_officer.grievance_escalated` | `GrievanceService::transitionStatus` | (existing, REQUIRED) |
| `designation.compliance_officer.grievance_overdue` | `GrievanceService::checkOverdue` | (existing, REQUIRED) |
| `designation.compliance_officer.cms_reportable_event` | `IncidentService::createIncident` | W2-tier1 |
| `designation.nursing_director.fall_risk_threshold` | `AssessmentController::maybeNotifyNursingDirectorOnFallRisk` | W1 |
| `designation.nursing_director.pressure_injury_staging` | `WoundService::addAssessment` | W2-tier1 |
| `designation.nursing_director.late_emar_pattern` | `DetectLateEmarPatternJob` (cron 06:30) | W2-tier2 |
| `designation.nursing_director.bcma_override_pattern` | `DetectBcmaOverridePatternJob` (cron 06:35) | W2-tier2 |
| `designation.nursing_director.critical_value_unacked` | `DetectUnackedCriticalValueJob` (cron hourly) | W2-tier2 |
| `designation.pharmacy_director.critical_drug_interaction` | `DrugInteractionService::checkInteractions` | SS2 |
| `designation.pharmacy_director.bcma_override_review` | `BcmaService` scan-override path | W2-tier1 |
| `designation.pharmacy_director.controlled_substance_pattern` | `DetectControlledSubstancePatternJob` (cron 06:40) | W2-tier2 |
| `designation.pharmacy_director.prior_auth_queue_oversight` | `PriorAuthQueueDigestJob` (cron 06:50) | W2-tier3 |
| `designation.social_work_supervisor.sdoh_critical` | `SocialDeterminantController::store` | W2-tier1 |
| `designation.social_work_supervisor.bereavement_followup_missed` | `BereavementFollowupOverdueJob` (cron 06:55) | W2-tier3 |
| `designation.social_work_supervisor.adv_directive_missing_at_admit` | `AdvDirectiveMissingJob` (cron 07:05) | W2-tier3 |
| `designation.program_director.sentinel_event` | `IncidentService::classifyAsSentinel` | SS2 |
| `designation.program_director.breach_incident_logged` | `BreachIncidentController::store` | SS2 |
| `designation.program_director.cms_reportable_grievance` | `GrievanceService::transitionStatus` (escalated) | W2-tier1 |
| `workflow.day_center_no_show.notify_social_work` | `DayCenterController::markAbsent` | SS2 |
| `workflow.transport_cancellation.notify_assigned_pcp` | `TransportRequestController::cancel` | SS2 |
| `workflow.lab_abnormal.notify_nursing_director` | `ProcessLabResultJob::handle` | SS2 |
| `workflow.appointment_no_show.notify_pcp` | `AppointmentController::noShow` | W2-tier1 |
| `workflow.advance_directive.renewal_warning_days` | `AdvDirectiveRenewalWarningJob` (cron 07:10) | W2-tier4 |
| `workflow.insurance_card.expiry_warning` | `InsuranceCardExpiryWarningJob` (cron 07:15) | W2-tier4 |

## Cron schedule (added in W2)

```
06:30  DetectLateEmarPatternJob
06:35  DetectBcmaOverridePatternJob
06:40  DetectControlledSubstancePatternJob
06:50  PriorAuthQueueDigestJob
06:55  BereavementFollowupOverdueJob
07:05  AdvDirectiveMissingJob
07:10  AdvDirectiveRenewalWarningJob
07:15  InsuranceCardExpiryWarningJob
hourly DetectUnackedCriticalValueJob
```

All scheduled jobs are gated by `NotificationPreferenceService::shouldNotify()` per tenant — a tenant that hasn't enabled a given preference is a no-op for that job.

## Maintenance contract (still applies)

When adding a NEW preference key:

1. Add catalog entry in `NotificationPreferenceService::catalog()`
2. Add the wiring (event-trigger check OR new daily/hourly job)
3. Flip `wired: false` → `wired: true` in the catalog entry
4. Add a wiring test (toggle off → no alert, toggle on → alert)
5. Update this doc

When changing a threshold default in code: bump `threshold_default_count` / `threshold_default_window` in the catalog. Existing tenants keep their stored values; new tenants pick up the new default.

## Architecture notes

`PatternDetectionService` (Tier 2) is the canonical shared helper for "actor X did N events in M days" patterns. New pattern-detection prefs should reuse it via the `runForKey()` method. Only adapt the `countQuery` callback for your event source.

Daily-check jobs (Tier 3) follow a simpler shape — see `BereavementFollowupOverdueJob` for the canonical example. Each job:
- Iterates active tenants
- Skips if `shouldNotify()` is false for that tenant
- Queries for matching candidates
- Dedupes against today's existing alerts
- Looks up the recipient via `withDesignation()`
- Creates the alert

Numeric daily jobs (Tier 4) use `numericValue()` to read the per-org day count and apply it as a `now()->addDays($n)` cutoff.

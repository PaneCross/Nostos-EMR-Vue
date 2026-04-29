# Credentials Audit #2 Remediation Plan

**Started:** 2026-04-28
**Mode:** Autonomous, all-non-paywall, back-to-back commits.

## Phase 1 — Bugs + safety guards
- A1 : verifyCredential rejects PSV-required defs unless source is state_board / npdb
- A2 : bulk-renew UI on dashboard drilldown (multi-select + bulk modal)
- A3 : renewal requires expires_at + issued_at must be in past
- A4 : bulk import validates verification_source enum + cms_status
- A5 : IT Admin update overwrites are versioned (new row + supersede chain) OR purge old file
- B5 : catalog cross-field validation (PSV only valid for license/certification)
- B9 : CredentialsDemoDataSeeder env-guard (refuse to run in production)
- E2 : cyclic supervisor chain detection (depth-N)

## Phase 2 — Wiring, visibility, demo data
- B1 : CEU hours required seeded on RoleSpecificCredentialSeeder licenses ; demo seeder links 25%-50% of training records to credentials so CEU progress shows
- B2 : preview-email endpoint accepts draft payload (preview before save)
- B3 : preview-email accepts ?days= + ?supervisor= so exec can see all variants
- B4 : seeder adds 1 demo executive user with department=executive
- B6 : seeder adds 1 site-only "extra" definition + 1 site-disable override for non-mandatory def
- B7 : NotificationPreferenceService::seedDefaults() called from a new tenant-seed hook (or migration backfill)
- B8 : audit log page gets a "Credentials" filter chip / category
- E3 : catalog warns/blocks save when targets array is empty
- E4 : JobTitle destroy nulls out user.job_title for users referencing it

## Phase 3 — Polish + features
- F1 : localStorage-persist showAuditHistory toggle on per-user page
- F3 : PDF packet shows CEU hours logged / required when set
- F4 : PDF packet collapses long titles to 2-line layout (responsive cell)
- F5 : Dashboard column-header filter shows a "Clear column filter" pill
- F6 : Catalog list adds CEU badge ("30 CEU hrs/cycle") when ceu_hours_required > 0
- F8 : reportAssignment also sends an email to IT admin (not just dept alert)
- F9 : Read-only credential detail modal (click row to view without editing)
- G1 : Bulk-edit verification_source / notes on selected credentials (extends the bulk-renew flow)
- G3 : "CEU complete : ready to renew" auto-banner on My Credentials when ceu_hours_logged >= ceu_hours_required
- G4 : User-facing email batching — if multiple credentials hit reminders the same day, send ONE digest email instead of N
- G2 : Renewals calendar widget on Dashboard (next 6 months bar chart of expiry counts)

## Phase 4 — Tests + paywall update + final
- C : tests for verifyCredential, bulkRenew, exportPdf, reportAssignment, MyTeam, previewEmail, roleAssignmentOptions, updateRoleAssignment + edge cases
- Update paywall_and_vendor_gates_report.md with G5/G6 entries (committee + clinical privileges)
- Build, test, commit + push at every phase

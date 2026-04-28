# Credentials Audit Remediation — Step-Through Plan

**Goal:** close every audit finding except paywalled integrations (NPDB, state-board lookup APIs).
**Started:** 2026-04-28
**Owner:** Claude (autonomous)

## Phase 1 — Critical bugs (A1-A5)
**Commit:** `Credentials remediation [1/4] - bug fixes A1-A5`

- [ ] A1: Filter `replaced_by_credential_id IS NULL` in `CredentialExpirationAlertJob` + `WeeklyCredentialDigestJob` + `ItAdminDashboardController::expiringCredentials`
- [ ] A2: Extend `/compliance/personnel-credentials` payload : add verification_source, cms_status, definition link, driver fields, CEU progress, replaced_by chain
- [ ] A3: Add picker on `StaffCredentials.vue` defaults to MISSING-only with "show held credentials" toggle for legitimate dup cases (multi-state license)
- [ ] A4: Superseded rows render italic + faded with "Replaced ↗" badge on both `StaffCredentials.vue` (admin) and `MyCredentials.vue` (user). "Show audit history" toggle default-off
- [ ] A5: AuditLog::record on `destroyCredential`, `storeTraining`, `destroyTraining`

## Phase 2 — Wiring + visible-data fixes (B1-B4)
**Commit:** `Credentials remediation [2/4] - wiring B1-B4 + gate normalization`

- [ ] B1 (CRITICAL): Wire job_title + supervisor_user_id into user provisioning form. UserProvisioningController accepts both, Users.vue add/edit modals expose both, dropdowns sourced from JobTitle catalog + tenant users
- [ ] B2: Normalize gates : Executive can READ per-user staff credentials page (no edit). QA Compliance can READ per-user page. IT Admin retains full edit. Catalog stays exec-only edit but allow QA + IT Admin read access
- [ ] B3: Training rows surface their linked credential title in the table
- [ ] B4: My Credentials self-service shows CEU hours-logged / hours-required progress per credential

## Phase 3 — Compliance gaps + encryption (C1-C4, C6)
**Commit:** `Credentials remediation [3/4] - CMS catalog additions + DEA encryption`

- [ ] C6: Encrypt `license_number` column (Laravel `encrypted` cast + one-shot migration to re-encrypt existing rows)
- [ ] C3: New baseline definition `oig_sam_exclusion_check` — monthly cadence, all-workforce target, doc required (LEIE + SAM screenshot)
- [ ] C4: New baseline definition `pace_orientation_8h` — single-event credential, all-workforce, doc required, "must complete within 30 days of hire"
- [ ] C1: New baseline definition `annual_competency_evaluation` — annual cadence, all clinical, doc required (signed eval form). Plus a structured `notes` template hint
- [ ] C2: New baseline definition `supervising_physician_agreement` — annual cadence, targets job_title=np + pa, doc required

## Phase 4 — Polish (D1-D12)
**Commit:** `Credentials remediation [4/4] - polish D1-D12`

- [ ] D1: Container widths consistent across all four Org Settings tabs (1280px max, expand on wider)
- [ ] D2: Search box + type-filter chips on Credentials Catalog
- [ ] D3: Filter chips on per-user Credentials page (All / Expired / Expiring / Missing-required / Pending verify)
- [ ] D4: "Next expiration" badge on user dropdown next to "My Credentials"
- [ ] D5: Per-user printable credentials packet PDF (just the credentials list with status, expirations, doc-on-file flag — no embed of the docs themselves; surveyor packet)
- [ ] D6: Bulk renewal action — select multiple users on dashboard drilldown → "Bulk update expiry" modal sets new expires_at + verification_source for all
- [ ] D7: New `/my-team` page for any user with directReports : credential status of their reports
- [ ] D8: One-click "✓ Verify" button on pending rows for IT Admin (sets cms_status=active, verified_at=now, verified_by=current user)
- [ ] D10: Cadence preview line restored : "Will fire at: 90, 45, 30, 14, 0 days"
- [ ] D11: "Report incorrect assignment" link on My Credentials → emails IT Admin with user's note
- [ ] D12: Email preview button in Catalog edit modal → renders CredentialExpiringMail HTML for visual review

## Verification
- [ ] All migrations run clean
- [ ] Full PHPUnit suite passes
- [ ] Build clean
- [ ] Push to origin/main between every phase

## Out of scope (paywall / architectural)
- NPDB integration (real PSV API)
- State board lookup automation
- C5 state-specific background re-check cadence (configurable today via per-site override)
- C7 employee-health PHI access split (architectural, would need new module)
- C8 credentialing committee workflow (separate module)
- C9 clinical privileges (separate module)

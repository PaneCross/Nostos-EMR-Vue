# Credentials V1 — Org-Catalog + User Tracking Buildout

**Started:** 2026-04-28
**Owner:** Claude (autonomous build at TJ's direction)
**Scope:** Org-defined credential catalog with site overrides, per-user tracking with PDF docs, layered email reminders, compliance dashboard, executive-defined job titles, supervisor field, tabbed Org Settings.

---

## Milestone 1 — DB Foundation
**Commit message:** `Credentials V1 [1/8] - schema for catalog, job titles, supervisor, PSV`

- [ ] Migration: add `job_title` (nullable string, FK-ish) + `supervisor_user_id` (nullable FK self) to `shared_users`
- [ ] Migration: create `emr_job_titles` (id, tenant_id, code, label, is_active, sort_order, timestamps, soft delete) — small org-controlled vocab
- [ ] Migration: create `emr_credential_definitions` (id, tenant_id, code, title, credential_type, requires_psv bool, is_cms_mandatory bool, reminder_cadence_days json, default_doc_required bool, is_active bool, sort_order, timestamps, softdeletes)
- [ ] Migration: create `emr_credential_definition_targets` (id, definition_id, target_kind enum {department,job_title,designation}, target_value string, timestamps) — OR semantics across rows
- [ ] Migration: create `emr_credential_definition_site_overrides` (id, tenant_id, site_id FK, definition_id FK, action enum {disabled,extra}, timestamps) — disabled only allowed for non-CMS-mandatory
- [ ] Migration: alter `emr_staff_credentials` to add `credential_definition_id` (nullable FK), `verification_source` enum (state_board|npdb|uploaded_doc|self_attestation|other), `cms_status` enum (active|expired|suspended|revoked|pending), `replaced_by_credential_id` nullable self-FK (for renewal history immutability)
- [ ] Models: `JobTitle`, `CredentialDefinition`, `CredentialDefinitionTarget`, `CredentialDefinitionSiteOverride`, plus relations on User + StaffCredential
- [ ] Seeder: `CmsCredentialBaselineSeeder` — seeds 8 mandatory definitions per tenant: HIPAA Annual Training, Fire Safety Annual, Infection Control Annual, Abuse/Neglect Reporting Training, Restraint Training (annual), TB Clearance, Background Check, BLS for clinical depts
- [ ] Seeder: default job titles per tenant (RN, LPN, CNA, MA, MD, NP, PA, MSW, LCSW, OT, PT, RD, Driver, Scheduler, Center Manager, Other)
- [ ] Run migrations + seed on dev DB
- [ ] Verify with `php artisan tinker` queries

## Milestone 2 — Backend Services + Controllers
**Commit message:** `Credentials V1 [2/8] - controllers, services, gap detection`

- [ ] `CredentialDefinitionService::activeForUser(User): Collection` — resolves which definitions apply to a given user (dept OR job_title OR designation match, minus site-disabled, plus site-extras)
- [ ] `CredentialDefinitionService::missingForUser(User): Collection` — returns required definitions where user has no active credential
- [ ] `CredentialComplianceService::matrixForTenant(int $tenantId): array` — produces dashboard payload: rows=definitions, cols=departments, cells={users_required, users_compliant, users_missing, users_expired, users_expiring_30d}
- [ ] `JobTitleController` (executive-gated) — index/store/update/destroy
- [ ] `CredentialDefinitionController` (executive-gated) — index/store/update/destroy/siteOverride
- [ ] Extend `StaffCredentialController::storeCredential` + `updateCredential` to accept multipart PDF upload (max 10MB, mime pdf), store at `tenant/{id}/credentials/{user_id}/{cred_id}.pdf`
- [ ] Add `MyCredentialsController::index` + `uploadRenewal` — staff self-service view
- [ ] `ExecutiveDashboardController::credentials` — returns compliance matrix view

## Milestone 3 — Tabbed Org Settings + Job Titles UI
**Commit message:** `Credentials V1 [3/8] - tabbed Org Settings, Job Titles tab`

- [ ] Refactor `Pages/Executive/OrgSettings.vue` into tabs: `Designations | Credentials | Job Titles | Notifications`
- [ ] Tab persistence via `?tab=` query param
- [ ] Build Job Titles tab: list, add, edit, deactivate (no hard delete to preserve historical user assignments)
- [ ] Wire Org Settings nav badge if any tab has unconfigured items

## Milestone 4 — Credentials Catalog UI
**Commit message:** `Credentials V1 [4/8] - catalog CRUD, target picker, site overrides`

- [ ] Credentials tab: definition list (with CMS-mandatory lock badge), filter by type
- [ ] Add/edit definition modal with three target columns (Departments / Job Titles / Designations checkbox grids)
- [ ] Reminder cadence editor (default `[90, 30, 14, 0]`, comma-separated input with chip preview)
- [ ] PSV required toggle, CMS-mandatory locked
- [ ] Per-site overrides section (collapsible per site — toggle definition off if not CMS-mandatory, or add site-only extras)

## Milestone 5 — Fix Existing Page + Wire Uploads
**Commit message:** `Credentials V1 [5/8] - fix StaffCredentials edit + PDF upload`

- [ ] Add edit modal to `Pages/ItAdmin/StaffCredentials.vue` (was broken — only had add/delete)
- [ ] Wire PDF upload on add + edit (multipart form, drag-drop area, current-doc preview link)
- [ ] Add definition picker dropdown — when picking from catalog, prefill type/title/PSV/cadence
- [ ] Add verification_source dropdown
- [ ] Add cms_status field (active/suspended/revoked override)
- [ ] Show "missing required credentials" warning banner for this user

## Milestone 6 — My Credentials User Page
**Commit message:** `Credentials V1 [6/8] - My Credentials self-service page`

- [ ] Add "My Credentials" link to user dropdown (next to Notification Preferences)
- [ ] Build `Pages/User/MyCredentials.vue`: list of held credentials with status badges, list of missing required ones, upload-renewal action per credential, view current doc link
- [ ] Routes: `/my-credentials` (GET) + `/my-credentials/{credential}/renewal` (POST upload)
- [ ] Audit log on renewal upload

## Milestone 7 — Compliance Dashboard
**Commit message:** `Credentials V1 [7/8] - executive compliance dashboard`

- [ ] Add nav link in Executive section: `Credentials Dashboard`
- [ ] Build `Pages/Executive/CredentialsDashboard.vue`:
   - Summary cards: total active credentials, expiring 30d, expired, missing-required count
   - Coverage matrix: definitions × departments, % compliant per cell, click cell drills into user list
   - Filter: by definition / by department / by status
   - Export CSV button
- [ ] Backend: `ExecutiveDashboardController::credentials` returns matrix payload
- [ ] Per-cell drill-down endpoint returns user list

## Milestone 8 — Notifications + Tests
**Commit message:** `Credentials V1 [8/8] - layered escalation, weekly digest, tests`

- [ ] Update `CredentialExpirationAlertJob`:
   - Layered recipients per cadence step: 90/60/30 → user; 14 → +supervisor; 0/overdue → +QA Compliance
   - Honor per-definition `reminder_cadence_days` instead of hardcoded array
   - Email mailables: `CredentialExpiringMail`, `CredentialOverdueMail`
- [ ] New `WeeklyCredentialDigestJob` — Monday 06:00:
   - Per-tenant aggregate to it_admin + qa_compliance: expiring next 30d, overdue, missing-required by department
   - `CredentialDigestMail`
- [ ] Add notification preference catalog keys: `credential_self_reminder` (default on, optional), `credential_supervisor_cc_14d` (default on, optional), `credential_overdue_qa` (required, locked on), `credential_weekly_digest` (default on, optional)
- [ ] Schedule digest job in `routes/console.php` (Mon 06:00 staggered)
- [ ] Tests:
   - `CredentialDefinitionTargetingTest` — matchForUser returns correct definitions across dept/title/designation rules
   - `CredentialMissingDetectionTest` — gap detection
   - `CredentialPdfUploadTest` — upload + retrieve cycle, mime validation
   - `MyCredentialsPageTest` — user sees only their own data
   - `CredentialEscalationTest` — verifies right recipients get right emails at each cadence step
   - `WeeklyCredentialDigestJobTest` — digest content + dedup
   - `OrgSettingsCredentialsTabTest` — catalog CRUD, site override, CMS-mandatory locked
   - `JobTitleCatalogTest` — basic CRUD + soft-delete preserves user assignments
- [ ] Run full suite, verify green
- [ ] Build, verify no console errors
- [ ] Final commit + push

---

## Conventions
- All migrations follow `2026_04_28_*` date prefix
- All services in `app/Services/Credentials/`
- All Vue pages dark-mode compatible per CLAUDE.md
- Audit log every credential write (create/update/upload/renewal)
- Tenant guard on every endpoint
- Site override scoping: disabling CMS-mandatory definitions is silently rejected with 422

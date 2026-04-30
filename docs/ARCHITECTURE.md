# NostosEMR — Architecture & Codebase Guide

Welcome. If you're reading this on day one, you're in the right place. This document is the dev team's reference for how this codebase is laid out, why decisions were made, and what every healthcare acronym in the code actually means. Keep it open in a second tab while you read code for the first month.

> **Audience assumption:** you are a competent web engineer. You are NOT assumed to know anything about PACE, CMS regulations, HIPAA citations, or healthcare workflows. Everything domain-specific is glossed in plain English at first use.

---

## Contents

1. [What is this project?](#1-what-is-this-project)
2. [PACE in plain English (domain primer)](#2-pace-in-plain-english-domain-primer)
3. [Stack at a glance](#3-stack-at-a-glance)
4. [Folder map — where everything lives](#4-folder-map--where-everything-lives)
5. [The 10 cross-cutting patterns](#5-the-10-cross-cutting-patterns)
6. [Glossary — acronyms](#6-glossary--acronyms)
7. [Glossary — CFR / HIPAA citations](#7-glossary--cfr--hipaa-citations)
8. [Decision log — "why is this done this way?"](#8-decision-log--why-is-this-done-this-way)
9. [Suggested first-week reading order](#9-suggested-first-week-reading-order)
10. [Where to ask deeper questions](#10-where-to-ask-deeper-questions)

---

## 1. What is this project?

**NostosEMR** is an Electronic Medical Record (EMR) system purpose-built for **PACE programs** — small healthcare organizations that take care of frail elderly people in their communities under a federal Medicare/Medicaid program.

A PACE program is unusual: it is simultaneously the doctor, the home-care agency, the day-care center, the pharmacy, the transportation service, and the insurance company for its members. So the EMR has to do everything those organizations do — track diagnoses, schedule rides, dispense meds, file claims, run quality reports — all in one place, all tenant-scoped (one PACE org per tenant), and all wrapped in CMS and HIPAA compliance.

The product is multi-tenant by design. One installation can host many PACE organizations; each org sees only its own data; staff log in scoped to their tenant + site + department.

---

## 2. PACE in plain English (domain primer)

### What PACE is

**PACE** = **Programs of All-Inclusive Care for the Elderly**. It is a federally-defined Medicare/Medicaid program that lets people aged 55+ who are sick enough to qualify for a nursing home (the "nursing-facility level of care" / **NF-LOC** determination) stay in their home and community instead. CMS pays the PACE organization a flat monthly amount per member ("capitation") and the org is on the hook to provide every kind of care that member needs.

**Members** of a PACE program are called **participants** in CMS terminology — that's the term you'll see throughout the codebase. Pre-enrollment they're called **potential enrollees** (don't confuse the two — see `feedback_pace_terminology.md` in the memory folder for the rules).

### The PACE care model

Every participant has an **Interdisciplinary Team** (**IDT**) — typically Primary Care, Nursing, Social Work, Therapies (PT/OT/ST), Activities, Dietary, Home Care, Transportation, plus the PACE Center Director. The IDT meets weekly to review members and plan care. Federal law (42 CFR §460.104) requires a full reassessment at least every 6 months, and sooner when a "significant change" happens (hospitalization, fall, new diagnosis).

The **day center** is the physical hub. Members are typically transported there 1–5 days a week for clinic visits, meals, rehab, social activities. The PACE org runs the transportation, the kitchen, the gym, the clinic — all of it.

### What CMS asks of us

- Report monthly enrollment and disenrollment files
- Report quarterly quality data (falls, pressure injuries, immunization rates)
- Report annual member surveys (HOS-M)
- Report every encounter (clinic visit, lab, procedure, hospitalization) for risk-adjustment payment
- Report every Part D drug dispensed (PDE — Prescription Drug Event)
- Survey-readiness: keep audit-pull universes (immutable rosters of grievances, appeals, denials, restraints, etc.) ready to hand a CMS auditor on demand
- Operate a Quality Assurance / Performance Improvement (QAPI) program with annual self-evaluation

### What HIPAA asks of us

- Encrypt PHI (Protected Health Information) at rest and in transit
- Log every PHI access (audit trail)
- Restrict access by role and need-to-know
- Honor member rights: right to access (§164.524), right to amend (§164.526), accounting of disclosures (§164.528)
- Notify CMS, members, and (if >500 affected) HHS + media within 60 days of a breach
- Sign Business Associate Agreements (BAAs) with every vendor that touches PHI on our behalf
- Run an annual Security Risk Analysis (SRA)

These rules drive most of the schemas, workflows, and scheduled jobs in this codebase. When you see a CFR § citation in a comment, see [Section 7](#7-glossary--cfr--hipaa-citations).

---

## 3. Stack at a glance

| Layer | Technology | Why this choice |
|---|---|---|
| **Backend framework** | Laravel 12 (PHP 8.3+) | Mature, batteries-included, the team's strongest skill set when this was started; excellent ORM and queue infrastructure |
| **Frontend framework** | Vue 3.5 (Composition API) | Predictable, fast, official TypeScript support, smaller learning curve than React for clinical staff who eventually become contributors |
| **Server-driven SPA** | Inertia.js | Lets Laravel controllers return Vue components directly. No separate API layer to maintain — Inertia is just JSON over HTTP that Vue renders client-side |
| **CSS** | Tailwind CSS v4 | Utility-first, ships only used classes, dark-mode token system already standardized |
| **Bundler / dev server** | Vite | Replaces Webpack; near-instant HMR for Vue |
| **Database** | PostgreSQL 16 | Strong type system, CHECK constraints, JSONB, partial indexes — all used heavily for HIPAA constraints and clinical enums |
| **Cache / sessions / queues** | Redis 7 | Session, cache driver, queue driver |
| **Real-time** | Laravel Reverb (WebSockets) | First-party Laravel WebSocket server. Replaces external Pusher dependency. Used for alerts + chat live-updates |
| **Email (dev)** | Mailpit | Local SMTP catcher; never delivers anything externally |
| **Email (prod)** | Postmark / SES (TBD per customer) | Paywall item — see `docs/GO_LIVE.md` |
| **Container orchestration** | Laravel Sail (Docker Compose) | Standard local dev environment; production should use the same images on a real BAA-grade host |
| **Test runner** | PHPUnit 11 + Paratest | 2400+ tests; paratest gives 2 workers (3+ hits factory races) |
| **Frontend tests** | Behavioral via Pest/PHPUnit hitting Inertia | No Cypress/Playwright yet (recommended next) |
| **Auth** | Laravel Fortify + custom OTP + Socialite (Google/Yahoo) | Passwordless OTP is the primary login; SSO is offered for organizations that want it |
| **PDFs** | DomPDF (Laravel wrapper) | Server-side rendering; used for facesheet, care plan, breach letter, denial notice |

### What this stack means in practice

- **You write a Laravel controller, you return `Inertia::render('Path/To/Component', $props)`, the Vue component receives those props.** The browser only ever talks to Laravel, not a separate API.
- **For real-time updates**, controllers/jobs `broadcast(new SomeEvent($payload))` and Vue components subscribe via the `useEcho()` composable on the channels defined in `routes/channels.php`.
- **For background work**, dispatch a Job. The cron-driven scheduler (in `routes/console.php`) handles deadlines.
- **For data**, write a migration, write a model, write a seeder if needed. Models are tenant-scoped via the `forTenant()` scope; never query without it in user-facing endpoints.

---

## 4. Folder map — where everything lives

```
nostosemr-vue/
├── app/                                  ← Laravel application code
│   ├── Console/Commands/                 ← Custom artisan commands
│   ├── Events/                           ← Broadcast events (Reverb / WebSockets)
│   ├── Http/
│   │   ├── Controllers/                  ← All HTTP entry points (~150 files)
│   │   │   ├── Auth/                     ← Login / OTP / OAuth
│   │   │   └── Dashboards/               ← One controller per department dashboard
│   │   ├── Middleware/                   ← Tenant scoping, auth, audit logging
│   │   └── Requests/                     ← FormRequest validation classes (~43 files)
│   ├── Jobs/                             ← Queued + scheduled jobs (~34 files)
│   ├── Models/                           ← Eloquent models (~157 files)
│   ├── Notifications/                    ← Email/SMS/in-app notifications
│   ├── Observers/                        ← Model lifecycle hooks
│   ├── Policies/                         ← Authorization policies
│   └── Services/                         ← Business-logic orchestration (~76 files)
├── bootstrap/                            ← Framework bootstrap (rarely touched)
├── config/                               ← Configuration files
│   └── emr_note_templates.php            ← The only custom config file
├── database/
│   ├── factories/                        ← Test data factories
│   ├── migrations/                       ← ~203 schema migration files
│   └── seeders/                          ← ~45 seeder files (split by phase)
├── docs/                                 ← Documentation (this file + companions)
│   ├── ARCHITECTURE.md                   ← You are here
│   ├── GO_LIVE.md                        ← What's still pending for production
│   ├── compliance/                       ← BAA, HIPAA training, privacy/security
│   ├── permission-matrix.md              ← Department × role × module access matrix
│   ├── runbooks/                         ← Tenant onboarding, admin operations
│   ├── security/                         ← Breach runbook, DR plan, pen-test plan
│   └── training/                         ← Role-specific one-pagers
├── public/                               ← Web-served static assets + Vite build output
├── resources/
│   ├── css/                              ← Global Tailwind + a few custom files
│   ├── js/                               ← All Vue source code
│   │   ├── app.ts                        ← Application bootstrap (Inertia + axios + Reverb)
│   │   ├── Components/                   ← Reusable shared components (~9 files)
│   │   ├── Layouts/                      ← AppShell.vue (root layout for every page)
│   │   └── Pages/                        ← ~175 Inertia page components
│   │       └── Participants/Tabs/        ← The 27+ participant detail tabs
│   └── views/                            ← Blade templates (login + PDFs only)
├── routes/
│   ├── channels.php                      ← WebSocket channel authorization
│   ├── console.php                       ← Scheduled jobs (regulatory clocks!)
│   └── web.php                           ← All HTTP routes (~1655 lines, mapped at top)
├── storage/                              ← Logs, sessions, file uploads
├── tests/
│   ├── Concerns/                         ← Reusable test traits (FreezesTime)
│   ├── Feature/                          ← End-to-end tests (~250+ files)
│   └── Unit/                             ← Pure-PHP unit tests (~50 files)
└── vendor/                               ← Composer dependencies
```

### When you need to add something — where does it go?

| You want to… | Add a file to… |
|---|---|
| Add a new HTTP endpoint | `app/Http/Controllers/` (extend or create) + `routes/web.php` |
| Validate request body | `app/Http/Requests/` |
| Add a new database table | `database/migrations/` (timestamped filename) |
| Add an Eloquent model | `app/Models/` |
| Add reusable business logic | `app/Services/` (NOT in the controller) |
| Add a queued background job | `app/Jobs/` (define `$tries`, `$timeout`, `backoff()` per Y4 convention) |
| Add a recurring cron job | `routes/console.php` (call `Schedule::job(new YourJob)->daily()`) |
| Add a new participant tab | `resources/js/Pages/Participants/Tabs/<Name>Tab.vue` + a controller endpoint |
| Add a department dashboard | `app/Http/Controllers/Dashboards/` + `resources/js/Pages/Dashboard/Depts/` |
| Add a Reverb broadcast event | `app/Events/` + register channel in `routes/channels.php` |
| Add a feature flag / config | `config/` (only if there isn't a more natural home) |
| Add a HIPAA disclosure surface | Wire into `PhiDisclosureService::record()` — see Q2 phase memo |

---

## 5. The 10 cross-cutting patterns

These are the conventions you'll see hundreds of times in the codebase. Internalize them in week one.

### 5.1 Tenant scoping

Every clinical query must filter by `tenant_id`. Cross-tenant data leakage is a **HIPAA breach** — see §164.312. The standard pattern:

```php
// In a controller — note effectiveTenantId(), not tenant_id directly
$rows = Participant::forTenant($user->effectiveTenantId())->get();

// In a model
public function scopeForTenant(Builder $q, int $tenantId): Builder
{
    return $q->where('tenant_id', $tenantId);
}
```

**`User::effectiveTenantId()` vs `User::tenant_id`** — important distinction.
A super-admin can switch tenant context via the header dropdown (sets
`session('active_tenant_id')`). `effectiveTenantId()` honours that override
and returns the active tenant ; `tenant_id` always returns the SA's home
tenant. Use `effectiveTenantId()` for any data-display query (so the SA
sees the tenant they're acting inside). Use `tenant_id` directly for audit
logging — the audit trail should record the SA's HOME tenant, not the
context they happened to be acting in (audit honesty). Regular users get
`tenant_id` either way ; they have no override capability.

The same pattern applies one tier down for site context :
`session('active_site_id')` lets executives + SAs switch site, resolved by
controllers that read `request->attributes->get('active_site_id')`.

Endpoints :
- `POST /tenant-context/switch` (SA only) — body `{tenant_id: int}`
- `DELETE /tenant-context` — revert to home tenant
- `POST /site-context/switch` — body `{site_id: int}`
- `DELETE /site-context` — revert to home site

Cross-tenant guards have full test coverage — see `V6PreWaveSCrossTenantGuardsTest`,
`TenantContextTest`, and the wider `*CrossTenant*` suite.

### 5.2 Audit logging on every read + write

Every PHI access creates a row in `shared_audit_logs` (immutable, append-only). The standard pattern:

```php
AuditLog::record(
    action:       'participant.viewed',
    tenantId:     $user->tenant_id,
    userId:       $user->id,
    resourceType: 'participant',
    resourceId:   $participant->id,
    description:  'Detail page viewed',
);
```

This is a HIPAA Security Rule requirement (§164.312(b)). The `AuditLog` model overrides `save()` to throw if you try to update a row.

### 5.3 Append-only / immutable models

Several domain models cannot be edited after create — only added to or superseded with new rows. Each one overrides `save()` to throw. The list:

- `AuditLog` — every action ever taken in the system
- `ClinicalNote` (after signing — drafts can still be edited)
- `EmarRecord` — every medication-pass event
- `PhiDisclosure` — §164.528 "who saw what" log
- `BreakGlassEvent` — emergency-access overrides
- `IntegrationLog` — every inbound HL7 / lab / NCPDP message

If you need to "change" one of these, you create a new row that supersedes the old one — never an UPDATE.

### 5.4 V5 axios interceptor + Toaster (global error UX)

Every Vue component that does an `axios.post(...)` benefits from this without writing any code:
- `5xx`, `ERR_NETWORK`, `403`, `409` → toast appears in the top-right (`Components/Toaster.vue`)
- `419` (CSRF expiry) → page reloads to refresh the token
- `422` (validation) → skipped here; per-component forms render their own inline errors
- `401` (auth) → skipped here; redirect handles it

Wired in `resources/js/app.ts`. Custom event bus key: `'nostos:toast'`. Components can manually emit toasts via `window.dispatchEvent(new CustomEvent('nostos:toast', { detail: {...}}))`.

### 5.5 FormRequest validation pattern

User input on every write endpoint is validated by an `app/Http/Requests/` class. The class:
1. `authorize()` returns whether the user is allowed (often delegates to a controller-side check)
2. `rules()` defines field-by-field rules
3. `messages()` (optional) overrides Laravel-default error strings with PACE-specific ones

When adding a new write endpoint, **always** create a FormRequest. Controllers should NEVER call `$request->validate(...)` inline — that bypasses the auth gate and makes testing harder.

### 5.6 Job conventions ($tries / $timeout / backoff)

Every queued job declares:

```php
class FooJob implements ShouldQueue
{
    public int $tries = 3;        // retry up to 3x
    public int $timeout = 120;    // hard kill after 2 min

    public function backoff(): array
    {
        return [60, 180, 360];    // jittered exponential
    }
}
```

This was set as a Phase Y4 convention after the Audit-13 finding that 4 long-running jobs had `$tries=3` but no `$timeout` — a worst-case 835 file parser would silently die mid-transaction.

### 5.7 Optimistic locking via `revision` column

Where multiple staff can edit the same record (CarePlan, IdtMeeting, AmendmentRequest), the model has a `revision` column. The save path is:
1. Client reads record, gets `revision = 3`
2. Client sends update with `expected_revision: 3`
3. Server: `WHERE id = X AND revision = 3` → if 0 rows updated, return `409 Conflict`
4. On success, `revision` is incremented

Phase R7 (IDT) and Phase X3 (CarePlan + AmendmentRequest) added this pattern.

### 5.8 DemoEnvironmentSeeder vs feature-specific seeders

`database/seeders/DemoEnvironmentSeeder.php` is the master orchestrator that boots a populated demo tenant. It calls many smaller phase-specific seeders (Phase4DataSeeder, Phase5ADataSeeder, W42DataSeeder, etc.) in order.

`DatabaseSeeder` (the Laravel default entry point) just delegates to `DemoEnvironmentSeeder` — so `php artisan migrate:fresh --seed` always produces a usable demo tenant.

For production, you DON'T run any seeder. You provision a real tenant via `TenantOnboardingService` (see `docs/runbooks/tenant-onboarding.md`).

### 5.9 The `forTenant()` scope as a scope, not a global filter

Models support tenant scoping but don't enforce it via a global scope, intentionally. Reasons:
1. SuperAdmin needs cross-tenant access (e.g. `SuperAdminPanelController`)
2. Some scheduled jobs span tenants (e.g. system-wide maintenance)
3. Global scopes hide bugs — explicit `->forTenant()` is louder when missing

Trade-off: you must remember to call it. The cross-tenant test suite (Wave T + V) catches forgotten scopes in CI.

### 5.10 Audit-pull universes (compliance/* endpoints)

Routes like `/compliance/grievances`, `/compliance/roi`, `/compliance/sentinel-events` return flat unpaginated JSON rosters auditors can paste into their workpapers. They live in `app/Http/Controllers/ComplianceController.php` and aliases. They are tenant-scoped, dept-gated to QA + Compliance + IT Admin + Super Admin, and intentionally **don't paginate** — auditors want everything.

When CMS shows up for a survey, you turn these on, hit print, and walk in with a binder.

---

## 6. Glossary — acronyms

Whenever you see one of these in code or comments, this is what it means.

### People + organizations
- **CMS** — Centers for Medicare & Medicaid Services (the federal regulator/payer)
- **HHS** — U.S. Department of Health and Human Services (CMS's parent)
- **OCR** — Office for Civil Rights (the HHS division that enforces HIPAA)
- **State Medicaid Agency / SMA** — the state-level Medicaid office
- **HPMS** — Health Plan Management System (CMS's contractor portal where PACE orgs upload monthly enrollment files)
- **EDS** — Encounter Data System (CMS's intake for encounter records)
- **CSSC** — Customer Service & Support Center (the CMS contractor that receives EDS submissions)

### Programs + roles
- **PACE** — Programs of All-Inclusive Care for the Elderly
- **NPA** — National PACE Association (the trade association)
- **IDT** — Interdisciplinary Team (the weekly clinical team meeting)
- **PCP** — Primary Care Provider
- **NP** — Nurse Practitioner
- **PT / OT / ST** — Physical / Occupational / Speech Therapist
- **SW** — Social Worker
- **POA** — Power of Attorney (legal representative)
- **DPOA** — Durable Power of Attorney
- **DNR** — Do Not Resuscitate
- **DNH** — Do Not Hospitalize

### Identifiers
- **MRN** — Medical Record Number (our internal per-tenant patient ID)
- **MBI** — Medicare Beneficiary Identifier (the patient's federal Medicare ID, replaced HICN in 2018)
- **NPI** — National Provider Identifier (10-digit federal ID for clinicians + organizations)
- **H-Number** — a PACE org's CMS Contract ID (e.g. "H1234")

### Clinical
- **EMR** — Electronic Medical Record (this app)
- **EHR** — Electronic Health Record (often used synonymously)
- **EHI** — Electronic Health Information (the patient's full record; 21st Century Cures Act gives them the right to export it)
- **EMAR / eMAR** — Electronic Medication Administration Record (the per-dose nurse log)
- **BCMA** — Bedside / Barcode Medication Administration (scan-the-wristband-and-bottle workflow)
- **CPOE** — Computerized Provider Order Entry (federal requirement for orders to be entered electronically by the ordering clinician)
- **ADT** — Admission/Discharge/Transfer (HL7 message class tracking patient location)
- **A01 / A03 / A08** — specific HL7 ADT events (admit / discharge / update)
- **HL7** — Health Level 7 (industry messaging standard for clinical data)
- **FHIR** — Fast Healthcare Interoperability Resources (modern HL7 REST API)
- **CCDA** — Consolidated Clinical Document Architecture (federal XML standard for clinical summaries)
- **SOAP** — Subjective / Objective / Assessment / Plan (clinical note structure)
- **ICD-10** — International Classification of Diseases v10 (diagnosis codes)
- **CPT** — Current Procedural Terminology (procedure codes)
- **LOINC** — Logical Observation Identifiers Names and Codes (lab test codes)
- **SNOMED** — Systematized Nomenclature of Medicine (clinical concept codes)
- **RxNorm** — RxNorm (medication codes from the National Library of Medicine)
- **ADL** — Activities of Daily Living (bathing, dressing, eating, toileting, transferring, continence)
- **IADL** — Instrumental ADLs (cooking, shopping, finances, transport, meds, phone, housework, laundry)
- **ADE** — Adverse Drug Event (a harmful medication reaction)
- **INR** — International Normalized Ratio (warfarin anticoagulation lab)
- **DOAC** — Direct-acting Oral Anticoagulant (alternative to warfarin; doesn't need INR monitoring)
- **TB** — Tuberculosis (annual screening required by §460.71)
- **PPD / Mantoux** — the TB skin test
- **NPUAP** — National Pressure Ulcer Advisory Panel (wound staging system)
- **NF-LOC** — Nursing-Facility Level of Care (annual recertification per §460.160)
- **MDS** — Minimum Data Set (the nursing-home assessment instrument)
- **HOS-M** — Health Outcomes Survey – Modified (annual CMS-required PACE member survey)
- **PHQ-9** — Patient Health Questionnaire 9-item (depression screen)
- **AUDIT-C** / **CAGE** / **DAST-10** — substance-use screens
- **MoCA** — Montreal Cognitive Assessment
- **Braden** — pressure-ulcer risk scale
- **Morse** — fall-risk scale
- **Lawton** — IADL scoring scale
- **Katz** — ADL scoring scale
- **OHAT** — Oral Health Assessment Tool
- **SDOH** — Social Determinants of Health (housing, food security, transport, etc.)
- **PRO** — Patient-Reported Outcome
- **RCA** — Root Cause Analysis
- **QAPI** — Quality Assessment / Performance Improvement (the formal PACE quality program)

### Billing + payment
- **RAF** — Risk Adjustment Factor (per-member CMS payment multiplier)
- **HCC** — Hierarchical Condition Category (CMS diagnosis grouping that drives RAF)
- **PMPM** — Per Member Per Month (the unit of capitation payment)
- **PDE** — Prescription Drug Event (every Part D dispensing record submitted to CMS)
- **TrOOP** — True Out-Of-Pocket (running total of what a member has personally paid; crossing thresholds changes their cost-share)
- **MARx** — the CMS system that pays Part D claims
- **EDI** — Electronic Data Interchange (the X12 family of healthcare messages)
- **X12 5010A1** — the HIPAA-mandated EDI version
- **837P** — X12 transaction set for Professional medical claims (clinician encounters)
- **837I** — X12 institutional/hospital claims
- **835** — X12 remittance / payment file (CMS's response to our claims)
- **ERA** — Electronic Remittance Advice (the 835 message itself)
- **CARC** — Claim Adjustment Reason Code (why a claim was reduced/denied)
- **POS** — Place of Service (2-digit code; "11" = office, "12" = home, "32" = nursing facility)
- **CLM** — Claim segment (header row inside an 837P)
- **TPA** — Third-Party Administrator
- **HMO** — Health Maintenance Organization
- **SNP** — Special Needs Plan (a Medicare Advantage variant)
- **IBNR** — Incurred But Not Reported (claims liability accrual estimate)
- **MMR** / **TRR** — Monthly Membership Report / Transaction Reply Report (CMS responses to enrollment submissions)

### Workflows + status
- **ROI** — Release of Information (HIPAA right-to-access workflow; **NOT return-on-investment**)
- **SDR** — Service Delivery Request (an internal hand-off between PACE departments, e.g. PCP asks pharmacy to fill a script). Has a 72-hour CMS clock per §460.121.
- **PA** — Prior Authorization (insurance approval before a service)
- **SRA** — Security Risk Analysis (annual HIPAA self-audit)
- **BAA** — Business Associate Agreement (HIPAA contract with vendors that touch PHI)
- **NPP** — Notice of Privacy Practices (the HIPAA notice we give every new participant)
- **PSDA** — Patient Self-Determination Act (advance directives)
- **OTP** — One-Time Password (login second-factor)
- **SAML** / **OAuth2** — single-sign-on protocols
- **RBAC** — Role-Based Access Control (department × role × module permission matrix)

### Technical
- **PHI** — Protected Health Information (HIPAA-covered patient data)
- **EHI** — Electronic Health Information (broader than PHI; full clinical record)
- **API** — Application Programming Interface
- **JWT** — JSON Web Token
- **PKCE** — Proof Key for Code Exchange (OAuth2 PKCE extension)
- **HMAC** — Hash-based Message Authentication Code (webhook signature)
- **NDJSON** — Newline-Delimited JSON (FHIR Bulk Export format)
- **PWA** — Progressive Web App
- **HMR** — Hot Module Replacement (Vite dev feature)

---

## 7. Glossary — CFR / HIPAA citations

These are the federal regulations the codebase enforces. When you see a `§` in a comment, this is what it means. CFR sections take this form: `42 CFR §460.x` (Title 42 = public health, Title 45 = HIPAA).

### 42 CFR §460 — PACE Program rules

- **§460.71** — TB screening; required annually for participants and staff
- **§460.86** — Hospice services; PACE may continue to provide care alongside hospice
- **§460.90** — Services must be ordered and documented (CPOE requirement)
- **§460.91** — Personnel access requirements (RBAC enforcement basis)
- **§460.96** — Advance directives; participant right to specify
- **§460.102** — IDT (Interdisciplinary Team) composition and responsibilities
- **§460.104(b)** — Significant-change reassessment; full IDT review within 30 days of a significant change
- **§460.104(c)** — Routine reassessment cadence: at least every 6 months
- **§460.104(d)** — Participant participation: must offer participant a chance to participate in care plan development
- **§460.113** — Enrollment requirements
- **§460.116** — Disenrollment notice + transition plan
- **§460.120** — Grievance procedures (30-day standard resolution)
- **§460.121** — Service Delivery Request timing (72 hours / 24 hours if urgent)
- **§460.122** — Service denial + appeal workflow (30-day standard / 72-hour expedited)
- **§460.136-140** — Quality Assessment / Performance Improvement (QAPI) requirements
- **§460.150-158** — Enrollment / informed-consent requirements
- **§460.156** — Informed consent at enrollment
- **§460.160(b)(2)** — NF-LOC annual recertification
- **§460.180-186** — Capitation payment structure
- **§460.196** — HPMS reporting requirements
- **§460.200** — Annual quality-assurance evaluation

### 42 CFR §405 / §422 / §423 — broader Medicare rules

- **§405.942** — Medicare appeal window (120-day standard)
- **§422.310** — Encounter data submission requirements
- **§423.329(b)** — Part D PDE submission requirements

### 45 CFR §160 / §162 / §164 — HIPAA

- **§162.1102** — HIPAA Electronic Transactions standards (X12 5010A1)
- **§164.308(a)(1)(ii)(A)** — Security Risk Analysis (annual SRA)
- **§164.312** — Technical safeguards (encryption, access control, audit controls)
- **§164.312(b)** — Audit log requirement
- **§164.400-414** — Breach notification rules
- **§164.404** — Notification to affected individuals (within 60 days of discovery)
- **§164.408** — Annual breach report to HHS (by March 1 of following year for breaches < 500)
- **§164.502(e)** — Business Associate Agreement requirement
- **§164.508** — Authorization for use/disclosure of PHI
- **§164.520** — Notice of Privacy Practices (NPP)
- **§164.524** — Right to access (member can request a copy of their record; we have 30 days to fulfill)
- **§164.526** — Right to amend (member can request a correction; we have 60 days to decide)
- **§164.526(c)(3)** — Downstream notification (when we accept an amendment, we must notify everyone who was given the unamended record)
- **§164.528** — Accounting of disclosures (member can request a list of who saw their PHI for non-treatment purposes; we keep this list for 6 years)

### Other federal authorities

- **21st Century Cures Act §4004 / ONC HTI-1** — Information-blocking prohibition; we must let members export their EHI on demand
- **Patient Self-Determination Act (PSDA)** — Advance directives requirement at admission
- **ESIGN Act + UETA** — Federal e-signature law (used for consent capture)

---

## 8. Decision log — "why is this done this way?"

Ten patterns that look weird but were intentional. If you find yourself thinking "this is overcomplicated, let me simplify," read the corresponding entry first.

### 8.1 Why are immutable models still using Eloquent (vs. raw query builder)?

Models like `AuditLog` and `EmarRecord` override `save()` to throw on update. They still use Eloquent because we want consistent relationships, eager loading, and casts. The override gives us immutability without losing the ergonomics. See the model files.

### 8.2 Why two note-template services (`NoteTemplateService` + `NoteTemplateRenderer`)?

`NoteTemplateService` is the older config-file-driven service used by structured QA notes. `NoteTemplateRenderer` was added in Phase B7 for DB-backed Markdown templates with `{{placeholder}}` substitution. They have different responsibilities — keep both, don't merge.

### 8.3 Why is the React fork frozen?

There's a parallel `nostosemr` repo (React) that was the original prototype. It is **frozen** and not the canonical app. The Vue fork (this repo, `nostosemr-vue`) is what ships. Don't sync changes from the React fork unless explicitly asked.

### 8.4 Why doesn't `Participant` have a global tenant scope?

Because SuperAdmin needs cross-tenant access and global scopes hide bugs. See pattern 5.9 above. The cross-tenant test suite catches forgotten `forTenant()` calls.

### 8.5 Why are some FormRequests using `authorize() { return true; }`?

Several Grievance/Consent FormRequests skip authorization at the FormRequest layer because the controller does dept gates. This is a legacy pattern; new FormRequests should authorize() explicitly. The architecture audit (Audit-13) flagged this; cleanup is deferred per H13 memo because it's cosmetic.

### 8.6 Why does `routes/web.php` have 1655 lines instead of being split?

Laravel's route caching is most effective when routes are in a single file. Splitting into multiple files would buy organization at the cost of route-cache build time. The trade-off chosen: one file with strong section comments at the top (see the file map in Z4).

### 8.7 Why are migrations one-per-file (203 files) instead of consolidated?

Each migration represents a real point-in-time schema change. Consolidating them into "phase migrations" would lose the per-migration commit and review trail. Production deployments run them in order; CI runs them on every test. The cost is tolerable.

### 8.8 Why does the cron schedule live in `routes/console.php` instead of `app/Console/Kernel.php`?

Laravel 11+ moved scheduling to `routes/console.php` per the new "streamlined kernel" pattern. We follow that convention.

### 8.9 Why is `DatabaseSeeder` itself effectively dev-only?

Because we never run it on production. Production tenants are provisioned via `TenantOnboardingService` after a real CMS contract is signed. `DatabaseSeeder` → `DemoEnvironmentSeeder` is exclusively the demo path.

### 8.10 Why so many `// Phase X` markers in commits and comments?

Each phase represents a discrete unit of work that shipped together. Phase markers let you trace `why does this column exist?` back to the originating audit/feature work via the memory file at `~/.claude/projects/.../feedback_phase_X_*.md`. They're institutional memory.

---

## 9. Suggested first-week reading order

If you're new and overwhelmed, read in this order. Each step builds on the last.

### Day 1 — orient
1. This document (you're already here)
2. `README.md` — boot/test/seed/login
3. `routes/web.php` — top-of-file map only (don't try to read all 1655 lines)
4. `resources/js/app.ts` + `resources/js/Layouts/AppShell.vue` — how the frontend boots
5. **Boot the app, log in as a primary_care admin, click around for 30 minutes.**

### Day 2 — clinical core
6. `app/Models/Participant.php` — the central entity
7. `app/Models/CarePlan.php` — the IDT-managed care plan
8. `app/Http/Controllers/ParticipantController.php` — list + show + tabs
9. `resources/js/Pages/Participants/Show.vue` — how tabs are wired
10. Read 5 participant tabs of your choice in `resources/js/Pages/Participants/Tabs/`

### Day 3 — workflows
11. `app/Http/Controllers/GrievanceController.php` + the Grievance model
12. `app/Http/Controllers/AppealController.php` + the Appeal state machine
13. `app/Http/Controllers/ClinicalOrderController.php` + the order lifecycle
14. `app/Services/IdtMeetingService.php` (if it exists) + the IDT meeting model

### Day 4 — billing + integrations
15. `app/Services/Edi837PBuilderService.php` — the most jargon-dense file
16. `app/Services/HccRiskScoringService.php` + `RiskAdjustmentService.php`
17. `app/Jobs/ProcessHl7AdtJob.php` — inbound HL7
18. `app/Services/TransportBridgeService.php` — the transport vendor scaffold (this is where your team will live)

### Day 5 — meta
19. `routes/console.php` — what runs on the schedule and why
20. `app/Http/Controllers/ComplianceController.php` — audit-pull universes
21. The W2 + V5 Toaster pattern (`resources/js/Components/Toaster.vue` + `app.ts` interceptor)
22. Pick one wave-letter close-out memo from the memory folder (e.g. `feedback_phase_h13_audit13_close.md`) — get a sense of how decisions land

After this week, you should be able to add a new participant tab end-to-end with confidence.

---

## 10. Where to ask deeper questions

The codebase has a layered documentation system:

| Layer | Where | What it has |
|---|---|---|
| Per-file headers | At the top of each PHP / Vue file | "What is this file? Why does it exist?" |
| Architecture overview | `docs/ARCHITECTURE.md` (this file) | Cross-cutting patterns, glossary, decision log |
| Operational runbooks | `docs/runbooks/` | Tenant onboarding, admin operations |
| Compliance docs | `docs/compliance/` | BAA template, HIPAA workforce training |
| Security docs | `docs/security/` | Breach runbook, DR plan, pen-test plan |
| Training one-pagers | `docs/training/` | Role-specific quick-reference (PCP, RN, etc.) |
| Permission matrix | `docs/permission-matrix.md` | Department × role × module access |
| Go-live readiness | `docs/GO_LIVE.md` | What's still pending; paywall items |
| Phase decision log | `~/.claude/projects/.../memory/feedback_phase_*.md` | Per-phase audit findings + WONTFIX rationale |

When in doubt:
1. Search the codebase for a symbol or `§` citation — every appearance has context.
2. Check `git log` for the originating commit; phase letters in commit messages map to memory files.
3. If still stuck, check `MEMORY.md` index for the relevant phase.

Welcome aboard.

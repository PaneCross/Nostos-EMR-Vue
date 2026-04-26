# NostosEMR — Go-Live Readiness

**Audience:** TJ (project owner) + dev-team lead.
**Purpose:** the canonical "what's left between today and a paying customer running this in production" reference.
**Last updated:** 2026-04-25 (end of Wave Z handoff).

> **TL;DR for TJ:** Nothing is blocking a *demo* today. Everything is blocking *production* on a real PACE org until you sign at least 4 vendor contracts (hosting BAA, mail BAA, clearinghouse, ePrescribing) and complete an annual pen test + DR provisioning. Year-1 budget for a mid-size pilot lands around **$70–120k all-in**, most of it concentrated in 3 line items (ePrescribing, clearinghouse, SOC 2).

---

## Contents

1. [What's done](#1-whats-done)
2. [What's pending in code (no vendor required)](#2-whats-pending-in-code-no-vendor-required)
3. [Paywall items — customer-first vs do-it-now matrix](#3-paywall-items--customer-first-vs-do-it-now-matrix)
4. [Top 3 paywall priorities (TJ recommendation)](#4-top-3-paywall-priorities-tj-recommendation)
5. [Operational readiness checklist](#5-operational-readiness-checklist)
6. [Demo seed verification](#6-demo-seed-verification)
7. [Transport handoff appendix](#7-transport-handoff-appendix)

---

## 1. What's done

13 audits closed end-to-end. Build state at handoff:

| Metric | Value |
|---|---|
| Test suite | **2435+ passing**, 8345+ assertions, ~6 min single-pass |
| Build | Clean (`app.js` 370.72 kB / 122.17 kB gzipped) |
| Backend files | 465 PHP files; ~99 already CLEAN + 67 newly-improved in Z2 |
| Frontend files | 175+ Vue pages, 9 Components, 1 Layout — all with headers (Z3) |
| Architecture doc | `docs/ARCHITECTURE.md` (~25 KB, 10 sections, full glossary) |
| Compliance scope | All §460 deadlines + all §164 HIPAA member-rights surfaces shipped |

The full audit + wave history is in the memory folder (`feedback_phase_h*_*.md`). Key milestones:
- **Waves A-H** (2026-04-23 → 24): MVP completion across 36 backend phases
- **Waves I-N** (Apr 24): launch readiness, 24 phases
- **Waves O-Q** (Apr 24-25): MVP cleanup, compliance, audit-5 cleanup
- **Waves R-S** (Apr 25): external research + audit-7 surface fixes
- **Waves T-X** (Apr 25): cross-tenant guards, UI wiring, UI quality, UX polish, concurrency + security
- **Wave Y** (Apr 25): polish + perf baseline (caught real /participants N+1)
- **Wave Z** (Apr 25): handoff prep — comments, jargon, architecture doc, this doc

---

## 2. What's pending in code (no vendor required)

These are real engineering items that don't need a contract — they're just "things the dev team can pick up." Each comes with severity + effort + when it should land.

### Deferred from H13 (Audit-13 close-out)

| Item | Severity | Effort | When it should land |
|---|---|---|---|
| **a11y modal sweep** — modals lack `aria-modal` + focus-trap; ~25 Vue files | LOW | ~3 days for one engineer | Before first enterprise sale (some buyers ask) |
| **Idempotency keys on POST creates** — Consent, Insurance, ADL, etc. | MEDIUM | ~2 weeks (per-controller; needs migration for keys + unique index) | When a customer reports a duplicate from a network-timeout retry. Currently latent. |
| **Transport circuit-breaker** — TransportBridgeService no fallback when vendor 5xx's | MEDIUM | ~1 week | Pair with first transport-vendor activation |
| **Remaining ~28 FreezesTime adoptions** — deadline tests not yet using the trait | LOW | ~1-2 days | Only when a flake actually surfaces |
| **9 of 10 Finance Vue pages already had headers, 5 didn't** | LOW | DONE in Z4 |  |
| **Method-level @param/@return docblocks across PHP** | LOW | ~1 week | Iterative, as files are touched |
| **Dashboard text-size audit (Phase 14 backlog)** | LOW | ~1 day | Before first real clinical user complains |
| **Left-nav parity vs React fork (Phase 14 backlog)** | LOW | ~2 days | Before SuperAdmin sees both UIs side-by-side |
| **Participant terminology sweep** — "patient" vs "participant" vs "member" | LOW | ~1 day | Pair with first surveyor walkthrough |
| **`nursing` department gap** — no `nursing` enum; nurses split between `primary_care` + `home_care` | LOW | ~2 days (migration + seeder + test) | When a customer with a separate Nursing dept asks |

**Recommended sequence:** ignore until customer pressure surfaces, except a11y modals — do those in week 2 of new-team onboarding as a useful scope-warmup task that also unblocks enterprise sales.

### Cypress / Playwright E2E suite

**Status:** not started.
**Why valuable:** the test suite is excellent at backend correctness and structural Vue wiring, but doesn't catch real-browser issues (focus management, scroll, network timing, JS errors that don't kill the page). 13 audits revealed 0 of these — but only because we never looked.
**Effort:** ~1 week to scaffold (Cypress recommended; Playwright works too) + ongoing test maintenance.
**Recommendation:** **first sprint of new-team work.** Pick 10 critical workflows (login, participant detail, care plan approval, grievance file, IDT meeting, EMAR pass, transport request, etc.). Land them as a CI gate before adding new features.

### Performance baseline expansion

**Status:** Y7 added 4 endpoint perf tests at 200-enrolled scale. The Y7 trap caught the `/participants` N+1 in its first run.
**Recommended next:** add 5-10 more endpoints to the perf trap — dashboards, IBNR estimator, RAF snapshot, BCMA scan-verify, EhiExport. Cheap follow-up; high regression-trap value.
**Effort:** ~2 days.

---

## 3. Paywall items — customer-first vs do-it-now matrix

The full per-item breakdown lives in `~/.claude/projects/.../paywall_and_vendor_gates_report.md`. Here it is sorted by activation timing.

### Tier 1 — must do before first paying client (regulatory / liability)

| Item | Vendor options | Annual cost (per PACE site) | Status |
|---|---|---|---|
| **HIPAA-BAA hosting** | AWS HIPAA-eligible, GCP, Azure | ~$15k | Required day 1 |
| **HIPAA-BAA email provider** | Postmark (recommended), AWS SES, Resend | ~$0.6-3.6k | Required day 1 (OTP delivery) |
| **DR / cross-region backup** | Same cloud as hosting, terraformed | ~$5-15k | Required pre-prod (HIPAA contingency rule) |
| **Annual penetration test** | Cobalt, Schellman, Bishop Fox | ~$20-30k year 1 | Required pre-prod |
| **HIPAA Security Risk Analysis (SRA)** | Internal + readiness tool | $0 (we have the framework) | Annual; first one due before go-live |
| **AWS Secrets Manager (or equivalent)** | AWS / GCP Secret Manager | ~$1k | Required day 1 |
| **CoverMyMeds for prior auth** OR per-payer build | CoverMyMeds | ~$30-100/prescriber/mo | Required if PA volume is real |

**Tier 1 subtotal:** ~$45-65k year 1 (excluding pen test which is one-time-up-front then annual).

### Tier 2 — needs first paying client to make sense (revenue + workflow)

| Item | Vendor recommendation | Annual cost (per site) | Trigger |
|---|---|---|---|
| **ePrescribing + EPCS + PDMP** | DrFirst Rcopia bundle | ~$23-34k | First prescriber goes live |
| **Clearinghouse (837P/835/277CA)** | Availity (or match incumbent) | ~$15-30k | First billing month |
| **Real-time eligibility (270/271)** | Bundle with clearinghouse | ~$5-15k | Bundled with above |
| **CMS EDS submission** | Bundle with clearinghouse | $0-5k incremental | Bundled |
| **State Medicaid encounter adapter** | Per-state internal build | ~$3-10k/state setup | Per state |
| **State POLST / advance directive forms** | Per-state internal build | $0 (forms are free) | Per state |
| **SOC 2 Type I** | Schellman / Prescient + Drata | ~$45-70k year 1 | First enterprise sales conversation |

**Tier 2 subtotal:** ~$90-135k year 1 if all activated. Realistically only ePrescribing + clearinghouse + eligibility are urgent — those land at ~$45-80k.

### Tier 3 — needs customer at scale or specific request

| Item | Vendor | Cost | Trigger |
|---|---|---|---|
| **SAML SSO** | laravel-saml2 (free) + customer's IdP | $0 our side | Enterprise customer asks |
| **HRIS sync** | BambooHR / Rippling / Gusto (theirs) | $0 our side | Customer >50 staff |
| **Sequoia / Carequality HIE** | Carequality + commercial SDK | ~$15-40k | Customer wants automated cross-EMR records |
| **Cloud OCR (vs Tesseract)** | AWS Textract or Google DocumentAI | ~$50-300/mo | Customer complains about Tesseract quality |
| **AI Scribe (visit-note dictation)** | Suki AI / Abridge / DAX Copilot | ~$30-300/clinician/mo | "We'll close if you show me AI Scribe" |
| **Real-time SMS (vs in-app + email)** | Twilio Verify | ~$50-200/mo | Customer demographic prefers SMS |
| **Telehealth video native integration** | Zoom Healthcare / Doximity / self-host Jitsi | $0-500/mo | Telehealth volume is real |
| **Full SNOMED CT + RxNorm distributions** | UMLS license (free) + load script | $0 + 1 week dev | Clinician complains code is missing |
| **MoCA cognitive assessment licensing** | MoCA Inc. | ~$125 lifetime/user | Customer prefers MoCA over Mini-Cog |

**Tier 3 subtotal:** highly variable. Most items defer indefinitely without a specific ask.

---

## 4. Top 3 paywall priorities (TJ recommendation)

After 13 audits and full vendor research, these are the 3 to activate first when the first pilot LOI is signed:

### Priority 1 — Hosting + Email + DR (week 1)

**Why first:** without HIPAA-BAA hosting + email provider, you cannot legally process PHI. This is the gate to everything else.

**Recommendation:** **AWS HIPAA-eligible** for hosting, **Postmark** for email. AWS gives you Secrets Manager + Textract (later) + cross-region backup all under one BAA. Postmark is the cheapest BAA-available email provider with rock-solid deliverability.

**Per-PACE-site annual cost estimate:** **$16-19k/year** (AWS hosting ~$15k + Postmark ~$0.6-1k + Secrets Manager ~$1k + S3/RDS extras ~$0.5-2k).

**Effort:** ~1-2 weeks of infra work. Terraform the whole thing so DR is `terraform apply` to a second region.

### Priority 2 — Clearinghouse bundle (Availity recommended) (weeks 2-6)

**Why second:** as soon as a real clinic visit happens, we owe CMS encounter data + we owe payers claims. Without a clearinghouse, Finance manually uploads 837P files to whatever portal each payer uses, and 835 ERAs come back manually. Painful by month 2.

**Recommendation:** **Availity** — bundles claims + eligibility + CMS EDS routing in one contract, mid-market PACE-friendly, REST + SFTP, OAuth2 client_credentials. Match the incumbent EMR's clearinghouse if pilot has one to minimize switching friction.

**Per-PACE-site annual cost estimate:** **$20-40k/year** (varies by transaction volume; ~$1-3k/mo flat + $0.15/transaction).

**Effort:** 2-3 weeks of adapter wiring once contract + credentials land. The `NullClearinghouseGateway` + `AvailityClearinghouseGateway` stub already exist (Phase 12).

### Priority 3 — DrFirst Rcopia bundle (ePrescribing + EPCS + PDMP) (weeks 3-8)

**Why third:** most US states require electronic prescribing for controlled substances. Running paper Rxs on a paying client in NY or CA is regulatory risk. This is the highest-cost vendor item and the slowest to onboard (EPCS identity proofing for prescribers takes ~2 weeks).

**Recommendation:** **DrFirst Rcopia hybrid** — widget-embedded V1 (ship in week 4-5), full REST API V2 later. Includes EPCS + PDMP queries (NarxCare via DrFirst aggregation).

**Per-PACE-site annual cost estimate:** **$23-34k/year** for 10 prescribers + 500 Rx/month bundle.

**Effort:** 4-6 weeks total (contract → EPCS proofing → integration). Design docs at `~/.claude/.../project_eprescribing_vendor_design.md` + `project_epcs_pdmp_vendor_design.md`.

### Three-priority Year-1 total

**~$65–95k for the first pilot** across these 3 priorities. Add SOC 2 + pen test (~$70-100k year 1) when first enterprise sales conversation surfaces — usually 6-12 months in.

---

## 5. Operational readiness checklist

Things the dev team takes over from the existing infra. Each item is a checkbox the new team should be able to mark done by week 4.

### Infrastructure
- [ ] Provision real Postgres (RDS multi-AZ recommended) + Redis (ElastiCache) + S3
- [ ] HIPAA BAA signed with cloud provider before any real PHI touches the environment
- [ ] Mail provider with BAA wired to Laravel mail driver (replace Mailpit in `.env`)
- [ ] Daily backup + tested restore (use `docs/security/dr-test-plan.md` template)
- [ ] Cross-region DR environment provisioned + tested
- [ ] Terraform/IaC for everything above so disaster recovery is a script, not a runbook

### Application runtime
- [ ] Laravel Horizon worker provisioned (queue:work alone is not durable)
- [ ] Cron line installed: `* * * * * php artisan schedule:run` — without it NONE of the deadline jobs run (see `routes/console.php` header)
- [ ] Reverb WebSocket server running + reachable from the Vue frontend (port 8182 in dev)
- [ ] Sentry / error reporting (or equivalent) wired to Laravel + Vue
- [ ] Uptime monitoring (Pingdom, BetterUptime, etc.) on the auth endpoint + a dashboard page
- [ ] Log aggregation (CloudWatch / Loki / Papertrail) for `storage/logs/laravel.log`

### Security + compliance
- [ ] SRA (Security Risk Analysis) completed and signed annually — see `docs/security/`
- [ ] Pen test scheduled before any real PHI lands in prod
- [ ] BAA signed with every vendor that touches PHI (hosting, email, clearinghouse, ePrescribing, OCR if used)
- [ ] Workforce HIPAA training records on file (`docs/compliance/hipaa-workforce-training.md`)
- [ ] Tenant-onboarding runbook reviewed (`docs/runbooks/tenant-onboarding.md`)

### Demo / pilot prep
- [ ] Demo seed verified producing populated tenant on a fresh DB (see Section 6)
- [ ] Default OTP delivery confirmed working with the chosen mail provider
- [ ] Pilot tenant's `cms_contract_id` (H-Number), state, timezone all set correctly via tenant onboarding
- [ ] Pilot's first 50 staff users provisioned with department + role + active flag
- [ ] First 10 enrolled participants test-imported via CCDA or manual entry to validate intake flow

---

## 6. Demo seed verification

Before handing the dev team a fresh checkout, TJ should personally verify the demo seed produces a working tenant. Five minutes:

```bash
cd /home/tj/projects/nostosemr-vue
git pull
./vendor/bin/sail down
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate:fresh --seed
./vendor/bin/sail npm run build
```

Open http://localhost:8081, log in as `pcp@example.com` (OTP code in Mailpit at `localhost:8126`). Check:

- [ ] Dashboard renders with KPI cards populated
- [ ] Participants → Alice Testpatient (WEST-00001) → all 27 tabs render without errors
- [ ] Cmd+K global search returns participants/referrals/grievances
- [ ] Compliance → CMS Audit Universes generates a sample export
- [ ] Switch to `idt@example.com` → IDT meeting page renders
- [ ] Switch to `it@example.com` → IT Admin → Audit Log loads
- [ ] No red errors in the browser console

If any of these fail, the seeder has rotted since the last verified run and needs a fix before handoff.

---

## 7. Transport handoff appendix

This is for the dev team that's going to wire the transportation system. Read carefully — this surface is paywall-deferred today and you'll be wiring real vendor adapters.

### Files you will live in

| File | What it does |
|---|---|
| `app/Services/TransportBridgeService.php` | The adapter abstraction — controllers and jobs call this; it routes to a vendor-specific implementation |
| `app/Jobs/ProcessTransportStatusWebhookJob.php` | Inbound webhook handler. Already has `$timeout=60` + `backoff()` per Y4 convention |
| `app/Models/TransportRequest.php` | The persistence model; per-leg status state machine |
| `app/Http/Controllers/TransportController.php` | Internal staff endpoints (book, dispatch, view manifest) |
| `app/Http/Controllers/TransportRequestController.php` | The CRUD surface for individual transport requests |
| `routes/web.php` | Search for "Transport" — auth'd staff routes + the `/webhooks/transport-status` public webhook (HMAC-authenticated) |
| `resources/js/Pages/Transport/Dashboard.vue` | Dispatcher dashboard |
| `resources/js/Pages/Transport/Manifest.vue` | Per-day driver manifest |
| `resources/js/Pages/Mobile/` | Driver mobile companion (separate audience — different security model) |

### Current state

- **Internal mode:** the EMR can dispatch transport requests internally. Status updates come from the staff UI or the driver mobile companion.
- **Vendor scaffold:** `TransportBridgeService` has the interface ready for an external adapter. There is currently no real vendor implementation. The "Null adapter" is the default.
- **Webhook surface:** `/webhooks/transport-status` exists, HMAC-signed per partner. Inbound webhook → `ProcessTransportStatusWebhookJob` → updates `TransportRequest.status` → broadcasts a Reverb event so the dispatcher dashboard live-updates.

### What's deferred (Audit-13 M7)

**Circuit-breaker on TransportBridgeService.** Today if a real vendor's external API returns 5xx repeatedly, the job's 3 retries fire in lock-step at the failing endpoint. This is fine for the Null adapter (no upstream) but will be a problem with a real vendor.

**Recommendation for the new team:** as part of vendor activation, implement the circuit-breaker. Pattern:
- After N consecutive 5xx responses, skip the external call for the next M minutes
- Surface the circuit state on the IT Admin Integrations page
- Log to observability (Sentry tag) when the circuit opens

This was deliberately deferred until a real vendor is wired — implementing it against the Null adapter is meaningless.

### Vendor decision tree

When a customer comes with a transport vendor in mind:

1. Does the vendor offer a REST API? → Build a `XxxTransportBridge` implementation of `TransportBridgeService`. Pattern: see `Edi837PBuilderService` for a similar adapter shape.
2. Webhook-only / no outbound? → Just the inbound webhook handler is enough; outbound dispatch stays internal.
3. Phone-and-email-only? → Stay on the Null adapter; train dispatchers on the internal flow.

### Mobile companion notes

- The driver mobile companion (`resources/js/Pages/Mobile/`) has a different auth model — drivers log in to a thinner UI scoped to their day's manifest only.
- The companion is an Inertia page like everything else; it's not a separate native app. PWA install is supported.
- HOME-CARE-ADL data flows through this surface too (Phase 15.5) — distinct audience but same auth backbone.

---

## When in doubt

- **Code questions** → `docs/ARCHITECTURE.md` (the bible)
- **Vendor questions** → this doc + `~/.claude/.../paywall_and_vendor_gates_report.md`
- **Compliance / breach response** → `docs/security/breach-notification-runbook.md`
- **Onboarding a new tenant** → `docs/runbooks/tenant-onboarding.md`
- **Specific phase decisions** → `~/.claude/.../feedback_phase_*.md` (search by phase letter)

The 13-audit history is institutional memory. If something looks weird in code, the originating phase memo will explain why it's that way.

Welcome to operate-mode.

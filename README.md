# NostosEMR (Vue fork)

Electronic Medical Record system for **PACE programs** (Programs of All-Inclusive Care for the Elderly). Built on Laravel 12 + Vue 3.5 + Inertia + Tailwind CSS v4. Multi-tenant, HIPAA-compliant, CMS-ready.

This README is your single starting point. Follow it top to bottom — by the end of week 1 you'll be productive in the codebase.

---

## Your first week here

If you're new to this repo, do these things in order. Don't skip the reading — the codebase is heavy on healthcare-domain knowledge (CMS regulations, HIPAA citations, PACE-specific workflows) and the docs explain everything in plain English.

### Day 1 — Get it running, click around, do not read code

**Morning (~2 hours):**
1. Skim the rest of this README so you know what's where.
2. Follow [**First 30 minutes**](#first-30-minutes) below to clone, install, seed, and boot the app.
3. Log in (see [Default demo credentials](#default-demo-credentials)) and click through the [recommended first paths](#click-around--recommended-first-paths). Don't try to understand the code yet — just get a feel for what the product does.

**Afternoon (~3 hours):**
4. Read [**`docs/ARCHITECTURE.md`**](docs/ARCHITECTURE.md) **cover to cover.** This is the single most important document in the repo. It contains:
   - A plain-English primer on what PACE is and why this software exists
   - The full stack rationale (Laravel + Vue + Inertia, why each)
   - A folder map: where everything lives and where to add new things
   - The 10 cross-cutting patterns (tenant scoping, audit logging, append-only models, etc.) you'll see hundreds of times
   - A glossary of ~80 healthcare acronyms (every IDT, EMAR, BCMA, RAF, HCC, NF-LOC, etc. you'll see in code)
   - A glossary of ~25 federal regulations (CFR §460.x, HIPAA §164.x) with plain-English explanations of what each requires
   - A decision log explaining 10 things that look weird but were intentional
   - A 5-day suggested reading order for the rest of week 1

It's ~580 lines. Budget 60-90 minutes. Take notes.

### Day 2 — Clinical core (the central entity)

5. Read [**`docs/ARCHITECTURE.md` Section 9 — "Suggested first-week reading order"**](docs/ARCHITECTURE.md). Follow the Day 2 list there: `Participant` model, `CarePlan` model, `ParticipantController`, the participant detail tabs.
6. With the app booted, navigate to **Alice Testpatient (WEST-00001)** and click each tab. Every tab is populated with realistic demo data. As you click each tab, open the corresponding Vue file (`resources/js/Pages/Participants/Tabs/<Name>Tab.vue`) and skim the script section. Each file has a header explaining what it does.

### Day 3 — Workflows (state machines you'll touch)

7. Follow [`docs/ARCHITECTURE.md` Section 9 Day 3 list]. Specifically: GrievanceController, AppealController, ClinicalOrderController. Read each file's top header and trace one happy-path through the code.
8. Try filing a grievance via the UI (`/grievances` → New). See what happens server-side: query the `emr_grievances` table; check the `shared_audit_logs` for the `grievance.created` row.

### Day 4 — Billing + integrations (the jargon-dense surface)

9. Read [`docs/ARCHITECTURE.md` Section 9 Day 4 list]: `Edi837PBuilderService`, `HccRiskScoringService`, `ProcessHl7AdtJob`, **`TransportBridgeService`** (this is where you'll be living — see Section 7 of `docs/GO_LIVE.md` for the full handoff appendix on transport).
10. Read [**`docs/GO_LIVE.md`**](docs/GO_LIVE.md) **cover to cover.** This is the second-most-important document. It tells you:
    - Section 2 — what's still pending in code (with severity + effort estimates)
    - Section 3 — every paid vendor that needs activation, with cost ranges
    - Section 4 — the top 3 paywall priorities and recommended vendors
    - Section 5 — the operational readiness checklist (DR, pen test, BAAs)
    - Section 7 — **the transport handoff appendix specifically for you**

### Day 5 — Meta + first-task pickup

11. Read [`docs/ARCHITECTURE.md` Section 9 Day 5 list]: `routes/console.php` (regulatory clocks), `ComplianceController` (audit-pull universes), the V5 axios interceptor + Toaster pattern.
12. Pick one of the [**Familiarization exercises**](#familiarization-exercises) below. They're small, concrete, and prove you've absorbed the patterns.

By end of week 1 you should be able to:
- Add a new participant tab end-to-end (model → controller → Vue page)
- Trace any HIPAA `§` citation in a comment to its plain-English meaning
- Explain in one sentence what every department dashboard does
- Locate the right file to wire a new transport vendor when one is signed

If you can't do those things, re-read `docs/ARCHITECTURE.md` and ping TJ.

---

## First 30 minutes

### Prerequisites
- **Docker Desktop** (for Sail)
- **Git**
- 8 GB RAM free, 10 GB disk
- For Windows users: WSL2 with Ubuntu. **Always work from inside WSL** — there's a known stale-clone trap on the Windows-side filesystem.

### Boot the dev environment

```bash
# 1. Clone (use SSH; the repo is private)
git clone git@github.com:PaneCross/Nostos-EMR-Vue.git nostosemr-vue
cd nostosemr-vue

# 2. Install PHP deps via the host Composer image (no PHP needed locally)
docker run --rm -u "$(id -u):$(id -g)" \
  -v "$(pwd)":/var/www/html \
  -w /var/www/html laravelsail/php83-composer:latest \
  composer install --ignore-platform-reqs

# 3. Copy the env template
cp .env.example .env

# 4. Boot the stack — first run pulls images (~5 min)
./vendor/bin/sail up -d

# 5. Generate the app key
./vendor/bin/sail artisan key:generate

# 6. Run migrations + seed a demo tenant
./vendor/bin/sail artisan migrate:fresh --seed

# 7. Build the frontend
./vendor/bin/sail npm install
./vendor/bin/sail npm run build      # or: ./vendor/bin/sail npm run dev (HMR)
```

### Open the app

- **Web app:** http://localhost:8081
- **Mailpit (caught emails):** http://localhost:8126
- **Reverb (WebSockets):** auto-connects from the Vue side; nothing to open
- **Postgres:** `localhost:5433` (user/pass in `.env`)

### Default demo credentials

After `migrate:fresh --seed`, the demo tenant (Rodriguez LLC PACE) is populated. Log in via OTP — the OTP code arrives at Mailpit (`localhost:8126`) and is also printed to `storage/logs/laravel.log`. Pick any of these demo users:

| Email | Department | Role |
|---|---|---|
| `pcp@example.com` | primary_care | admin |
| `nurse@example.com` | primary_care | standard |
| `idt@example.com` | idt | admin |
| `qa@example.com` | qa_compliance | admin |
| `it@example.com` | it_admin | admin |
| `enrollment@example.com` | enrollment | admin |
| `pharmacy@example.com` | pharmacy | admin |

(Full list in `database/seeders/DemoEnvironmentSeeder.php`.) Hit "Request OTP," check Mailpit, paste the 6-digit code.

### Click around — recommended first paths

1. **Dashboard** — your dept-specific landing page
2. **Participants → Alice Testpatient (WEST-00001)** — every clinical tab is populated
3. **Cmd+K** (or Ctrl+K) — global search across participants, referrals, appointments, grievances, orders, SDRs
4. **Compliance → CMS Audit Universes** — see how surveyor-ready exports work
5. **Switch user (logout + log in as a different demo user)** — see how the dashboard, nav, and permissions change by department

You will see things you don't understand. That's expected. Don't try to explain everything yet — `docs/ARCHITECTURE.md` (Day 1 afternoon reading) is what makes it click.

---

## Familiarization exercises

By the end of week 1, you should be able to do all of these without help. They're not graded — they're how you'll know you've absorbed the patterns.

### Exercise 1 — Trace a single user action through the stack
Log in as `pcp@example.com`. Open Alice Testpatient → Vitals tab. Add a new vital sign reading.

Now find:
- The Vue component that rendered the form (`resources/js/Pages/Participants/Tabs/VitalsTab.vue`)
- The controller method that handled the POST (search `routes/web.php` for `/vitals`)
- The FormRequest that validated the input (`app/Http/Requests/StoreVitalRequest.php`)
- The model the row got saved to (`app/Models/Vital.php`)
- The migration that created the table (`database/migrations/*_create_emr_vitals_*`)
- The audit log row generated (run `./vendor/bin/sail tinker` then `\App\Models\AuditLog::latest()->first()`)

You should be able to point at all 6 within 10 minutes.

### Exercise 2 — Add a column to an existing model
Add a `notes_internal` text column to the `emr_grievances` table. Surface it in `GrievanceController::show` and render it on the Grievance Vue page (read-only).

This forces you through: writing a migration, updating `$fillable`, updating the controller, updating the Vue page. ~1-2 hours including reading. Don't merge it — just open a PR for review.

### Exercise 3 — Read a regulatory clock
Open `routes/console.php`. Pick any scheduled job in the file. Trace from there to:
- The `app/Jobs/<Name>Job.php` file
- The CFR § the job enforces (the file header explains it in plain English)
- One test in `tests/Feature/` that locks in the job's behavior

Write a 3-sentence summary in your own words: "this job runs at X, queries Y, and creates an alert when Z."

### Exercise 4 — Find the transport handoff surface
Without reading `docs/GO_LIVE.md` Section 7 first, find these files in the repo:
- The service class that abstracts transport vendors
- The job that processes inbound transport status webhooks (and check that `$timeout` and `backoff()` are set per Phase Y4 convention)
- The Vue page where dispatchers manage today's manifest
- The mobile companion entry point

Then read `docs/GO_LIVE.md` Section 7 and confirm you found them all. This is your first real week-2 work area.

### Exercise 5 — Run the test suite
```bash
./vendor/bin/sail composer test
```
Watch it pass. Then break something on purpose (rename a controller method) and run again. Watch the failure cascade. Revert. This is just to confirm the suite works on your machine.

If the suite has 6 failures with `shared_tenants_slug_unique` errors, that's the known paratest factory race — re-run once. Single-pass (`./vendor/bin/sail test`) is always clean.

---

## Common tasks

### Run the test suite
```bash
./vendor/bin/sail composer test           # paratest, ~3 min
./vendor/bin/sail test --filter=Foo       # single class
./vendor/bin/sail test tests/Feature/Foo  # single file
./vendor/bin/sail test                    # single-pass (slowest, but always clean)
```

The full suite is **2435+ tests, ~6 min single-pass**. Paratest occasionally flakes on a known factory race (`shared_tenants_slug_unique`) — re-run if you see ~6 failures with that exact message. Single-pass is always clean.

### Frontend dev (HMR)
```bash
./vendor/bin/sail npm run dev
```

### Lint / typecheck
```bash
./vendor/bin/sail npm run typecheck       # vue-tsc
```

### Tail the queue worker
```bash
./vendor/bin/sail artisan horizon
# or in dev: ./vendor/bin/sail artisan queue:work
```

### Re-seed a clean demo tenant
```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

### Run a one-shot artisan command
```bash
./vendor/bin/sail artisan otp:purge-expired
./vendor/bin/sail artisan bcma:backfill-barcodes
./vendor/bin/sail artisan schedule:list      # see all scheduled jobs
```

### Stop the stack
```bash
./vendor/bin/sail down
```

---

## Common problems

**"Permission denied on `.phpunit.result.cache`"** — benign warning. Ignore.

**Vue build error "EACCES on `public/sw.js`"** — pre-existing file-ownership artifact. The build itself succeeded; the post-build hook fails to write the cache-bust file. Either `chmod 666 public/sw.js` once, or ignore.

**Paratest factory races** — re-run `composer test`. Single-pass (`./vendor/bin/sail test`) is always clean.

**WSL2: edits not appearing** — make sure you're working in `/home/tj/projects/nostosemr-vue` (the canonical WSL path), NOT `/mnt/c/Users/...`. The Windows-side path may have a stale stray clone — see `docs/ARCHITECTURE.md` decision log Section 8.10 for the trap details.

**Login OTP never arrives** — check Mailpit at `localhost:8126`. If empty, check `storage/logs/laravel.log` for the OTP code (it's logged for dev convenience). If neither has it, check that the queue worker is running (`./vendor/bin/sail artisan queue:work`) — OTP delivery is a queued job.

**`php artisan schedule:list` is empty in production** — you forgot the cron line on the server. See `routes/console.php` header for the required crontab entry. Without it, NO deadline jobs run.

---

## Where to look next

| You want to… | Read… |
|---|---|
| Understand the codebase + domain | `docs/ARCHITECTURE.md` *(your week-1 bible)* |
| Know what's still pending for production | `docs/GO_LIVE.md` *(includes vendor activation priorities + transport handoff)* |
| Onboard a new tenant | `docs/runbooks/tenant-onboarding.md` |
| Run a HIPAA breach response | `docs/security/breach-notification-runbook.md` |
| Prepare for a CMS survey | `docs/security/cms-audit-dry-run.md` |
| Train a new clinical user | `docs/training/role-one-pagers.md` |
| See the dept × role × module access matrix | `docs/permission-matrix.md` |
| Activate a vendor (DrFirst, Availity, etc.) | `docs/GO_LIVE.md` Sections 3-4, then the relevant gateway scaffold under `app/Services/` |

---

## Conventions you'll see hundreds of times

(Full versions in `docs/ARCHITECTURE.md` Section 5. Listed here so you recognize them on day 1.)

- **Tenant scoping:** every clinical query is `forTenant($user->tenant_id)`. Cross-tenant access is a HIPAA breach.
- **Audit logging:** every PHI access calls `AuditLog::record(...)`. The `AuditLog` model is append-only — you cannot UPDATE rows.
- **Append-only models:** `AuditLog`, `ClinicalNote` (after signing), `EmarRecord`, `PhiDisclosure`, `BreakGlassEvent`, `IntegrationLog`. Each overrides `save()` to throw on update. To "change" one, create a new row that supersedes it.
- **V5 axios interceptor + Toaster:** every Vue `axios.post(...)` automatically gets a top-right toast on 5xx / 403 / 409 / network failure. 422 (validation) is skipped — the FormRequest validation pattern handles it inline.
- **FormRequest pattern:** every write endpoint validates via an `app/Http/Requests/` class. Controllers should NEVER call `$request->validate(...)` inline.
- **Job conventions (Phase Y4):** every queued job declares `$tries`, `$timeout`, and a `backoff()` array.
- **Optimistic locking:** records edited by multiple staff (CarePlan, IdtMeeting, AmendmentRequest) have a `revision` column. Updates send `expected_revision`; mismatch returns 409.

---

## Project status (2026-04-25)

- **2435+ tests passing**, 8345+ assertions, ~6 min single-pass
- **13 audits closed** (every wave from MVP through Audit-13 polish + perf)
- **Build clean:** `app.js` 370.72 kB / 122.17 kB gzipped
- **CMS + HIPAA compliance:** all required surfaces shipped (audit log, breach notification, accounting of disclosures, right to amend, BAA tracking, SRA, all §460 deadlines, audit-pull universes)
- **Vendor activations pending:** clearinghouse, DrFirst (ePrescribing/EPCS), eligibility check, transport vendor, real BAA-grade hosting. **See `docs/GO_LIVE.md` for the full list with cost ranges and recommendations.**

---

## Asking for help

This codebase is large. When you're stuck:

1. **Search the file's top-of-file comment.** Every PHP and Vue file has a header explaining what it does.
2. **Search for the `§` citation or acronym.** `docs/ARCHITECTURE.md` Sections 6 and 7 are exhaustive glossaries.
3. **Check the originating phase commit.** Run `git log -- path/to/file.php` and read the relevant `launch-*` commit message — they're substantial.
4. **Ask TJ.** Default to a short Slack/email rather than a meeting. Most questions answerable in 2-3 sentences.

---

## License

Proprietary. © PaneCross / Nostos Tech. All rights reserved.

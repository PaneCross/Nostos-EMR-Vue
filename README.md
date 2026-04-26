# NostosEMR (Vue fork)

Electronic Medical Record system for **PACE programs** (Programs of All-Inclusive Care for the Elderly). Built on Laravel 12 + Vue 3.5 + Inertia + Tailwind CSS v4. Multi-tenant, HIPAA-compliant, CMS-ready.

> **New here?** Read [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) first. It's the dev-team bible — domain primer, folder map, glossary, decision log. This file is the 30-minute boot guide.

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

# 3. Install JS deps (Sail will run them inside the container)
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

After `migrate:fresh --seed`, the demo tenant (Rodriguez LLC PACE) is populated. Log in via OTP — the OTP is printed to the Laravel log (`storage/logs/laravel.log`) and also caught by Mailpit. Pick any of these demo users:

| Email | Department | Role |
|---|---|---|
| `pcp@example.com` | primary_care | admin |
| `nurse@example.com` | primary_care | standard |
| `idt@example.com` | idt | admin |
| `qa@example.com` | qa_compliance | admin |
| `it@example.com` | it_admin | admin |
| `enrollment@example.com` | enrollment | admin |
| `pharmacy@example.com` | pharmacy | admin |

(Full list in `database/seeders/DemoEnvironmentSeeder.php`.) Hit "Request OTP," check Mailpit at `localhost:8126`, paste the 6-digit code.

### Click around — recommended first paths

1. **Dashboard** — your dept-specific landing page
2. **Participants → Alice Testpatient (WEST-00001)** — every clinical tab is populated
3. **Cmd+K** (or Ctrl+K) — global search
4. **Compliance → CMS Audit Universes** — see how surveyor-ready exports work

---

## Common tasks

### Run the test suite
```bash
./vendor/bin/sail composer test           # paratest, ~3 min
./vendor/bin/sail test --filter=Foo       # single class
./vendor/bin/sail test tests/Feature/Foo  # single file
```

The full suite is **2400+ tests, ~6 min single-pass**. Paratest occasionally flakes on a known factory race (`shared_tenants_slug_unique`) — re-run if you see ~6 failures with that exact message.

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

**WSL2: edits not appearing** — make sure you're working in `/home/tj/projects/nostosemr-vue` (the canonical WSL path), NOT `/mnt/c/Users/...`. The Windows-side path may have a stale stray clone — see `docs/ARCHITECTURE.md` decision log for the trap details.

---

## Where to look next

| You want to… | Read… |
|---|---|
| Understand the codebase | `docs/ARCHITECTURE.md` |
| Know what's still pending for production | `docs/GO_LIVE.md` |
| Onboard a new tenant | `docs/runbooks/tenant-onboarding.md` |
| Run a HIPAA breach response | `docs/security/breach-notification-runbook.md` |
| Train a new clinical user | `docs/training/role-one-pagers.md` |
| See the dept × role × module access matrix | `docs/permission-matrix.md` |

---

## Project status (2026-04-25)

- **2435+ tests passing**, 8345+ assertions
- **13 audits closed** (every wave from MVP through Audit-13 polish + perf)
- **Build clean:** `app.js` 370.72 kB / 122.17 kB gzipped
- **CMS + HIPAA compliance:** all required surfaces shipped (audit log, breach notification, accounting of disclosures, right to amend, BAA tracking, SRA, all §460 deadlines, audit-pull universes)
- **Vendor activations pending:** clearinghouse, DrFirst (ePrescribing/EPCS), eligibility check, transport vendor, real BAA-grade hosting. See `docs/GO_LIVE.md`.

---

## License

Proprietary. © PaneCross / Nostos Tech. All rights reserved.

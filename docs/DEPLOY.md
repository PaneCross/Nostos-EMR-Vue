# Demo deployment notes

The repo has Fly.io deploy artefacts in place (Dockerfile, fly.toml,
.github/workflows/fly-deploy.yml, app/Console/Commands/DemoSeedIfEmpty.php),
but as of the 2026-04-30 click-through session **the live deployment was
torn down** : the Fly.io setup wasn't free enough for heavy testing, and
TJ wants to keep developing locally on Docker until the EMR is ready to
share with PACE contacts.

When the time comes to redeploy, the recommended path is **Option C** below
(Render + Neon + Upstash, all permanent free tiers). The Fly.io path is
still wired up and works ; it's just not cost-free, which matters for an
indefinite demo with hard testing.

## Status as of 2026-04-30

- Fly apps `nostos-emr-vue` and `nostos-emr-vue-db` : **destroyed**.
- Cost going forward : **$0** until next deploy.
- Repo files left in place (still useful as reference / future redeploy) :
  - `Dockerfile`
  - `.dockerignore`
  - `fly.toml` (restored to free-tier-friendly defaults)
  - `.github/workflows/fly-deploy.yml`
  - `app/Console/Commands/DemoSeedIfEmpty.php`

---

## Option A : just keep developing locally (current state)

No hosting. TJ runs `./start.sh` on his WSL2 + Docker setup ; Claude Code
edits files locally ; tests run on the Sail container. **This is where
we are right now.**

---

## Option B : redeploy to Fly.io (for sale demos, ~$0-3/mo idle)

Has caveats — see "Lessons learned" below. If you go this route :

```bash
# (one-time) install + auth flyctl
curl -L https://fly.io/install.sh | sh
fly auth login

# Bump fly.toml's web VM to 2 GB temporarily (the seeder OOMs at 1 GB).
# Then :
fly launch --no-deploy --copy-config --name nostos-emr-vue --region lax

# Provision Postgres
fly postgres create --name nostos-emr-vue-db --region lax \
    --vm-size shared-cpu-1x --initial-cluster-size 1 --volume-size 1
fly postgres attach nostos-emr-vue-db --app nostos-emr-vue \
    --database-name nostos_emr

# IMPORTANT : bump Postgres memory or the seeder OOMs the DB
fly machine update <pg-machine-id> -a nostos-emr-vue-db --vm-memory 1024 --yes
fly machine update <pg-machine-id> -a nostos-emr-vue-db --autostart --yes

# Set DB connection fields (fly attach writes DATABASE_URL but the project
# reads DB_HOST/DB_PORT/etc., which Fly doesn't auto-set)
fly secrets set --app nostos-emr-vue --stage \
    DB_HOST=nostos-emr-vue-db.flycast \
    DB_PORT=5432 \
    DB_DATABASE=nostos_emr \
    DB_USERNAME=<from-attach-output> \
    DB_PASSWORD=<from-attach-output>

# Set the rest of production secrets
fly secrets set --app nostos-emr-vue --stage \
    APP_KEY=base64:<openssl rand -base64 32> \
    APP_ENV=production APP_DEBUG=false \
    APP_URL=https://nostos-emr-vue.fly.dev \
    SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database \
    DB_CONNECTION=pgsql LOG_CHANNEL=stderr LOG_LEVEL=info \
    BROADCAST_CONNECTION=null FILESYSTEM_DISK=local MAIL_MAILER=log

# Deploy. The release_command runs migrations + DemoSeedIfEmpty inside
# a Fly deploy machine (no SSH timeout) so the seed completes cleanly.
fly deploy

# After the seed completes, drop web VM back to 1 GB for cheaper idle :
# edit fly.toml memory = "1gb" and `fly deploy` again.

# Generate auto-deploy token for GitHub Actions :
fly tokens create deploy --app nostos-emr-vue --expiry 8760h \
    --name 'github-actions-deploy'
# Paste output into github.com/PaneCross/Nostos-EMR-Vue/settings/secrets/actions
# under the name FLY_API_TOKEN.
```

Cost at idle with `auto_stop_machines = "stop"` and `min_machines_running = 0`:
$0 for the web VM (parks itself), ~$2/mo for Postgres (always-on).

---

## Option C : redeploy to Render + Neon + Upstash (permanent $0, recommended for next launch)

Three vendors, all permanent free tiers, no card required for any.

| Piece | Vendor | Free tier | Catch |
|---|---|---|---|
| Web app | Render.com | Always free, 512 MB | Sleeps after 15 min idle, ~30s cold start on next request |
| Postgres | Neon.tech | Always free, 0.5 GB | Sufficient for demo seed (~50 MB) ; auto-pauses when idle |
| Redis (optional) | Upstash | 10k commands/day free | Skip for demo ; database driver works fine |

### Setup outline (estimate ~1 hour)

1. **Sign up at neon.tech**, create a new project. Copy the connection
   string (looks like `postgresql://user:pass@ep-xxx.us-east.aws.neon.tech/neondb`).

2. **Sign up at render.com**, create a new "Web Service" pointed at the
   GitHub repo. Render auto-detects the Dockerfile.

3. Set the same env vars as Option B but :
   - Use Neon's connection string for `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
     `DB_USERNAME`, `DB_PASSWORD`
   - Set `APP_URL=https://your-render-url.onrender.com` (or custom domain)
   - All other vars same as Option B

4. Render runs the `Dockerfile` and exposes the app. Add a "pre-deploy
   command" of `php artisan migrate --force && php artisan demo:seed-if-empty`
   so the demo seed runs once on first deploy.

5. Auto-deploy is automatic on Render — every push to `main` triggers a
   redeploy, no GitHub Actions workflow needed (Render does it itself).

### Trade-offs vs Fly.io

- **Render free tier sleeps after 15 min of inactivity.** First request
  after sleep takes ~30 seconds to wake the dyno. Set expectation with
  PACE contacts : "first click takes 30 seconds, after that it's snappy."
- **Neon free Postgres also auto-pauses** after 5 min idle. Wake takes
  another few seconds. So a truly cold demo : ~30s for the web app +
  a few seconds for Postgres on the first DB query. Total <60s.
- After the first request everything is fast for as long as traffic
  keeps flowing.

---

## Lessons learned from the 2026-04-30 attempt

Six production-only bugs surfaced during the Fly deploy. All fixed and
committed ; future deploys won't hit these again, but worth knowing :

1. **`USER www-data` in Dockerfile broke s6-overlay** (the serversideup
   base image's init system requires PID 1 = root). Fixed by removing
   the directive.

2. **serversideup's s6-overlay incompatible with Fly.io's machine runtime**
   even after the USER fix : "s6-overlay-suexec : fatal : can only run as
   pid 1". Switched base to `webdevops/php-nginx:8.4-alpine`.

3. **`richarvey/nginx-php-fpm:3.1.6` (intermediate attempt) ships PHP 8.2,**
   but our composer.lock requires PHP 8.4. Switched to webdevops which
   has a clean 8.4 tag.

4. **`fakerphp/faker` was in `require-dev`** so `composer install --no-dev`
   stripped it ; production seeders need it for factories. Moved to
   `require`.

5. **Laravel's `config/database.php` reads `DB_URL`,** not Fly's
   auto-attached `DATABASE_URL`. Set the individual `DB_HOST` / `DB_PORT`
   / etc. fields explicitly.

6. **`storage/framework/*` was excluded from build context** (dev artefacts
   owned by laravel.test container, unreadable by host docker). Added
   `RUN mkdir -p` for the runtime dirs in Dockerfile.

7. **HTTPS scheme** : behind Fly's TLS proxy Laravel saw plain HTTP and
   emitted `http://` asset URLs. Fixed via `URL::forceScheme('https')`
   in `AppServiceProvider::boot()` when `app()->environment('production')`.

8. **SSH session timeout (~3 min) was killing the demo seed.** Worked
   around by moving the seed into a `php artisan demo:seed-if-empty`
   custom command and calling it from `fly.toml`'s `[deploy]
   release_command` — the deploy machine has a 30 min window vs SSH's 3.

9. **Postgres OOM at 256 MB** during the seeder — bumped to 1 GB.
   `fly.toml` web VM bumped to 2 GB during seed, dropped back to 1 GB
   after for cost.

All of these are now baked into the repo. A clean redeploy to either Fly
or Render shouldn't hit any of them.

---

## When NOT to use Fly.io / Render / Neon

These are **demo / pilot** infrastructure. None are HIPAA-eligible by
default. The moment a real PACE contact enters real patient data, you
need :

- A signed BAA with the hosting provider.
- Encryption at rest + in transit (most modern hosts have this, but
  it's part of what the BAA formalises).
- Audit logging (already built into NostosEMR via `shared_audit_logs`).

For a real client deployment, migrate to one of : AWS (Fargate / ECS,
sign BAA), Azure App Service (sign BAA), Google Cloud Run (sign BAA),
or a HIPAA-by-default vendor like Aptible. That's a separate project,
~$200-500/mo for a small hosted EMR.

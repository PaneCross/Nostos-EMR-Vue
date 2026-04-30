# Demo deployment to Fly.io

Living doc for the `nostosemr-demo` Fly.io deployment. **This is a demo
environment with seeded fake data.** Real PHI must NEVER be entered here —
the host is not BAA-eligible. When we sign a real client we'll migrate to
HIPAA-eligible infra (AWS / Azure / Aptible) ; that's a separate project
documented elsewhere.

## What's where

| Piece | Location | Free? |
|---|---|---|
| Web app (Laravel + Vue) | Fly.io shared VM, `lax` region, 1 GB RAM | ~$2-3/mo if it stays warm, $0 if always idle (auto-stop) |
| Postgres | Fly Postgres, smallest tier | ~$2/mo, sometimes covered by free credit |
| Redis (sessions / cache) | Upstash via Fly extension | Generous free tier |
| Domain | Whatever TJ owns (or `*.fly.dev` for free) | $0 with `.fly.dev`, ~$12/yr custom |

Total : **$0 to ~$5/mo for a low-traffic demo.**

## What TJ has to do (one time, ~10 minutes)

1. **Sign up at https://fly.io.** Email + password, or GitHub OAuth.
   Add a credit card (required even on free tier ; Fly gives free
   allowances but won't provision without a card on file).

2. **Install the CLI on your machine.** From WSL2 :
   ```bash
   curl -L https://fly.io/install.sh | sh
   ```
   That puts `flyctl` (alias `fly`) at `~/.fly/bin/flyctl`.

3. **Log in once.** Opens a browser :
   ```bash
   fly auth login
   ```
   After login the CLI stores a token at `~/.fly/config.yml`. Once that
   exists, Claude Code can run every subsequent `fly` command on its own.

4. **Tell Claude Code "go deploy"** in chat. Claude does the rest of the
   provisioning (`fly launch`, `fly postgres create`, secrets, deploy,
   seed). When Claude needs the deploy token for GitHub Actions, it'll
   tell you the one command to run + paste into the GitHub UI.

That's it. Future code changes : `git push origin main` auto-deploys via
`.github/workflows/fly-deploy.yml`.

## What Claude does (after the bootstrap)

Each step below is a single `fly` command Claude runs via the Bash tool.
They're listed here so the reasoning is auditable.

### Initial provisioning (one-time)

```bash
# 1) Create the app (no deploy yet — we want to set secrets first)
fly launch --no-deploy --copy-config --name nostosemr-demo --region lax

# 2) Provision Postgres in the same region
fly postgres create --name nostosemr-demo-db --region lax \
    --vm-size shared-cpu-1x --volume-size 1 --initial-cluster-size 1

# 3) Attach Postgres to the app — this writes DATABASE_URL into Fly secrets
fly postgres attach nostosemr-demo-db --app nostosemr-demo

# 4) Provision Redis (Upstash)
fly redis create --name nostosemr-demo-redis --region lax

# 5) Set the rest of the production secrets
fly secrets set --app nostosemr-demo \
    APP_KEY=base64:$(php artisan --no-ansi key:generate --show) \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=https://nostosemr-demo.fly.dev \
    SESSION_DRIVER=redis \
    CACHE_STORE=redis \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=pgsql \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info

# 6) First deploy
fly deploy

# 7) Seed the demo data (runs inside the deployed VM)
fly ssh console --app nostosemr-demo \
    -C "php artisan db:seed --class=DemoEnvironmentSeeder --force"
```

### Day-to-day (Claude or TJ)

```bash
# Manual deploy (auto-deploy via GH Actions also works)
fly deploy

# Tail logs
fly logs

# Run one-off artisan commands
fly ssh console -C "php artisan tinker"

# Reset demo data (wipes + re-seeds)
fly ssh console -C "php artisan migrate:fresh --seed --force"

# Check app status / cost
fly status
fly billing show
```

## Cost / scaling notes

- `auto_stop_machines = "stop"` + `min_machines_running = 0` in `fly.toml`
  means the web VM parks itself after a few minutes of no traffic and
  costs $0 while idle. First request after idle takes 1-3 seconds to
  cold-start. Fine for demos, would be unacceptable for a real app.
- Postgres has no auto-stop ; it bills continuously. The smallest tier
  (`shared-cpu-1x` / 1 GB volume) is ~$1.94/mo at the time of writing.
- If a demo session needs to feel snappy (no cold start), bump
  `min_machines_running = 1` in `fly.toml` for that day. Adds ~$3/mo
  while it's on.

## Future-proofing

When we sign a real PACE client :

1. Migrate to a HIPAA-eligible host (likely AWS Fargate or Aptible).
2. Sign the BAA.
3. Move the `production` deploy there ; keep the Fly.io app as `staging`
   or sales-demo.

The Dockerfile here is portable to any container host (ECS, Cloud Run,
Aptible, etc.) — no Fly-specific code in it. `fly.toml` is the only
Fly-coupled file.

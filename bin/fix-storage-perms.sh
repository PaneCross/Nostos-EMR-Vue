#!/usr/bin/env bash
# ─── bin/fix-storage-perms.sh ────────────────────────────────────────────────
# Phase A1 tech-debt sweep. Heals the Sail "root-owned files under storage/"
# issue that recurs after parallel test runs.
#
# Run from the project root:
#   ./bin/fix-storage-perms.sh
#
# Safe to re-run; idempotent. Requires docker compose to be up.
# ─────────────────────────────────────────────────────────────────────────────
set -euo pipefail

if ! command -v docker >/dev/null 2>&1; then
    echo "docker not found — run this on the host with Sail's containers running." >&2
    exit 1
fi

echo "[fix-storage-perms] chowning storage/ and bootstrap/cache/ to sail:sail..."
./vendor/bin/sail exec -T -u root laravel.test bash -c \
    'chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache'

echo "[fix-storage-perms] ensuring required subdirectories exist..."
./vendor/bin/sail exec -T laravel.test bash -c '
  for d in \
    storage/app/private \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs; do
    mkdir -p "/var/www/html/$d"
    chmod 0775 "/var/www/html/$d" 2>/dev/null || true
  done
'

echo "[fix-storage-perms] done."

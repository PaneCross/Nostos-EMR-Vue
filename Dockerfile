# ─── NostosEMR production image ──────────────────────────────────────────────
# Multi-stage build for the Fly.io demo deployment :
#   1. node-builder : compile the Vue / Tailwind front-end via vite
#   2. composer-builder : install PHP dependencies (no dev, optimised autoload)
#   3. final : serversideup/php-nginx base + the artefacts from the two builders
#
# The final image runs nginx + php-fpm 8.4 in one container (the official
# serversideup base bundles both with sane defaults for Laravel). On Fly's
# free / hobby tier this fits inside a 256-512 MB shared VM.
#
# Local dev still runs via docker-compose (Sail). This file is for production
# only — if you need to mess with it locally :
#     docker build -t nostosemr-vue .
#     docker run -p 8080:8080 -e APP_KEY=base64:... nostosemr-vue
# ─────────────────────────────────────────────────────────────────────────────

# ── Stage 1 : front-end build ────────────────────────────────────────────────
FROM node:22-alpine AS node-builder

WORKDIR /app

# Copy only the manifests first so docker layer-caches the npm install
# step across deploys when no dep changes.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Now bring in everything vite needs : sources + config + the Laravel
# blade templates (the @vite directive resolves manifest paths at build).
# Bring the whole repo so vite can find any dynamically-resolved paths ;
# the .dockerignore keeps vendor/, node_modules/, .git/ out.
COPY . .

RUN npm run build

# ── Stage 2 : composer install ───────────────────────────────────────────────
FROM composer:2 AS composer-builder

WORKDIR /app

# composer.json + lock first, again for layer caching.
COPY composer.json composer.lock ./

# --no-dev keeps the image lean ; --optimize-autoloader is required for
# production performance ; --no-scripts because artisan commands need the
# rest of the app to be present, which it isn't yet.
# --ignore-platform-req=ext-pcntl : the composer:2 image lacks pcntl, which
# laravel/horizon requires. The runtime serversideup image has it, so the
# package will work in production ; we just need to bypass the install-time
# platform check.
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-pcntl

# Now bring in the rest of the app and finalise the autoloader.
COPY . .
RUN composer dump-autoload --classmap-authoritative

# ── Stage 3 : final runtime image ────────────────────────────────────────────
# Tried serversideup/php first — its s6-overlay entrypoint refuses to run on
# Fly.io ("s6-overlay-suexec: fatal: can only run as pid 1"). Tried richarvey
# next — that ships PHP 8.2 and our composer lock requires 8.4. webdevops
# is actively maintained, has a clean PHP 8.4 alpine tag, runs nginx +
# php-fpm under supervisord, and works on Fly without any PID 1 gymnastics.
FROM webdevops/php-nginx:8.4-alpine AS final

# webdevops uses /app as the document root by default. Symlink to
# /var/www/html so the rest of the build can stay consistent with Laravel
# conventions (and so existing /var/www/html paths in env / config still
# resolve correctly if anything reads them at runtime).
ENV WEB_DOCUMENT_ROOT=/app/public
ENV APP_ROOT=/app

WORKDIR /app

# Pull in the built app + composer vendor dir + compiled front-end manifest.
COPY --from=composer-builder /app/ /app/
COPY --from=node-builder /app/public/build/ /app/public/build/

# webdevops runs as the "application" user (uid 1000) by default — give it
# write access to the framework's runtime directories.
RUN chown -R application:application /app/storage /app/bootstrap/cache \
 && chmod -R ug+rwX /app/storage /app/bootstrap/cache

# Port 80 is the webdevops default ; matches fly.toml internal_port.
EXPOSE 80

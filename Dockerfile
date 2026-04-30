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
FROM serversideup/php:8.4-fpm-nginx-alpine AS final

# serversideup runs as the unprivileged "www-data" user by default on port 8080.
# We need to be root briefly to install our app, then drop back.
USER root

WORKDIR /var/www/html

# Pull in the built app + composer vendor dir + compiled front-end manifest.
COPY --from=composer-builder /app/ /var/www/html/
COPY --from=node-builder /app/public/build/ /var/www/html/public/build/

# Make sure storage + bootstrap/cache are writable by php-fpm. Sail does
# this in dev via the volume mount ; we have to bake it in.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
 && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# Health-check endpoint — Laravel ships /up out of the box (see bootstrap/app.php).
ENV HEALTHCHECK_PATH=/up

# IMPORTANT : do NOT set `USER www-data` here. The serversideup image uses
# s6-overlay as PID 1 (it manages nginx + php-fpm + healthcheck). s6-overlay
# refuses to run as anything other than PID 1 / root, then drops privileges
# to www-data internally for the actual web processes. Setting USER above
# breaks the container with "s6-overlay-suexec: fatal: can only run as pid 1".

# Port 8080 is the serversideup default ; matches fly.toml internal_port.
EXPOSE 8080

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
# Switched off serversideup/php:*-fpm-nginx-alpine because its s6-overlay
# entrypoint refuses to run on Fly.io machines : "s6-overlay-suexec: fatal:
# can only run as pid 1" even when started as root. Fly's machine runtime
# wraps containers in a way that breaks s6's PID 1 check.
#
# This image (richarvey/nginx-php-fpm) bundles nginx + php-fpm under
# supervisord (which doesn't care about PID 1) and works cleanly on Fly.
# It's also smaller and a common pick for Laravel on container hosts.
FROM richarvey/nginx-php-fpm:3.1.6 AS final

WORKDIR /var/www/html

# Pull in the built app + composer vendor dir + compiled front-end manifest.
COPY --from=composer-builder /app/ /var/www/html/
COPY --from=node-builder /app/public/build/ /var/www/html/public/build/

# Make sure storage + bootstrap/cache are writable by php-fpm. Sail does
# this in dev via the volume mount ; we have to bake it in.
RUN chown -R nginx:nginx /var/www/html/storage /var/www/html/bootstrap/cache \
 && chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

# Tell richarvey's image where Laravel's public dir lives (it defaults to
# /var/www/html which already matches, but being explicit is safer when
# upstream defaults change). WEBROOT must point at the public/ folder
# so nginx serves index.php through laravel's front controller.
ENV WEBROOT=/var/www/html/public
ENV SKIP_COMPOSER=1
ENV PHP_ERRORS_STDERR=1
ENV REAL_IP_HEADER=1

# Port 80 is the richarvey default. fly.toml internal_port matches.
EXPOSE 80

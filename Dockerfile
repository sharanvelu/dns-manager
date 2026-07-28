# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: build frontend assets (Vite / React / Tailwind)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

# Install dependencies first for layer caching.
# The repo's .npmrc sets ignore-scripts=true; we pass --ignore-scripts
# explicitly so behaviour is identical whether or not .npmrc is present.
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

# Only what `vite build` needs. Note: the `ziggy-js` import in app.tsx is
# type-only and erased at build time, so vendor/ is NOT required here.
COPY vite.config.js tsconfig.json components.json eslint.config.js ./
COPY public/ public/
COPY resources/ resources/

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 2: build the static documentation site (Next.js export)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS docs

WORKDIR /app

# Dependencies first for layer caching. docs-site ships its own .npmrc
# (ignore-scripts=false) so Next.js/SWC install scripts run normally.
COPY docs-site/package.json docs-site/package-lock.json docs-site/.npmrc docs-site/
RUN npm ci --prefix docs-site

# The build resolves ../VERSION relative to docs-site/ (version pill), so
# the repo-relative layout must be reproduced inside the stage.
COPY VERSION ./
COPY docs-site/ docs-site/

# Static export: docs-site/out/docs/ is the complete site (basePath /docs).
RUN npm run build --prefix docs-site

# ---------------------------------------------------------------------------
# Stage 3: install PHP dependencies (Composer)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Dependency manifests first for layer caching.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

# Full application source (filtered by .dockerignore), then the optimized
# autoloader. --no-scripts skips `artisan package:discover`; the entrypoint
# runs it at container start instead.
COPY . .
RUN composer dump-autoload \
    --optimize \
    --classmap-authoritative \
    --no-dev \
    --no-scripts

# ---------------------------------------------------------------------------
# Stage 4: runtime (php-fpm + nginx under supervisord, non-root)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS runtime

# PHP extensions via mlocati/php-extension-installer.
# Redis client is predis (pure PHP) -> no redis extension needed.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql pgsql pcntl opcache intl zip bcmath \
    && apk add --no-cache nginx supervisor

WORKDIR /var/www/html

# Configuration
COPY docker/php.ini        /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php-fpm.conf   /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf     /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh  /usr/local/bin/docker-entrypoint

# Application code + vendor from the composer stage, built assets from the
# assets stage, static documentation site (served by nginx at /docs) from
# the docs stage.
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build
COPY --from=docs --chown=www-data:www-data /app/docs-site/out/docs /var/www/html/public/docs

# Ensure writable runtime directories exist and everything nginx/php needs
# is accessible to www-data (container runs fully non-root).
RUN chmod +x /usr/local/bin/docker-entrypoint \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        /var/lib/nginx/logs \
        /var/lib/nginx/tmp \
    && chown -R www-data:www-data \
        storage \
        bootstrap/cache \
        public \
        /var/lib/nginx \
        /var/log/nginx

# Numeric UID (www-data on Alpine is 82): kubernetes `runAsNonRoot` cannot
# verify a named user and rejects the pod with "non-numeric user".
USER 82:82

EXPOSE 8080

ENTRYPOINT ["docker-entrypoint"]

# Web role (default). Worker/scheduler override the command, e.g.:
#   docker-entrypoint php artisan queue:work redis --tries=3 --max-time=3600
#   docker-entrypoint php artisan schedule:work
CMD ["supervisord", "-c", "/etc/supervisord.conf"]

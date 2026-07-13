#!/bin/sh
# Entrypoint for every role (web / queue worker / scheduler / migrate job).
# Caches are built at CONTAINER START, not image build, because
# `config:cache` snapshots the environment (k8s-injected env vars).
set -e

cd /var/www/html

# Package manifest (skipped at build time via composer --no-scripts)
php artisan package:discover --ansi || true

# public/storage -> storage/app/public (idempotent)
php artisan storage:link || true

php artisan config:cache
php artisan view:cache

# routes/web.php contains closure-based routes, which cannot be cached.
# Attempt it anyway so we benefit automatically once routes move to
# controllers; tolerate failure until then.
php artisan route:cache || echo "route:cache skipped (closure-based routes cannot be cached)"

# Run Migration against every deployment
php artisan migrate --force

# Run whatever role command was passed (supervisord, queue:work, ...)
exec "$@"

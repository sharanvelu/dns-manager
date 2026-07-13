#!/bin/sh
# Entrypoint for every role (web / queue worker / scheduler / migrate job).
# Caches are built at CONTAINER START, not image build, because
# `config:cache` snapshots the environment (k8s-injected env vars).
set -e

cd /var/www/html

# supervisord.conf expands these; default to a fully self-contained
# container (web + worker + scheduler). Kubernetes sets them to false and
# runs dedicated worker/scheduler Deployments instead.
export SUPERVISOR_WORKER="${SUPERVISOR_WORKER:-true}"
export SUPERVISOR_SCHEDULER="${SUPERVISOR_SCHEDULER:-true}"

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

# Migrations are the k8s Job's responsibility (k8s/job-migrate.yaml), so
# multi-replica rollouts don't race. Single-container Docker setups can opt
# in with AUTO_MIGRATE=true.
if [ "${AUTO_MIGRATE:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Run whatever role command was passed (supervisord, queue:work, ...)
exec "$@"

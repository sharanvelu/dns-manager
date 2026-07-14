---
title: Installation
nav_order: 2
description: Run DNS Manager with Docker or Kubernetes, configure environment variables, and log in for the first time.
---

# Installation

DNS Manager ships as a single container image, `ghcr.io/OWNER/dns-manager` (replace `OWNER` with the GitHub user or organization hosting your build). By default one container runs all three roles — web, queue worker, and scheduler — under its supervisor.

## Requirements

- **PostgreSQL 16** — the application database (source of truth for all DNS entries).
- **Redis** — backs the job queue that performs all provider pushes and drift checks.
- **An OIDC provider** — DNS Manager has no local accounts; sign-in is OpenID Connect only. Any spec-compliant provider works (Authentik, Keycloak, Auth0, ...).
- **Somewhere to run containers** — Kubernetes (manifests included) or plain Docker.

## Quick start with Docker

Generate an application key first (any Laravel-compatible 32-byte key works):

```sh
docker run --rm ghcr.io/OWNER/dns-manager php artisan key:generate --show
```

A single container is fully self-contained: its supervisor runs nginx + PHP, the queue worker, **and** the scheduler. The web role listens on port 8080.

```sh
# AUTO_MIGRATE=true runs database migrations at container start — the
# Kubernetes ConfigMap sets it too, so both setups migrate automatically.
docker run -d --name dns-manager -p 8080:8080 \
  -e APP_KEY="base64:..." \
  -e AUTO_MIGRATE=true \
  -e APP_ENV=production -e APP_DEBUG=false \
  -e APP_URL=https://dns.example.com \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=dns_manager -e DB_USERNAME=dns_manager -e DB_PASSWORD=secret \
  -e REDIS_HOST=redis -e REDIS_PORT=6379 \
  -e QUEUE_CONNECTION=redis \
  -e OIDC_BASE_URL=https://auth.example.com/application/o/dns-manager \
  -e OIDC_CLIENT_ID=... -e OIDC_CLIENT_SECRET=... \
  ghcr.io/OWNER/dns-manager
```

To split the roles into separate containers instead (an advanced setup for scaling out — not needed for a homelab), set `SUPERVISOR_WORKER=false` and `SUPERVISOR_SCHEDULER=false` on the web container and run two more containers with the same env, overriding the command with `php artisan queue:work redis` and `php artisan schedule:work` respectively.

The container entrypoint caches configuration (and migrates when `AUTO_MIGRATE=true`), so a fresh database is ready as soon as the container is up. Verify with:

```sh
curl -f http://localhost:8080/up
```

## Kubernetes

The repository ships ready-made manifests under `k8s/`:

| Manifest | Purpose |
| --- | --- |
| `configmap.yaml` | Non-secret environment (APP_URL, DB/Redis hosts, queue settings, `AUTO_MIGRATE=true`) |
| `secret.example.yaml` | Template for secrets (APP_KEY, DB password, OIDC client secret) — copy to `secret.yaml`, never commit it |
| `deployment.yaml` | The single `dns-manager` pod: nginx + php-fpm + queue worker + scheduler via supervisord (image default command) |
| `service.yaml`, `ingress.yaml` | Expose the app |
| `volume.yaml` | *Optional* PersistentVolumeClaim for `storage/app` — excluded by default (the app is stateless; Postgres and Redis are external) |
| `cronjob.yaml` | *Optional* Kubernetes-native scheduler alternative — runs `php artisan dns:check-drift` every 15 minutes; set `SCHEDULER_ENABLED=false` if you use it |
| `kustomization.yaml` | Ties it all together for `kubectl apply -k` (secret, volume, and cronjob are commented out by default) |

The deployment runs **one pod** (`replicas: 1`, `Recreate` strategy) covering all three roles, exactly like the single Docker container above. Keep replicas at 1 — the scheduler must run at most once per installation. `AUTO_MIGRATE=true` in the ConfigMap runs database migrations at pod start, so there is no separate migrate Job.

One-time setup: replace `OWNER` in `deployment.yaml`, set your hostname in `configmap.yaml` (`APP_URL`) and `ingress.yaml`, point `DB_HOST` / `REDIS_HOST` at your services, and create `secret.yaml` from the example. Then:

```sh
kubectl create namespace dns-manager
kubectl apply -k k8s/
```

Health probes hit Laravel's built-in `/up` endpoint on port 8080. See `k8s/README.md` in the repository for the full command sequence.

## Environment reference

| Variable | Purpose |
| --- | --- |
| `APP_KEY` | Laravel encryption key. Also encrypts provider credentials at rest — if you lose it, you must re-enter every provider's credentials. Back it up. |
| `APP_URL` | Public URL of the app (must match your ingress hostname). |
| `FORCE_HTTPS` | `true` generates every URL (assets, redirects) with the https scheme. Set it whenever TLS terminates in front of the app (Kubernetes ingress, reverse proxy) and the container itself is reached over plain http — otherwise browsers block the http asset URLs as mixed content. Defaults to `false`. The app additionally trusts proxy `X-Forwarded-*` headers, so request-derived URLs (pagination links) also come out https when the proxy sends `X-Forwarded-Proto: https` — no extra configuration needed. |
| `APP_ENV` / `APP_DEBUG` | Set to `production` / `false` when deployed. |
| `DB_CONNECTION` | `pgsql` |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | PostgreSQL 16 connection. |
| `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD` | Redis connection (queue backend). |
| `QUEUE_CONNECTION` | `redis` in production. All syncing runs through this queue. |
| `OIDC_BASE_URL` | Issuer base URL — the part before `/.well-known/openid-configuration`. |
| `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | OIDC client credentials registered with your identity provider. |
| `OIDC_REDIRECT_URI` | Callback URL; defaults to `${APP_URL}/auth/callback`. Register this URI with your identity provider. |
| `OIDC_PROVIDER_LABEL` | Text on the sign-in button, e.g. `Authentik` renders "Sign in with Authentik". Defaults to `SSO`. |
| `DOCS_SITE_URL` | URL of the hosted latest-version documentation site, linked from the in-app docs banner. |
| `AUTO_MIGRATE` | `true` runs database migrations at container start. The Kubernetes ConfigMap sets it to `true`; do the same for single-container Docker setups. Defaults to `false`. |
| `SUPERVISOR_WORKER` / `SUPERVISOR_SCHEDULER` | Whether the container's supervisor also runs the queue worker / scheduler. Default `true` (self-contained container); only set `false` in the advanced setup that splits the roles into separate containers/Deployments with command overrides. |
| `SCHEDULER_ENABLED` | `false` disables the built-in drift-check schedule entirely — use when an external tool triggers checks via the webhook instead. Defaults to `true`. |
| `DRIFT_CHECK_CRON` | Cron expression for the built-in drift-check schedule. Defaults to `*/15 * * * *` (every 15 minutes). |
| `DRIFT_CHECK_TRIGGER_TOKEN` | Bearer token enabling `POST /api/hooks/drift-check` for external automation. The endpoint stays disabled (404) while unset. |

## Running roles

The worker and scheduler are **not optional**. The web role only writes to the database and queues jobs:

| Role | Command | What breaks without it |
| --- | --- | --- |
| Web | image default (nginx + php-fpm via supervisord) | The UI itself. |
| Worker | `php artisan queue:work redis` | Nothing is ever pushed to or deleted from any provider; entries sit at "Pending" forever. |
| Scheduler | `php artisan schedule:work` | No automatic drift checks (manual checks still work, via the worker). |

By default the container's supervisor runs **all three** (see `SUPERVISOR_WORKER` / `SUPERVISOR_SCHEDULER` above) — on Kubernetes the single pod covers everything, just like a standalone Docker container. Run at most **one** scheduler across your whole installation — the schedule also takes a cache lock (`onOneServer`) as a safety net.

## Automating drift checks externally

The built-in schedule queues the drift checker (`php artisan dns:check-drift`) on the `DRIFT_CHECK_CRON` expression. If you'd rather drive it from an external tool (N8N, cron, CI), set a `DRIFT_CHECK_TRIGGER_TOKEN` and call the webhook:

```sh
# check all enabled providers
curl -X POST https://dns.example.com/api/hooks/drift-check \
  -H "Authorization: Bearer $DRIFT_CHECK_TRIGGER_TOKEN"

# or a single provider
curl -X POST https://dns.example.com/api/hooks/drift-check \
  -H "Authorization: Bearer $DRIFT_CHECK_TRIGGER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"provider_id": 1}'
```

The response reports what was queued: `{"queued": 2, "providers": ["Cloudflare — example.com", "Pi-hole — homelab"]}`. Requests without the correct token get `401`; while no token is configured the endpoint returns `404`. Set `SCHEDULER_ENABLED=false` if the external trigger fully replaces the built-in schedule.

On Kubernetes there is a third option: the optional `cronjob.yaml` manifest runs `php artisan dns:check-drift` as a native CronJob every 15 minutes — set `SCHEDULER_ENABLED=false` when using it.

## First login

Open the app and click the sign-in button. There is no registration step: users are auto-provisioned on first OIDC login, matched by OIDC subject and then by email. Avatars come from Gravatar (falling back to initials).

The **first user to sign in becomes the Super Admin**. Everyone after that starts as a read-only Viewer until the Super Admin assigns roles under Settings → Users — see [Users & Roles](users).

## Upgrading

1. Pull the new image tag.
2. Restart the container — on Kubernetes bump the image tag or run `kubectl -n dns-manager rollout restart deploy/dns-manager`.
3. That's it: with `AUTO_MIGRATE=true` set, database migrations run automatically at container start.

Documentation for the exact version you are running is always available on your own instance at `/docs` — no login required.

## Next steps

Connect your first provider on the [Providers](providers) page, then create records on the [DNS Entries](dns-entries) page.

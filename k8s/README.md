# DNS Manager — Kubernetes deployment

**One pod = the whole app.** The single container runs nginx + php-fpm +
queue worker + scheduler via supervisord — exactly like running the image
as a single Docker container. Postgres 16 and Redis are expected to already
exist (in-cluster or reachable externally). Migrations run automatically at
pod start (`AUTO_MIGRATE=true`).

## Files

| File | Purpose | In kustomization? |
|---|---|---|
| `kustomization.yaml` | Ties it all together, sets the namespace | — |
| `configmap.yaml` | Non-secret app config (`APP_URL`, DB/Redis hosts, …) | yes |
| `deployment.yaml` | The single app pod (all roles) | yes |
| `service.yaml` | ClusterIP, port 80 → pod 8080 | yes |
| `ingress.yaml` | TLS ingress (nginx + cert-manager annotations) | yes |
| `secret.example.yaml` | Template for `secret.yaml` (APP_KEY, DB creds, OIDC, …) | copy first |
| `volume.yaml` | Optional PVC for persistent `storage/app` | no (optional) |
| `cronjob.yaml` | Optional k8s-native drift-check scheduler | no (optional) |

## One-time setup

1. Replace placeholders:
   - `OWNER` in `deployment.yaml` (and `cronjob.yaml` if used) — your GitHub user/org
   - `dns.example.com` in `configmap.yaml` (`APP_URL`) and `ingress.yaml`
   - `DB_HOST` / `REDIS_HOST` in `configmap.yaml`
2. If GHCR packages are private, add an image pull secret and reference it
   in the deployment (`imagePullSecrets`).

## Deploy

```sh
# 1. The namespace must exist (kustomize's `namespace:` field only labels
#    resources; it does not create the namespace):
kubectl create namespace dns-manager

# 2. Create the secret:
cp k8s/secret.example.yaml k8s/secret.yaml   # edit values; do NOT commit
# APP_KEY: php artisan key:generate --show
# then uncomment `- secret.yaml` in kustomization.yaml
# (or create it imperatively — see the header of secret.example.yaml)

# 3. Apply everything:
kubectl apply -k k8s/
```

Health check: probes hit Laravel's `/up` endpoint on port 8080. The
readiness probe allows generous startup time because migrations run before
the web server accepts traffic.

## Upgrades

```sh
# Bump the image tag in deployment.yaml and re-apply:
kubectl apply -k k8s/

# ...or, if you track :latest, just restart:
kubectl -n dns-manager rollout restart deploy/dns-manager
```

Migrations run automatically when the new pod starts (`AUTO_MIGRATE=true`
in the ConfigMap). `strategy: Recreate` guarantees the old pod is gone
before the new one migrates, at the cost of a brief downtime window.

## Optional: persistent storage

The app is stateless by default — all state lives in Postgres/Redis. If you
want `storage/app` (e.g. uploaded files) to survive pod restarts:

1. Add `- volume.yaml` to `kustomization.yaml`.
2. Uncomment the `volumeMounts` and `volumes` blocks in `deployment.yaml`.

## Optional: k8s-native scheduler (CronJob)

By default the in-pod scheduler triggers drift checks (`SCHEDULER_ENABLED`
+ `DRIFT_CHECK_CRON` in the ConfigMap) and provider health checks
(`PROVIDER_HEALTH_CHECK_ENABLED` + `PROVIDER_HEALTH_CHECK_CRON`). To use
a Kubernetes CronJob instead:

1. Set `SCHEDULER_ENABLED: "false"` (or `SUPERVISOR_SCHEDULER: "false"`)
   in `configmap.yaml`.
2. Add `- cronjob.yaml` to `kustomization.yaml` (schedule: `*/15 * * * *`,
   `concurrencyPolicy: Forbid`). Duplicate the manifest with
   `dns:check-provider-health` if you also want scheduled health checks.

## Scaling out (advanced — not the default)

This layout is intentionally single-replica: the in-pod scheduler and
start-time migrations are not safe to run in parallel. If you outgrow one
pod:

1. Set `SUPERVISOR_WORKER: "false"` and `SUPERVISOR_SCHEDULER: "false"` in
   the ConfigMap so web pods only serve HTTP.
2. Add dedicated Deployments with command overrides
   (`docker-entrypoint php artisan queue:work redis ...` and
   `docker-entrypoint php artisan schedule:work`, the scheduler at
   exactly 1 replica).
3. Set `AUTO_MIGRATE: "false"` and run migrations via a one-shot Job (or
   manually with `kubectl exec ... php artisan migrate --force`) so
   replicas don't race migrations.
4. Then the web Deployment can use `RollingUpdate` and `replicas > 1`.

## Build locally (optional)

```sh
docker build -t ghcr.io/OWNER/dns-manager:dev .
docker run --rm -p 8080:8080 --env-file .env ghcr.io/OWNER/dns-manager:dev
curl -f http://localhost:8080/up
```

CI builds and pushes automatically on merge to `master` (tag `latest` +
`sha-<short>`) and on `v*` tags (semver) — the `build` job in `.github/workflows/ci.yml`, which only runs after lint and tests pass.

## Notes

- `config:cache` / `view:cache` run in the entrypoint at container start so
  they pick up k8s env vars; `route:cache` is attempted but skipped while
  routes use closures.

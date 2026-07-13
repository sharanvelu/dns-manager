# DNS Manager — Kubernetes deployment

One image (`ghcr.io/OWNER/dns-manager`), three roles: web (nginx + php-fpm
via supervisord), queue worker, and scheduler. Postgres 16 and Redis are
expected to already exist in the cluster (or be reachable externally).

## One-time setup

1. Replace placeholders:
   - `OWNER` in `deployment-*.yaml` and `job-migrate.yaml` (your GitHub user/org)
   - `dns.example.com` in `configmap.yaml` (`APP_URL`) and `ingress.yaml`
   - `DB_HOST` / `REDIS_HOST` in `configmap.yaml`
2. Create the secret:

   ```sh
   cp k8s/secret.example.yaml k8s/secret.yaml   # edit values; do NOT commit
   # APP_KEY: php artisan key:generate --show
   ```

   Then uncomment `- secret.yaml` in `kustomization.yaml` (or create the
   secret with `kubectl create secret generic` — see secret.example.yaml).
3. If GHCR packages are private, add an image pull secret and reference it
   in the deployments (`imagePullSecrets`).

## Deploy

```sh
# Everything via kustomize (namespace first is handled automatically)
kubectl apply -k k8s/

# Or plain YAML, in order:
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/configmap.yaml
kubectl apply -f k8s/secret.yaml
kubectl apply -f k8s/job-migrate.yaml
kubectl -n dns-manager wait --for=condition=complete job/dns-manager-migrate --timeout=120s
kubectl apply -f k8s/deployment-web.yaml -f k8s/deployment-worker.yaml -f k8s/deployment-scheduler.yaml
kubectl apply -f k8s/service.yaml -f k8s/ingress.yaml
```

## On each new image (rolling update)

```sh
kubectl -n dns-manager delete job dns-manager-migrate --ignore-not-found
kubectl apply -f k8s/job-migrate.yaml
kubectl -n dns-manager rollout restart deploy/dns-manager-web deploy/dns-manager-worker deploy/dns-manager-scheduler
```

## Build locally (optional)

```sh
docker build -t ghcr.io/OWNER/dns-manager:dev .
docker run --rm -p 8080:8080 --env-file .env ghcr.io/OWNER/dns-manager:dev
curl -f http://localhost:8080/up
```

CI builds and pushes automatically on merge to `master` (tag `latest` +
`sha-<short>`) and on `v*` tags (semver) — see `.github/workflows/build.yml`.

## Notes

- Health probes hit Laravel's built-in `/up` endpoint on port 8080.
- `config:cache` / `view:cache` run in the entrypoint at container start so
  they pick up k8s env vars; `route:cache` is attempted but skipped while
  routes use closures.
- Migrations run only via the Job, never in the entrypoint.

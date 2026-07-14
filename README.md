# DNS Manager

Manage DNS entries across multiple providers from one place. Entries created here are automatically pushed to every enabled provider that supports the record type, and a scheduled drift check flags records that were changed or removed behind the app's back.

**Providers in v1:** Cloudflare (public DNS, 9 record types) and Pi-hole v6 (local DNS: A / AAAA / CNAME). The connector architecture is designed for adding Technitium, Unbound, and others later.

## Documentation

- **User docs**: source of truth in [`docs/content/`](docs/content/) — served by your running instance at **`/docs`** (matching your installed version) and published for the latest version by the standalone [`docs-site/`](docs-site/) (Next.js).
- **Agent/contributor docs**: [AGENTS.md](AGENTS.md) (start here), [ARCHITECTURE.md](ARCHITECTURE.md), [DESIGN.md](DESIGN.md), [CLAUDE.md](CLAUDE.md).

> ⚠️ These docs are kept in sync with the code as a hard rule: any change to the application must update the matching documentation in the same change set. See AGENTS.md.

## Stack

- Laravel 12 (PHP 8.4) + Inertia.js 2 + React 19 + TypeScript + Tailwind CSS 4
- PostgreSQL 16 (managed migrations), Redis (queue)
- OIDC authentication (any spec-compliant provider: Authentik, Keycloak, Auth0, ...)
- Provider credentials stored encrypted at rest (AES-256 via `APP_KEY`)

## Local development

Requirements: PHP 8.4, Composer, Node 22+, PostgreSQL 16, Redis.

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate

# configure DB_*, REDIS_*, OIDC_* in .env, then:
php artisan migrate
composer run dev   # serves app + queue worker + vite
```

Run tests with `./vendor/bin/pest`, format with `./vendor/bin/pint`.

### Environment

| Variable | Purpose |
| --- | --- |
| `OIDC_BASE_URL` | Issuer base URL (the part before `/.well-known/openid-configuration`) |
| `OIDC_CLIENT_ID` / `OIDC_CLIENT_SECRET` | OIDC client credentials |
| `OIDC_REDIRECT_URI` | Defaults to `${APP_URL}/auth/callback` |
| `OIDC_PROVIDER_LABEL` | Sign-in button label, e.g. `Sentrix` → "Sign in with Sentrix" |
| `QUEUE_CONNECTION` | `redis` in production |

## Architecture

```
app/
├── Connectors/            # provider integrations
│   ├── Contracts/DnsConnector.php   # the contract every connector implements
│   ├── DTOs/               # ConfigField, RemoteRecord, TestResult, capabilities
│   ├── CloudflareConnector.php
│   ├── PiholeConnector.php
│   └── ConnectorRegistry.php        # register new connectors here
├── Jobs/                   # SyncEntryToProvider, DeleteEntryFromProvider, CheckProviderDrift, CheckProviderHealth
├── Services/SyncService.php # decides which providers receive which entries
└── Http/Controllers/       # Dashboard, DnsEntry, Provider controllers
```

- A connector declares the record types it **can** manage (`supportedRecordTypes`) and the settings it needs (`configSchema`, rendered dynamically by the Providers UI).
- A provider (a configured connector instance) narrows that to the types it **does** manage — chosen in the UI, stored as `managed_record_types`.
- Saving an entry queues a push to every enabled provider managing that type. The scheduler dispatches a drift check for each provider every 15 minutes and a connectivity health check every 5 minutes.

### Adding a connector

1. Create `app/Connectors/FooConnector.php` extending `AbstractDnsConnector`.
2. Add a case to `App\Enums\ProviderType`.
3. Register the class in `ConnectorRegistry::$connectors`.
4. The Providers UI picks it up automatically from the registry descriptors.

## Deployment

A single container image runs the web, queue worker, and scheduler roles together via supervisord — one standalone Docker container, or one pod on Kubernetes (single Deployment, migrations run at pod start via `AUTO_MIGRATE=true`). See `k8s/README.md` for the manifests and deploy order; the `build` job in `.github/workflows/ci.yml` builds and pushes `ghcr.io/<owner>/dns-manager` on every merge to `master`, after lint and tests pass.

```sh
docker build -t ghcr.io/<owner>/dns-manager:dev .
kubectl create namespace dns-manager
kubectl apply -k k8s/
```

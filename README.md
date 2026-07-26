# DNS Manager

Manage DNS entries across multiple providers from one place. Records live in **DNS zones** with zone-relative names; a provider's credentials are entered once and **attached** to any number of zones, entries are pushed to the attachments you select, and a scheduled drift check flags records that were changed or removed behind the app's back. Access is role-based per zone (Super Admin/Viewer, User Admin globally; Zone Admin, DNS Manager, Viewer, and Provider Manager per zone). Every user action — zone, entry, provider, user/grant, and sign-in changes — is recorded in a built-in audit trail with field-level diffs; provider credentials are never logged.

**Providers:** Cloudflare (public DNS, 9 record types), Pi-hole v6 (local DNS: A / AAAA / CNAME — zoneless, auto-attaches to every zone), and Technitium DNS Server (self-hosted authoritative DNS, 9 record types). The connector architecture is designed for adding Unbound and others later.

## Documentation

- **User docs**: source of truth in [`docs/content/`](docs/content/) — served by your running instance at **`/docs`** (matching your installed version) and published for the latest version by the standalone [`docs-site/`](docs-site/) (Next.js, deployed on Vercel).
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
│   ├── TechnitiumConnector.php
│   └── ConnectorRegistry.php        # register new connectors here
├── Jobs/                   # SyncEntryToProvider, DeleteEntryFromProvider, CheckProviderDrift, CheckProviderHealth
├── Services/               # SyncService (which attachments receive which entries), ZoneAttachmentService
├── Policies/DnsZonePolicy.php       # per-zone role checks
└── Http/Controllers/       # Dashboard, Zone, ZoneProvider, DnsEntry, Provider, User controllers
```

Three levels connect an entry to a provider: a **Provider** holds account credentials, a **zone attachment** (`zone_providers`) carries the zone-specific settings (e.g. the Cloudflare zone ID — auto-discovered), and each **entry** targets a subset of its zone's attachments, with sync state tracked per attachment.

- A connector declares the record types it **can** manage (`supportedRecordTypes`), the credentials it needs (`configSchema`), and its per-zone attachment settings (`zoneConfigSchema`) — both forms are rendered dynamically by the UI.
- A provider (a configured connector instance) narrows that to the types it **does** manage — chosen in the UI, stored as `managed_record_types`.
- Saving an entry queues a push to its selected attachments (all compatible ones by default). The scheduler dispatches a drift check for each provider every 15 minutes and a connectivity health check every 5 minutes.

### Adding a connector

1. Create `app/Connectors/FooConnector.php` extending `AbstractDnsConnector` (implement `zoneConfigSchema()`/`testZone()`/`discoverZoneConfig()` for zoned providers, or mark it zoneless to auto-attach everywhere).
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

# ARCHITECTURE.md

System design reference for DNS Manager. Keep in sync with the code — see the sync rule in [AGENTS.md](AGENTS.md).

## Overview

```
                      ┌────────────────────────────────────────────┐
                      │  Laravel 12 (single image, three roles)    │
 Browser ── Inertia ──▶  web (nginx+fpm) ─┐                        │
                      │  worker (queue:work redis) ◀─ Redis queue  │
                      │  scheduler (schedule:work, 15-min drift)   │
                      └───────┬──────────────┬─────────────────────┘
                              │              │
                        Postgres 16    Connectors (HTTP)
                     (source of truth)   ├─ Cloudflare API (api.cloudflare.com/client/v4)
                                         └─ Pi-hole v6 REST API (self-hosted)
```

- **Auth**: OIDC only (generic discovery via `kovah/laravel-socialite-oidc`). Users auto-provisioned on first login, matched by `oidc_sub` then email. Avatars: Gravatar (`sha256(email)`, `d=404`) with client-side initials fallback. Sign-in button label from `OIDC_PROVIDER_LABEL`.
- **RBAC**: predefined roles in `App\Enums\Role` (`super-admin`, `dns-manager`, `providers-manager`, `viewer`), multi-assignable per user (`users.roles` json). Enforced by Gates (`manage-entries`, `manage-providers`, `manage-users` in `AppServiceProvider`) applied as `can:` route middleware; the UI mirrors them via the shared `auth.can` Inertia prop. First-ever user → Super Admin; later users → Viewer. Guards: ≥1 role per user, last Super Admin cannot be demoted/deleted, no self-deletion. User management lives at Settings → Users (`Settings\UserController`).
- **Frontend**: Inertia 2 + React 19. No separate API; controllers return Inertia pages. One JSON endpoint (`POST /providers/test`) for connection testing.

## Data model

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `providers` | A configured connector instance | `type` (`cloudflare`\|`pihole`), `config` (**encrypted** array: tokens/passwords/urls), `managed_record_types` (json — user-chosen subset of connector-supported types), `enabled`, `health_status`/`health_message`/`last_checked_at` |
| `dns_entries` | Managed DNS records (source of truth) | `name`, `type` (A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR), `content`, `ttl` (null = auto), `priority` (MX/SRV), `proxied` (Cloudflare), `comment`; unique on (name, type, content) |
| `dns_entry_provider` | **Assignment + sync state** (pivot) | `external_id` (provider-side identifier), `sync_status` (`pending`\|`synced`\|`drifted`\|`error`\|`deleting`), `last_synced_at`, `last_error` |
| `sync_logs` | Activity feed | action (`push`\|`delete`\|`drift-check`), status, message |
| `users` | OIDC-provisioned users | `oidc_sub` unique, `avatar_url`, `roles` (json array of Role enum values), password nullable (unused) |

Enums live in `app/Enums`: `RecordType`, `SyncStatus`, `HealthStatus`, `ProviderType`.

## Connector architecture (`app/Connectors/`)

`Contracts/DnsConnector` is the interface every provider implements:

- `supportedRecordTypes()` — what the connector *can* manage. The provider's `managed_record_types` narrows this to what it *does* manage (`Provider::managesType()` checks both).
- `configSchema()` — declarative `ConfigField[]` (key, label, type, secret, required, help). The Providers UI renders its form from this; adding a connector requires no bespoke form code.
- `capabilities()` — `supportsProxied` / `supportsTtl` / `supportsPriority` / TTL bounds. Drives both UI field visibility and drift comparison (`RemoteRecord::matches()` only compares fields the connector supports).
- `testConnection()`, `listRecords()`, `createRecord()`, `updateRecord()`, `deleteRecord()`.

`ConnectorRegistry` maps `ProviderType` → class and exposes `descriptors()` (static metadata for the UI). `AbstractDnsConnector` provides config access and error normalization; connectors throw `ConnectorException` with provider-parsed messages.

**Cloudflare** — Bearer token + zone_id. `ttl: 1` = auto (normalized to `null` locally). `proxied` only for A/AAAA/CNAME. MX priority top-level; SRV/CAA use `data` objects (serialized locally as `"weight port target"` / `flags tag "value"` strings). 429 retry with backoff. `external_id` = Cloudflare record id. **Adopt-on-conflict** (config `adopt_existing`, default on): create failures 81057/81053 trigger a same-name+type lookup; an exact-content match — or a single unambiguous record — is adopted via PUT (DB wins); ambiguous conflicts rethrow.

**Pi-hole v6** — app-password session auth (`POST /api/auth` → `X-FTL-SID` header → `DELETE /api/auth` in `finally`; Pi-hole caps ~16 concurrent sessions). A/AAAA via `PUT/DELETE /api/config/dns/hosts/{"IP host"}`; CNAME via `cnameRecords/{"name,target[,ttl]"}`. No per-entry update: delete-old + put-new. `external_id` = the raw entry string. `supportsTtl=false` so hosts entries never flag TTL drift. Optional `verify_tls=false` for self-signed certs. Identical existing entries are adopted (`adopt_existing`, default on; off = 400 already-exists becomes an error).

## Sync engine

- **Assignment**: the `dns_entry_provider` pivot rows ARE the entry→provider assignment. The entry form sends an explicit `providers: [ids]` selection (default: all enabled providers managing the type). `SyncService::syncEntry(entry, ?providerIds)`:
  - explicit ids → those providers (∩ enabled ∩ managing the type)
  - null (manual re-sync / API without selection) → existing assignment; brand-new entry → all compatible
  - deselected/incompatible providers get remote deletes; **disabled providers are paused, never purged**.
- **Jobs** (queued, retries with backoff): `SyncEntryToProvider` (create-or-update by pivot `external_id`), `DeleteEntryFromProvider` (removes remote, deletes pivot, deletes the entry row when the last `deleting` pivot clears), `CheckProviderDrift` (fetch `listRecords()`, compare via `RemoteRecord::matches()`, mark `drifted`/`synced`, refresh provider health).
- **Scheduler** (`routes/console.php`): schedules `php artisan dns:check-drift` (queues a `CheckProviderDrift` per enabled provider). Cron expression from `DRIFT_CHECK_CRON` (default every 15 min); `SCHEDULER_ENABLED=false` unregisters it; guarded by `withoutOverlapping()` + `onOneServer()`.
- **External trigger**: `POST /api/hooks/drift-check` (routes/api.php — no session/CSRF) queues the same checks, optionally for one `provider_id`. Guarded by `AuthenticateTriggerToken` middleware comparing the bearer token to `DRIFT_CHECK_TRIGGER_TOKEN`; 404 while unset, 401 on mismatch. Built for N8N/cron-style automation.
- **Import from provider** (`ProviderImportController`, behind `can:manage-entries`): `GET providers/{id}/remote-records` returns the connector's live `listRecords()` annotated `new`/`exists`/`managed` against local data (types outside `managed_record_types` hidden); `POST providers/{id}/import` upserts selected records (match on name+type+content, update ttl/priority/proxied) and links pivots to the source provider only, pre-synced with the remote `external_id` — no push jobs, no propagation to other providers.
- **Bulk actions** (`DnsEntryBulkController`, behind `can:manage-entries`): `POST entries/bulk/sync` (re-push each), `POST entries/bulk/providers` (replace assignment via `syncEntry(entry, ids)`), `PATCH entries/bulk` (apply a `set` of type/content/ttl/comment per entry, re-validated with `DnsEntryRules` merged in — invalid or duplicate results are skipped and counted, priority cleared on type change, then re-synced), `DELETE entries/bulk`. Missing ids are dropped silently. Literal `bulk` routes are registered before the `{entry}` routes.
- Every job outcome lands in `sync_logs` (dashboard activity feed).
- **Conflict policy**: the app DB wins — drift is flagged, and re-push ("Sync now") overwrites the remote.

## Documentation system

`docs/content/*.md` (frontmatter: `title`, `nav_order`, `description`) is the single source rendered by:
1. `GET /docs[/{slug}]` — public in-app endpoint (Blade + CommonMark), banner: "docs for installed version vX; latest at DOCS_SITE_URL".
2. `docs-site/` — standalone Next.js static site for the latest version, banner: "for your installed version, open /docs on your instance".

`VERSION` (repo root) → `config('app.version')` and the docs-site build. `DOCS_SITE_URL` env → `config('app.docs_site_url')`.

## Deployment

- **One image** (multi-stage Dockerfile: node assets → composer → php8.4-fpm-alpine + nginx + supervisord, non-root, port 8080). The default command (supervisord) runs nginx + php-fpm + queue worker + scheduler — a single container is fully functional. `SUPERVISOR_WORKER=false` / `SUPERVISOR_SCHEDULER=false` (entrypoint-defaulted, expanded in supervisord.conf) turn the background programs off — only needed for the advanced scaling-out setup that splits roles into separate deployments via command override.
- `k8s/`: **single-pod** — one Deployment (`dns-manager`, `replicas: 1`, `Recreate`) running the image default, mirroring a standalone Docker container. `AUTO_MIGRATE=true` in the ConfigMap migrates at pod start (no migrate Job). Plus configmap, secret template, Service, Ingress, optional PVC for `storage/app` (`volume.yaml`, excluded by default — app is stateless, Postgres/Redis external), optional CronJob scheduler alternative (`cronjob.yaml`, runs `dns:check-drift` every 15 min with `SCHEDULER_ENABLED=false`), kustomization (namespace `dns-manager`, created via `kubectl create namespace`). Health probes on Laravel's `/up`.
- nginx: `fastcgi_buffer_size 32k` (Laravel header sizes vs 4k default — see AGENTS.md gotchas). Vite preloading disabled app-side for the same reason.
- CI: `ci.yml` (jobs: `lint` — Pint/Prettier/ESLint/tsc/Vite build, check-only — and `tests` — Pest on sqlite `:memory:`) runs on every push to any branch and on PRs. `build.yml` → GHCR (`latest`, `sha-*`, semver on `v*` tags) triggers on pushes to master only — with master push-protected, that means merged PRs. Docs site builds separately (`docs-site` workflow/image). All workflows use only the automatic `GITHUB_TOKEN` — no repo secrets or variables required.

## Testing strategy

- Pest on sqlite `:memory:`; `Http::preventStrayRequests()` globally — connector tests use `Http::fake()` with realistic API fixtures.
- Coverage: connector behavior (both providers), sync targeting/deletion/drift semantics, encryption at rest, secret non-exposure, OIDC flow, validation, page rendering with props.

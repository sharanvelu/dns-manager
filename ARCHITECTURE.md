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
- **RBAC**: predefined roles in `App\Enums\Role` (`super-admin`, `dns-manager`, `providers-manager`, `viewer`), multi-assignable per user (`users.roles` json). Enforced by Gates (`manage-entries`, `manage-providers`, `manage-users`, `view-activity` in `AppServiceProvider`) applied as `can:` route middleware; the UI mirrors them via the shared `auth.can` Inertia prop. First-ever user → Super Admin; later users → Viewer. Guards: ≥1 role per user, last Super Admin cannot be demoted/deleted, no self-deletion. User management lives at Settings → Users (`Settings\UserController`).
- **Frontend**: Inertia 2 + React 19. No separate API; controllers return Inertia pages. One JSON endpoint (`POST /providers/test`) for connection testing.

## Data model

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `providers` | A configured connector instance | `type` (`cloudflare`\|`pihole`), `config` (**encrypted** array: tokens/passwords/urls), `managed_record_types` (json — user-chosen subset of connector-supported types), `enabled`, `health_status`/`health_message`/`last_checked_at` |
| `dns_entries` | Managed DNS records (source of truth) | `name`, `type` (A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR), `content`, `ttl` (null = auto), `priority` (MX/SRV), `proxied` (Cloudflare), `comment`; unique on (name, type, content) |
| `dns_entry_provider` | **Assignment + sync state** (pivot) | `external_id` (provider-side identifier), `sync_status` (`pending`\|`synced`\|`drifted`\|`error`\|`deleting`), `last_synced_at`, `last_error` |
| `sync_logs` | Dashboard activity feed (background jobs) | action (`push`\|`delete`\|`drift-check`), status, message |
| `activity_log` | Audit trail (user actions — spatie/laravel-activitylog v5) | `log_name` (`entries`\|`providers`\|`users`\|`auth`), `event`, `subject_*` (morph), `causer_*` (acting user), `attribute_changes` (trait diff), `properties` (custom payloads) |
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
- **Jobs** (queued, retries with backoff): `SyncEntryToProvider` (create-or-update by pivot `external_id`; when the update targets a record deleted out-of-band the connector throws `RecordNotFoundException` and the job falls back to `createRecord` — Cloudflare signals this on HTTP 404/81044, Pi-hole's delete-then-put update is inherently tolerant), `DeleteEntryFromProvider` (removes remote, deletes pivot, deletes the entry row when the last `deleting` pivot clears), `CheckProviderDrift` (fetch `listRecords()`, compare via `RemoteRecord::matches()`, mark `drifted`/`synced`, refresh provider health), `CheckProviderHealth` (run the connector's `testConnection()`, refresh provider health only — no record comparison).
- **Scheduler** (`routes/console.php`): schedules `php artisan dns:check-drift` (queues a `CheckProviderDrift` per enabled provider; cron from `DRIFT_CHECK_CRON`, default every 15 min) and `php artisan dns:check-provider-health` (queues a `CheckProviderHealth` per enabled provider; cron from `PROVIDER_HEALTH_CHECK_CRON`, default every 5 min, toggled by `PROVIDER_HEALTH_CHECK_ENABLED`). `SCHEDULER_ENABLED=false` unregisters both; each is guarded by `withoutOverlapping()` + `onOneServer()`.
- **External triggers**: `POST /api/hooks/drift-check` and `POST /api/hooks/provider-health-check` (routes/api.php — no session/CSRF) queue the same checks, optionally for one `provider_id`. Guarded by `AuthenticateTriggerToken` middleware comparing the bearer token to `HOOKS_TRIGGER_TOKEN`; 404 while unset, 401 on mismatch. Built for N8N/cron-style automation.
- **Import from provider** (`ProviderImportController`, behind `can:manage-entries`): `GET providers/{id}/remote-records` returns the connector's live `listRecords()` annotated `new`/`exists`/`managed` against local data (types outside `managed_record_types` hidden); `POST providers/{id}/import` upserts selected records (match on name+type+content, update ttl/priority/proxied) and links pivots to the source provider only, pre-synced with the remote `external_id` — no push jobs, no propagation to other providers.
- **Bulk actions** (`DnsEntryBulkController`, behind `can:manage-entries`): `POST entries/bulk/sync` (re-push each), `POST entries/bulk/providers` (replace assignment via `syncEntry(entry, ids)`), `PATCH entries/bulk` (apply a `set` of type/content/ttl/comment per entry, re-validated with `DnsEntryRules` merged in — invalid or duplicate results are skipped and counted, priority cleared on type change, then re-synced), `DELETE entries/bulk`. Missing ids are dropped silently. Literal `bulk` routes are registered before the `{entry}` routes.
- Every job outcome lands in `sync_logs` (dashboard activity feed).
- **Conflict policy**: the app DB wins — drift is flagged, and re-push ("Sync now") overwrites the remote.

## Activity log (audit trail)

`spatie/laravel-activitylog` v5 records user actions in `activity_log` (distinct from `sync_logs`, which tracks background jobs).

- **Model instrumentation**: `DnsEntry`, `Provider`, and `User` use the `LogsActivity` trait with `logOnly(...)` + `logOnlyDirty()` + `dontLogEmptyChanges()`, log names `entries` / `providers` / `users`. The `Provider` allowlist (`name`, `type`, `enabled`, `managed_record_types`) deliberately excludes `config` (secrets) and the health columns — background health/drift checks therefore write **no** activities. A credential change is logged by `ProviderController` as `updated connection settings` with only a `config_changed: true` property, never any value.
- **Custom events** via the `activity()` helper: `delete-requested` on entries when deletion is deferred to queued provider-cleanup jobs (attributes the requesting user; the trait's `deleted` event fires later, system-caused), `providers-changed` on bulk reassignment (property: assigned provider names), and `login` / `logout` in `Auth\OidcController` (log name `auth`).
- **Storage**: v5 stores trait diffs in the `attribute_changes` column and custom payloads in `properties`. Morph map aliases `entry` / `provider` / `user` are registered in `AppServiceProvider`, keeping `subject_type` values short and stable for filters.
- **Viewer**: `Settings\ActivityLogController` serves the Inertia page (`/settings/activity`, filters: subject type/id, event, causer, log name, date range; paginated) and a JSON `data` endpoint consumed by the `ActivityLogDialog` component (the kebab-menu "Activity" popup on entry rows and provider cards). Routes in `routes/settings.php` behind `can:view-activity` (Super Admin only, mirrored by the shared `auth.can.viewActivity` prop). Subject labels for deleted records are recovered from the last logged snapshot.
- **Retention**: `clean_after_days` = 365 in `config/activitylog.php`; pruned by `php artisan activitylog:clean` (not scheduled by the app). `ACTIVITYLOG_ENABLED=false` disables logging.

## Documentation system

`docs/content/*.md` (frontmatter: `title`, `nav_order`, `description`) is the single source rendered by:
1. `GET /docs[/{slug}]` — public in-app endpoint (Blade + CommonMark), banner: "docs for installed version vX; latest at DOCS_SITE_URL".
2. `docs-site/` — standalone Next.js static site for the latest version (deployed on Vercel directly from the repo — no Docker image), banner: "for your installed version, open /docs on your instance".

`VERSION` (repo root) → `config('app.version')` and the docs-site build. `DOCS_SITE_URL` env → `config('app.docs_site_url')`.

## Deployment

- **One image** (multi-stage Dockerfile: node assets → composer → php8.4-fpm-alpine + nginx + supervisord, non-root, port 8080). The default command (supervisord) runs nginx + php-fpm + queue worker + scheduler — a single container is fully functional. `SUPERVISOR_WORKER=false` / `SUPERVISOR_SCHEDULER=false` (entrypoint-defaulted, expanded in supervisord.conf) turn the background programs off — only needed for the advanced scaling-out setup that splits roles into separate deployments via command override.
- `k8s/`: **single-pod** — one Deployment (`dns-manager`, `replicas: 1`, `Recreate`) running the image default, mirroring a standalone Docker container. `AUTO_MIGRATE=true` in the ConfigMap migrates at pod start (no migrate Job). Plus configmap, secret template, Service, Ingress, optional PVC for `storage/app` (`volume.yaml`, excluded by default — app is stateless, Postgres/Redis external), optional CronJob scheduler alternative (`cronjob.yaml`, runs `dns:check-drift` every 15 min with `SCHEDULER_ENABLED=false`), kustomization (namespace `dns-manager`, created via `kubectl create namespace`). Health probes on Laravel's `/up`.
- nginx: `fastcgi_buffer_size 32k` (Laravel header sizes vs 4k default — see AGENTS.md gotchas). Vite preloading disabled app-side for the same reason.
- CI: one pipeline, `ci.yml`. Jobs `lint` (Pint/Prettier/ESLint/tsc/Vite build, check-only) and `tests` (Pest on sqlite `:memory:`) run on every push to any branch and on PRs. Job `build` → GHCR (`latest`, `sha-*`, semver on `v*` tags) runs only on pushes to master or `v*` tags — with master push-protected, that means merged PRs — and `needs: [lint, tests]`, so a red commit never publishes an image. The docs site is NOT built in CI: Vercel deploys `docs-site/` directly on push (root directory `docs-site`, static export to `out/`). All workflows use only the automatic `GITHUB_TOKEN` — no repo secrets or variables required.

## Testing strategy

- Pest on sqlite `:memory:`; `Http::preventStrayRequests()` globally — connector tests use `Http::fake()` with realistic API fixtures.
- Coverage: connector behavior (both providers), sync targeting/deletion/drift semantics, encryption at rest, secret non-exposure, OIDC flow, validation, page rendering with props.

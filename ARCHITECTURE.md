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
                                         ├─ Pi-hole v6 REST API (self-hosted)
                                         └─ Technitium DNS Server HTTP API (self-hosted)
```

- **Auth**: OIDC only (generic discovery via `kovah/laravel-socialite-oidc`). Users auto-provisioned on first login, matched by `oidc_sub` then email — the first-ever user gets `super-admin`, everyone after gets **no roles** (`[]`) until an admin grants access. Avatars: Gravatar (`sha256(email)`, `d=404`) with client-side initials fallback. Sign-in button label from `OIDC_PROVIDER_LABEL`.
- **RBAC**: two-tier — global roles plus per-zone grants. See "Access control (RBAC)" below.
- **Frontend**: Inertia 2 + React 19. No separate API; controllers return Inertia pages. Small JSON endpoints exist where a dialog needs live data without navigation: `POST /providers/test`, `POST zones/{zone}/providers/{zoneProvider}/test`, `POST zones/{zone}/providers/discover`, `GET zones/{zone}/providers/{zoneProvider}/remote-records`, and the activity-log `data` endpoint.

## The zones model

Three levels separate *credentials* from *where they apply*:

1. **Provider** (`providers`) — an account credential for a connector (Cloudflare API token, Pi-hole app password, Technitium API token). `providers.config` holds credentials only — no zone identifiers.
2. **ZoneProvider** (`zone_providers`) — the **attachment** of a provider to a DNS zone, unique per (zone, provider). Carries its own **encrypted** per-zone `config` (e.g. the Cloudflare `zone_id`) and an `enabled` flag. Connectors run in the context of an attachment for all record operations.
3. **DnsZone** (`dns_zones`) — a DNS zone (`example.com`), unique by name. Entries belong to exactly one zone.

Entry names are stored **zone-relative** (`@`, `www`, `*.app`); the FQDN is derived (`DnsEntry::fqdn` accessor → `DnsZone::fqdn($name)`, inverse `DnsZone::relativize($fqdn)`). **Connectors always speak FQDN** — the relative↔FQDN seam lives entirely in the models, so hot paths (drift, listings) must eager-load `entry.zone`.

Per-provider sync state is the `EntrySyncState` pivot on `dns_entry_zone_provider` — keyed to the **zone attachment**, not the bare provider.

The v2 zones overhaul shipped as a deliberate **clean-slate migration** (`2026_07_26_100001_restructure_for_zones`): the old `dns_entries` / `dns_entry_provider` / `sync_logs` tables are dropped and recreated (entries and sync history are not migrated), and the obsolete `zone_id` key is stripped from stored provider configs via a raw `Crypt` round-trip. Provider credentials survive. Shipped migrations are never edited — fresh and existing installs converge on this file.

## Access control (RBAC)

Two tiers, both multi-assignable json role arrays:

1. **Global roles** (`users.roles`, `App\Enums\Role`) — `super-admin` (everything, via `Gate::before`), `super-viewer` (read-only access to *everything*: zones, records, providers, users, activity), `user-admin` (manage users, their global roles, and zone access — nothing else). **Zero roles is valid**: OIDC provisions new users with `[]`; their access can come entirely from zone grants.
2. **Zone roles** (`zone_user` grants, `App\Enums\ZoneRole`) — per (zone, user), unique pair: `zone-admin` (everything in the zone except deleting it, including access grants), `zone-dns-manager` (records: create/edit/sync/import/delete), `zone-viewer` (read-only zone + records + activity), `zone-provider-manager` (the zone's provider attachments only).

**Enforcement split**:

- Zone-scoped abilities live in `DnsZonePolicy` (registered in `AppServiceProvider`); global abilities are Gates there: `create-zones` and `manage-providers` (Super Admin only — the closures return `false`; only `Gate::before` passes), `view-providers` (+ Super Viewer), `manage-users` (User Admin), `view-users` (User Admin + Super Viewer), `view-global-activity` (Super Viewer; Super Admin via before).
- `Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null)` — Super Admin passes every ability. Super Viewer is read-only **by construction**: no mutating gate or policy ability ever returns true for them, so there is nothing to strip later (deny-by-default).

Policy matrix (`DnsZonePolicy`; SA = always via `Gate::before`, SV = Super Viewer, UA = User Admin):

| Ability | Grants that pass |
| --- | --- |
| `view` | SV, or any zone grant |
| `manageRecords` | zone-admin, zone-dns-manager |
| `manageAttachments` | zone-admin, zone-provider-manager |
| `update` | zone-admin |
| `delete` | nobody but SA |
| `viewActivity` | SV, zone-admin, zone-viewer |
| `viewAccess` | SV, UA, zone-admin |
| `manageAccess` | UA, zone-admin |

Zone-scoped mutating routes carry `can:ability,zone` middleware; entry mutations instead authorize in `DnsEntryRequest` — the zone comes from the payload (store) or the bound entry (update), never the URL. `ZONE_ADMIN` grant-target restrictions depend on the request payload and live in `ZoneAccessController`, not the policy: a pure zone-admin actor cannot change their own access, cannot touch grants containing `zone-admin`, and cannot mint `zone-admin` (only SA/UA may).

**One query per request**: every policy check, `zoneCan` prop, and query scope runs off the memoized `User::zoneRolesMap()` (zone id → role values); `forgetZoneRolesMap()` drops it after in-request grant mutations. **Scoping chokepoints**: `User::accessibleZoneIds(?roles)` (null = unrestricted — SA and SV) filters `EntryQuery` (global entries page, belt-and-braces on zone records), the zones index, the dashboard (which never 403s — it renders a `noAccess` state), and `DnsEntryBulkController::selectedEntries()` (which pins to record-managing grants and **silently shrinks** the id selection).

**Frontend prop contracts**: `auth.can` (shared, `HandleInertiaRequests`) = `{ createZones, manageProviders, viewProviders, manageUsers, viewUsers, viewGlobalActivity, hasZoneAccess }` — `hasZoneAccess` gates the sidebar's platform section (SA/SV or any grant). Zone pages pass `zoneCan` built by `App\Support\ZonePermissions::for()` = `{ viewZone, manageRecords, manageAttachments, updateZone, deleteZone, viewActivity, viewAccess, manageAccess }` (`viewZone` exists because a User Admin may open the Access tab without being able to see the zone); entries pages pass the per-zone map `ZonePermissions::mapFor()` = zoneId → `{ manageRecords, viewActivity }`.

**User management** (`UserController`, `/users` + `/users/{user}`): guards — a User Admin (who is not also SA) can never change/delete their own account; only a Super Admin may grant or revoke `super-admin` (either direction — the escalation guard); the last Super Admin can be neither demoted nor deleted. Deleting a user is safe — re-login re-provisions with no access. **Zone access** (`ZoneAccessController`): page `GET /zones/{zone}/access` behind `viewAccess`, grants via `PUT`/`DELETE /zones/{zone}/access/{user}` behind `manageAccess` (grant roles required, min 1 — removing all roles = revoking the grant).

**Migration note**: the role redesign shipped as `2026_07_26_200002_remap_user_roles` — existing `super-admin`s keep full access, every other user (old `dns-manager`/`providers-manager`/`viewer`) becomes `super-viewer` until granted zone access. String literals only, so the migration stays valid as the enums evolve.

## Data model

| Table | Purpose | Notable columns |
| --- | --- | --- |
| `providers` | An account credential for a connector | `type` (`cloudflare`\|`pihole`\|`technitium`), `config` (**encrypted** array: tokens/passwords/urls — credentials only, never zone ids), `managed_record_types` (json — user-chosen subset of connector-supported types), `enabled`, `health_status`/`health_message`/`last_checked_at` |
| `dns_zones` | DNS zones entries live under | `name` (unique), `description` |
| `zone_providers` | **Zone↔provider attachment** | `dns_zone_id` + `provider_id` (unique pair), `config` (**encrypted** array of per-zone settings, e.g. Cloudflare `zone_id`), `enabled` |
| `dns_entries` | Managed DNS records (source of truth) | `dns_zone_id`, `name` (**zone-relative**: `@`, `www`, `*.app`), `type` (A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR), `content`, `ttl` (null = auto), `priority` (MX/SRV), `proxied` (Cloudflare), `comment`; unique on (zone, name, type, content) |
| `dns_entry_zone_provider` | **Assignment + sync state** (`EntrySyncState` pivot: entry × zone attachment) | `dns_entry_id` + `zone_provider_id` (unique pair), `external_id` (provider-side identifier — `text`, since Technitium ids embed full rData like 2 KB TXT values), `sync_status` (`pending`\|`synced`\|`drifted`\|`error`\|`deleting`), `last_synced_at`, `last_error` |
| `sync_logs` | Activity feed (background jobs) | `dns_zone_id` (nullable — powers the zone Providers-tab feed), action (`push`\|`delete`\|`drift-check`\|`import`), status, message |
| `activity_log` | Audit trail (user actions — spatie/laravel-activitylog v5) | `log_name` (`entries`\|`providers`\|`zones`\|`users`\|`auth`), `event`, `subject_*` (morph), `causer_*` (acting user), `attribute_changes` (trait diff), `properties` (custom payloads) |
| `users` | OIDC-provisioned users | `oidc_sub` unique, `avatar_url`, `roles` (json array of global Role values — may be empty), password nullable (unused) |
| `zone_user` | **Zone access grant** (`ZoneUser` model, morph alias `zone-grant`) | `dns_zone_id` + `user_id` (unique pair), `roles` (json array of ZoneRole values) |

Enums live in `app/Enums`: `RecordType`, `SyncStatus`, `HealthStatus`, `ProviderType`, `Role`, `ZoneRole`.

## Connector architecture (`app/Connectors/`)

`Contracts/DnsConnector` is the interface every provider implements:

- `supportedRecordTypes()` — what the connector *can* manage. The provider's `managed_record_types` narrows this to what it *does* manage (`Provider::managesType()` checks both; `ZoneProvider::managesType()` additionally requires both the attachment and the provider to be enabled).
- `configSchema()` — declarative `ConfigField[]` (key, label, type, secret, required, help) for the **credential** form. The Providers UI renders its form from this; adding a connector requires no bespoke form code.
- `zoneConfigSchema()` — same `ConfigField[]` idea for the **per-zone attachment** form (Cloudflare: `zone_id`). Zoneless connectors return `[]` (the `AbstractDnsConnector` default) — and a zoned connector may too (Technitium addresses the remote zone by the DnsZone's own name, so its attachments need no config).
- `capabilities()` — `supportsProxied` / `supportsTtl` / `supportsPriority` / `supportsZones` / TTL bounds. Drives UI field visibility, drift comparison (`RemoteRecord::matches()` only compares fields the connector supports), auto-attachment, and how drift checks iterate (see below).
- `testConnection()` (credential-level), `testZone()` (attachment-level — zoneless connectors fall back to `testConnection()`), `discoverZoneConfig(string $zoneName): ?array` (look the zone up remotely to pre-fill the attachment config; default `null`).
- `listRecords()`, `createRecord()`, `updateRecord()`, `deleteRecord()` — these speak **FQDN** (`$entry->fqdn`), never relative names.

`ConnectorRegistry` maps `ProviderType` → class, builds instances via `for(Provider|ZoneProvider)` or `make(type, provider, ?zoneProvider)`, and exposes `descriptors()` (static metadata incl. both config schemas for the UI). `AbstractDnsConnector` holds `(Provider, ?ZoneProvider)`; `config()` returns the decrypted provider config **overlaid** with the attachment config (attachment wins), and `requireZoneContext()` makes zone-scoped operations fail loudly when invoked without an attachment. Connectors throw `ConnectorException` with provider-parsed messages.

**Cloudflare** — Bearer token (credential config: `api_token`, `adopt_existing`); the `zone_id` lives in the **attachment** config (`zoneConfigSchema`). `testConnection()` = `GET /zones?per_page=1` (deliberately not `/user/tokens/verify`, which rejects valid account-owned tokens); `testZone()` fetches the zone by id and verifies its name matches the local zone; `discoverZoneConfig()` = `GET /zones?name={zone}`. Every record operation goes through `recordsPath()` which requires zone context. `ttl: 1` = auto (normalized to `null` locally). `proxied` only for A/AAAA/CNAME. MX priority top-level; SRV/CAA use `data` objects (serialized locally as `"weight port target"` / `flags tag "value"` strings). 429 retry with backoff. `external_id` = Cloudflare record id. **Adopt-on-conflict** (config `adopt_existing`, default on): create failures 81057/81053 trigger a same-name+type lookup; an exact-content match — or a single unambiguous record — is adopted via PUT (DB wins); ambiguous conflicts rethrow.

**Pi-hole v6** — **zoneless** (`supportsZones: false`, empty `zoneConfigSchema`): one instance serves every attached zone alike, and `listRecords()` lists the whole instance without zone context. App-password session auth (`POST /api/auth` → `X-FTL-SID` header → `DELETE /api/auth` in `finally`; Pi-hole caps ~16 concurrent sessions). A/AAAA via `PUT/DELETE /api/config/dns/hosts/{"IP host"}`; CNAME via `cnameRecords/{"name,target[,ttl]"}`. No per-entry update: delete-old + put-new. `external_id` = the raw entry string (FQDN-based, so states from different zones never collide). `supportsTtl=false` so hosts entries never flag TTL drift. Optional `verify_tls=false` for self-signed certs. Identical existing entries are adopted (`adopt_existing`, default on; off = 400 already-exists becomes an error). **Push pacing**: every CNAME config write makes FTL restart its embedded resolver, taking the whole REST API down for a few seconds — so sessions are serialized per provider (cache lock `pihole-session:{provider_id}`) and the session following a CNAME write waits a 5 s cooldown (`pihole-restart-cooldown:{provider_id}`) before authenticating. Both cache keys are keyed by **provider id**, deliberately shared across all of that provider's zone attachments. Bulk CNAME pushes drain gradually instead of failing against a restarting FTL. Batch-replacing via `PATCH /api/config` was deliberately rejected: it overwrites the whole `cnameRecords` array and would wipe records users manage outside the app.

**Technitium DNS Server** — **zoned** (`supportsZones: true`) with an **empty** `zoneConfigSchema`: every record call carries `zone={DnsZone name}`, so the attachment stores nothing — `testZone()` verifies the zone exists on the server (`GET /api/zones/list?filterName={zone}`, exact case-insensitive match; failure: "Zone {name} does not exist on this Technitium server") and `discoverZoneConfig()` returns `[]` when it does, `null` otherwise. Credential config: `base_url`, `api_token` (permanent token, Bearer header), `verify_tls`, `adopt_existing`. `testConnection()` = `GET /api/zones/list` reporting the hosted-zone count. Records via `GET /api/zones/records/{add,get,update,delete}` with per-type params (`ipAddress`, `cname`, `nameServer`, `ptrName`, `exchange`+`preference`, `text`, SRV `priority`/`weight`/`port`/`target`, CAA `flags`/`tag`/`value`); errors arrive as `{"status":"error","errorMessage":...}` on HTTP 200 and become `ConnectorException`s. **No stable record ids**: `external_id` encodes the tuple as canonical JSON (`{"type","name",...identifying rData}`, domain-name values lowercased) produced identically by `createRecord()` and `listRecords()` so drift matches on string equality, and decoded by update/delete to address the old record. Updates use `records/update`'s old→new params in one call (`newIpAddress`, `newExchange`/`newPreference`, `newDomain` on rename, ...); a type change falls back to delete-old + add-new, and an out-of-band-deleted record throws `RecordNotFoundException` (sync job re-creates). Deletes tolerate already-missing records. **Adopt-on-conflict**: an "already exists" add error retries with `overwrite=true`, replacing the record set with the local entry (DB wins). TTL: Technitium always stores one; null (auto) entries are pushed with the connector default **3600**, and remote records at 3600 are reported as auto so they never flag TTL drift. `listRecords()` = `records/get?listZone=true`, skipping types the app doesn't model (SOA, DNSSEC types, FWD, APP, ...).

## Sync engine

- **Assignment**: the `dns_entry_zone_provider` (`EntrySyncState`) rows ARE the entry→attachment assignment. The entry form sends an explicit `zone_providers: [ids]` selection drawn from **the entry's zone's attachments** (default: all active attachments managing the type). `SyncService::syncEntry(entry, ?zoneProviderIds)`:
  - candidates = the zone's attachments filtered by `ZoneProvider::managesType()` (attachment enabled ∧ provider enabled ∧ type managed)
  - explicit ids → candidates ∩ ids; null (manual re-sync / API without selection) → existing assignment; brand-new entry → all candidates
  - deselected/incompatible attachments get remote deletes — but states whose attachment is **inactive** (attachment disabled OR provider disabled) are **paused, never purged**.
  - `entries.store` requires `dns_zone_id`; the zone is **immutable** on update (`DnsEntryRequest` ignores it). Submitted names are normalized to zone-relative form (a pasted FQDN still validates), and the `zone_providers` selection is validated to belong to the entry's zone.
- **Jobs** (queued, retries with backoff): `SyncEntryToProvider(entryId, zoneProviderId)` (no-ops when the attachment is inactive; create-or-update by state `external_id`; when the update targets a record deleted out-of-band the connector throws `RecordNotFoundException` and the job falls back to `createRecord` — Cloudflare signals this on HTTP 404/81044, Technitium on the "no such record" error envelope, Pi-hole's delete-then-put update is inherently tolerant), `DeleteEntryFromProvider(syncStateId)` (removes remote, deletes the state, deletes the entry row when the last `deleting` state clears), `CheckProviderDrift(providerId)` (see drift semantics below), `CheckProviderHealth(providerId)` (run the connector's `testConnection()`, refresh provider health only — no record comparison; unchanged by the zones model).
- **Drift semantics** (`CheckProviderDrift`): compares states via `RemoteRecord::matches()` and marks `drifted`/`synced`, refreshing provider health. **Zoned** connectors (`supportsZones`) loop the provider's **enabled attachments** — one zone-scoped `listRecords()` per attachment, a failing zone is isolated (logged per zone, others still checked) and health names the failing zones. **Zoneless** connectors (Pi-hole) do ONE instance-wide `listRecords()` matched against the states of every attached zone (FQDN-based external ids keep it unambiguous). States are compared with `entry.zone` eager-loaded — `RemoteRecord::matches()` compares against the FQDN.
- **Auto-attachment** (`ZoneAttachmentService`): zoneless providers attach automatically — on zone creation, every enabled zoneless provider is attached; on zoneless-provider creation, it attaches to every existing zone. A **deleted attachment row is the opt-out**: nothing auto-recreates a detached pair (the only triggers are those two creation events). Detaching never deletes remote records.
- **Scheduler** (`routes/console.php`): schedules `php artisan dns:check-drift` (queues a `CheckProviderDrift` per enabled provider; cron from `DRIFT_CHECK_CRON`, default every 15 min) and `php artisan dns:check-provider-health` (queues a `CheckProviderHealth` per enabled provider; cron from `PROVIDER_HEALTH_CHECK_CRON`, default every 5 min, toggled by `PROVIDER_HEALTH_CHECK_ENABLED`). `SCHEDULER_ENABLED=false` unregisters both; each is guarded by `withoutOverlapping()` + `onOneServer()`. Separately, `ACTIVITY_LOGS_RETENTION_DAYS=N` (≥1) registers a daily `dns:flush-activities --days=N --force` — deliberately independent of `SCHEDULER_ENABLED`, since retention has no webhook alternative and the variable itself is the opt-in.
- **External triggers**: `POST /api/hooks/drift-check` and `POST /api/hooks/provider-health-check` (routes/api.php — no session/CSRF) queue the same checks, optionally for one `provider_id`. Guarded by `AuthenticateTriggerToken` middleware comparing the bearer token to `HOOKS_TRIGGER_TOKEN`; 404 while unset, 401 on mismatch. Built for N8N/cron-style automation.
- **Import from provider** (`ProviderImportController`, behind `can:manageRecords,zone`, scoped to a zone attachment): `GET zones/{zone}/providers/{zoneProvider}/remote-records` returns the connector's live `listRecords()` annotated `new`/`exists`/`managed` against local data — remote names are **relativized** to the zone, records outside the zone or with unmanaged types are excluded and counted; `POST zones/{zone}/providers/{zoneProvider}/import` upserts selected records (match on zone+name+type+content, update ttl/priority/proxied, re-validated via `DnsEntryRules`) and links states to the source attachment only, pre-synced with the remote `external_id` — no push jobs, no propagation to other attachments.
- **Zone sync-all**: `POST zones/{zone}/sync` re-runs `syncEntry()` for every entry in the zone.
- **Bulk actions** (`DnsEntryBulkController`; authorization is per zone — ids in zones without a record-managing grant are silently dropped from the selection): `POST entries/bulk/sync` (re-push each), `POST entries/bulk/providers` (three `mode`s: `replace` = full reassignment via `syncEntry(entry, ids)`, `attach` = additive via `SyncService::attachEntry` (existing assignments untouched, no re-push), `detach` = subtractive via `SyncService::detachEntry` (queues remote deletes for the named attachments only, honoring the paused invariant); attach/detach require ≥1 id, and the submitted attachment ids are intersected with **each entry's own zone's attachments**, so cross-zone ids are silently dropped; the UI only offers this action for single-zone selections), `PATCH entries/bulk` (apply a `set` of type/content/ttl/comment per entry, re-validated with `DnsEntryRules` merged in — invalid or duplicate results are skipped and counted, priority cleared on type change, then re-synced), `DELETE entries/bulk`. Missing ids are dropped silently. Literal `bulk` routes are registered before the `{entry}` routes.
- Every job outcome lands in `sync_logs`, stamped with `dns_zone_id` where known (dashboard feed + zone Providers-tab feed).
- **Conflict policy**: the app DB wins — drift is flagged, and re-push ("Sync now") overwrites the remote.

## Activity log (audit trail)

`spatie/laravel-activitylog` v5 records user actions in `activity_log` (distinct from `sync_logs`, which tracks background jobs).

- **Model instrumentation**: `DnsEntry`, `DnsZone`, `Provider`, `User`, and `ZoneUser` (zone grants, log name `users`) use the `LogsActivity` trait with `logOnly(...)` + `logOnlyDirty()` + `dontLogEmptyChanges()`, log names `entries` / `zones` / `providers` / `users`. The `Provider` allowlist (`name`, `type`, `enabled`, `managed_record_types`) deliberately excludes `config` (secrets) and the health columns — background health/drift checks therefore write **no** activities. A credential change is logged by `ProviderController` as `updated connection settings` with only a `config_changed: true` property, never any value. `DnsEntry::beforeActivityLogged()` stamps `properties.dns_zone_id` + the zone name onto every entry activity, so zone-scoped queries survive entry deletion.
- **Custom events** via the `activity()` helper: `delete-requested` on entries when deletion is deferred to queued provider-cleanup jobs (attributes the requesting user; the trait's `deleted` event fires later, system-caused), `providers-changed` on bulk replace/attach/detach (property: the resulting assigned provider names), `provider-attached` / `attachment-updated` / `provider-detached` on zones (`ZoneProviderController` — provider name only, never attachment config values), `zone-access-granted` / `zone-access-updated` / `zone-access-revoked` on zone grants (`ZoneAccessController` — user name, zone name, roles), and `login` / `logout` in `Auth\OidcController` (log name `auth`).
- **Storage**: v5 stores trait diffs in the `attribute_changes` column and custom payloads in `properties`. Morph map aliases `entry` / `provider` / `zone` / `user` / `zone-grant` are registered in `AppServiceProvider`, keeping `subject_type` values short and stable for filters.
- **Viewer**: the shared filter/paginate/serialize pipeline is `App\Support\ActivityQuery`; `ActivityLogController` is a thin delegate serving the Inertia page (`/activity`, a Settings-group sidebar item; filters: subject type/id, zone, event, causer, log name, date range; paginated) and a JSON `data` endpoint consumed by the `ActivityLogDialog` component (the kebab-menu "Activity" popup on entry rows, provider cards, and zone headers; it takes a `dataUrl` prop so zone pages point it at the zone-scoped endpoint). `ZoneController::activity()` reuses the same pipeline pre-scoped via the `zone_id` filter — activities on the zone subject itself OR entry activities with the stamped `properties->dns_zone_id` (a JSON-path where clause portable across Postgres and sqlite). The global routes sit behind `can:view-global-activity` (Super Admin + Super Viewer, mirrored by the shared `auth.can.viewGlobalActivity` prop); the zone activity page + `data` endpoint sit behind `can:viewActivity,zone` (mirrored by `zoneCan.viewActivity`). Subject labels for deleted records are recovered from the last logged snapshot.
- **Retention**: kept forever by default. `ACTIVITY_LOGS_RETENTION_DAYS=N` (`config/dns.php`) schedules a daily `dns:flush-activities --days=N --force` (see Scheduler above). On-demand wipe: `php artisan dns:flush-activities [--days=N] [--force]` (`FlushActivities`; confirmation-gated without `--force`). `ACTIVITYLOG_ENABLED=false` disables logging.

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
- Coverage: connector behavior (both providers), sync targeting/deletion/drift semantics, zone attachment/auto-attachment/discovery, encryption at rest (`providers.config` AND `zone_providers.config`), secret non-exposure, OIDC flow, validation, page rendering with props, RBAC (`RbacTest`, `ZoneAccessTest`, `ZoneScopingTest`, `UsersPageTest` — the policy/gate matrix, listing scoping, grant guards).

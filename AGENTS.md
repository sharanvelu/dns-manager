# AGENTS.md — Guide for AI Agents

Canonical orientation file for any AI agent working in this repository.
Companion files: [ARCHITECTURE.md](ARCHITECTURE.md) (system design), [DESIGN.md](DESIGN.md) (UI/UX conventions), [docs/content/](docs/content/) (user-facing documentation).

> ## ⚠️ Documentation sync rule (read this first)
>
> **Every change to the application MUST be reflected in the documentation in the same change set.** These files are contractually in sync with the code:
>
> - `AGENTS.md`, `CLAUDE.md` — commands, conventions, gotchas
> - `ARCHITECTURE.md` — data model, connectors, sync engine, deployment
> - `DESIGN.md` — UI patterns, components, status colors
> - `docs/content/*.md` — user-facing docs (rendered at the in-app `/docs` endpoint AND built into the public docs site)
> - `VERSION` — bump on every release
>
> If you add a route, change a form, alter sync semantics, add a connector, or modify deployment — update the matching doc before finishing. Do not leave documentation drift behind.

## What this app is

DNS Manager: a homelab web app that manages DNS entries across multiple providers from one UI. Entries live under **DNS zones** with zone-relative names; providers (Cloudflare, Pi-hole v6, Technitium) are account credentials **attached to zones** (`zone_providers`), entries are pushed to selected attachments, and a scheduled drift check flags out-of-band changes. The local Postgres DB is the source of truth. See ARCHITECTURE.md "The zones model" first.

## Commands

```sh
composer run dev          # serve app + queue worker + vite (local dev)
./vendor/bin/pest         # test suite (sqlite :memory:, HTTP fully faked)
./vendor/bin/pint --dirty # PHP formatting (run before finishing)
npx tsc --noEmit          # TypeScript check
npm run build             # Vite production build
php artisan migrate       # run migrations (Postgres in dev/prod)
php artisan dns:check-drift [--provider=ID]   # queue drift checks (what the schedule runs)
php artisan dns:check-provider-health [--provider=ID]  # queue provider connectivity health checks
php artisan dns:flush-activities [--days=N] [--force]  # wipe the audit trail (also scheduled daily when ACTIVITY_LOGS_RETENTION_DAYS is set)
docker build -t dns-manager:dev .   # production image (web+worker+scheduler roles)
```

Local dev services: Postgres 16 and Redis 7 (queue). `.env` uses `DB_*`, `REDIS_*`; queue connection is `redis` (predis client).

## Repository layout

```
app/Connectors/          # provider integrations — see ARCHITECTURE.md
app/Jobs/                # SyncEntryToProvider, DeleteEntryFromProvider, CheckProviderDrift, CheckProviderHealth
app/Services/            # SyncService (attachment targeting + push/delete), ZoneAttachmentService (zoneless auto-attach), DnsEntryImporter (CSV)
app/Policies/            # DnsZonePolicy — every zone-scoped ability (see RBAC convention below)
app/Support/             # shared pipelines: EntryQuery + EntryPresenter (global entries page AND zone records page), ActivityQuery (settings + zone activity), ZonePermissions (zoneCan props), DnsEntryRules, DocsRepository, Gravatar
app/Http/Controllers/    # Dashboard, DnsEntry(+Bulk), Zone, ZoneProvider, Provider, ProviderImport, User, ZoneAccess (grants), ActivityLog (thin delegate to ActivityQuery), Auth\Oidc, Settings
resources/js/pages/      # Inertia React pages (dashboard, entries/, zones/{index,records,providers,activity,access}, providers/, users/{index,show}, auth/, settings/)
resources/js/components/entries/   # shared EntriesView + dialogs — serves /entries AND /zones/{id}/records via EntriesScope
resources/js/components/activity/  # shared ActivityTable — serves settings activity AND zone activity via baseUrl
resources/js/components/zones/     # zone dialogs (form/delete/attach/detach/attachment-config/import-records); zone-tabs.tsx header lives one level up
resources/js/components/users/     # GrantDialog — shared zone-access grant/edit dialog (user detail page AND zone Access tab); nav-group.tsx (collapsible sidebar group) lives one level up
resources/js/components/icons/     # custom SVG icon set (original artwork; provider marks are real brand logos — see DESIGN.md)
docs/content/            # user documentation source (markdown + frontmatter) — single source
docs-site/               # standalone Next.js docs site (latest-version docs; deployed via Vercel, not Docker)
k8s/                     # Kubernetes manifests; docker/ holds nginx/fpm/supervisor configs
routes/web.php           # all app routes; routes/auth.php OIDC; routes/api.php automation hooks
routes/console.php       # the schedule (Laravel 12 has no console Kernel — this file IS the scheduler)
```

## Conventions

- **PHP**: Laravel 12 conventions, typed signatures, Pint-formatted. Enums in `app/Enums`. Form Requests for validation. No comments that restate code.
- **TypeScript/React**: Inertia 2 + React 19, shadcn-style components in `resources/js/components/ui/` (do not hand-roll equivalents), Tailwind CSS 4 tokens (`text-muted-foreground`, `bg-card`, …), dark mode via `dark:` variants everywhere. Page-local components live in the page's folder; components promoted to serve multiple pages live under `components/` (`entries/`, `activity/`, `zones/`, `zone-tabs.tsx`, `stat-tile.tsx`, `config-fields.tsx`, `flash-toast.tsx`).
- **Scoped shared views**: `EntriesView` takes an `EntriesScope` (`{ baseUrl, zone? }`) and `ActivityTable` takes a `baseUrl` — every filter/sort/pagination request MUST target the scope's `baseUrl` (`/entries` vs `/zones/{id}/records`, `/activity` vs `/zones/{id}/activity`). Never hard-code `/entries` (or any listing path) inside these shared components.
- **Tests**: Pest 4. `tests/TestCase.php` applies `withoutVite()`, `Http::preventStrayRequests()` — every outbound HTTP call in a test must be faked or the test fails — and `Sleep::fake()`, so connector pacing (Pi-hole's post-CNAME restart cooldown) records sleeps instead of actually waiting. Factories exist for `User`, `Provider` (`->cloudflare()`, `->pihole()`, `->technitium()`), `DnsEntry` (`->cname()`, `->mx()`), `ZoneUser` (zone grants).
- **Secrets**: provider credentials live in `providers.config` and per-zone attachment settings in `zone_providers.config` — both with the `encrypted:array` cast. Never expose either raw in Inertia props; controllers blank secret fields against the connector's `configSchema()` / `zoneConfigSchema()` (blank on update = keep stored value).
- **RBAC** (two tiers — full matrix in ARCHITECTURE.md "Access control"): global roles `super-admin` / `super-viewer` / `user-admin` (`App\Enums\Role`, `users.roles` json — zero roles is valid) and per-zone roles `zone-admin` / `zone-dns-manager` / `zone-viewer` / `zone-provider-manager` (`App\Enums\ZoneRole`, granted via `zone_user` rows). **Split**: zone-scoped abilities live in `DnsZonePolicy` (view / manageRecords / manageAttachments / update / delete / viewActivity / viewAccess / manageAccess); global abilities are Gates in `AppServiceProvider` (`create-zones`, `manage-providers`, `view-providers`, `manage-users`, `view-users`, `view-global-activity`). `Gate::before` passes Super Admin everywhere; Super Viewer is read-only **by construction** — no mutating gate or policy ability ever returns true for them (deny-by-default, nothing to strip later). **Convention**: zone-scoped mutating routes carry policy middleware (`can:ability,zone`) or FormRequest authorization (`DnsEntryRequest` authorizes `manageRecords` against the payload/entry zone, never the URL); UI triggers are hidden via per-zone `zoneCan` page props (built by `App\Support\ZonePermissions`) and the shared `auth.can` props. Every zone-role check runs off the **memoized** `User::zoneRolesMap()` (one query per request) — never add lazy per-zone grant queries, and call `forgetZoneRolesMap()` after mutating grants in-request. Listing scoping goes through `accessibleZoneIds()` (null = unrestricted, applied in `EntryQuery`, zones/dashboard listings, and bulk selection). Factories: `User::factory()` defaults to Super Admin so tests exercise behavior; states `->superViewer()` / `->userAdmin()` / `->noRoles()` / `->withRoles(...)` test authorization (the old `->viewer()` state is gone); `ZoneUser::factory()` has `->admin()` / `->viewer()` / `->dnsManager()` / `->providerManager()`.
- **Audit trail** (spatie/laravel-activitylog v5, see ARCHITECTURE.md): `DnsEntry`, `DnsZone`, `Provider`, and `User` carry `LogsActivity` with `logOnly` allowlists. The `Provider` allowlist MUST NEVER include `config` (secrets) or the health columns (`health_status`/`health_message`/`last_checked_at`) — credentials never reach the log (a change is logged as `config_changed: true` only) and background health/drift checks must not write activities; the same rule applies to `zone_providers.config` in the attachment events. `ZoneUser` grants also log (alias `zone-grant`, plus explicit `zone-access-granted`/`zone-access-updated`/`zone-access-revoked` events from `ZoneAccessController`). Morph aliases `entry`/`provider`/`zone`/`user`/`zone-grant` live in `AppServiceProvider`. `DnsEntry::beforeActivityLogged()` stamps `properties.dns_zone_id` + zone name so zone-scoped activity survives entry deletion. Viewer pipeline is `App\Support\ActivityQuery`: the global page `/activity` (`ActivityLogController`) sits behind `can:view-global-activity` (Super Admin + Super Viewer; sidebar item in the Settings group), the zone activity page behind `can:viewActivity,zone`; the `ActivityLogDialog` takes a `dataUrl` so zone-scoped users fetch via `/zones/{id}/activity/data`, not the global endpoint.

## Key gotchas (learned the hard way — keep current)

- Provider `config` cast requires a valid `APP_KEY`; tests inherit it from `.env`.
- Postgres-only SQL (`ilike`) breaks the sqlite test DB — use portable `LOWER(x) LIKE ?`.
- Vite preloading is **disabled** (`Vite::usePreloadTagAttributes(false)` in `AppServiceProvider`): the preload `Link` header (~4KB+) overflowed default nginx `fastcgi_buffer_size` (4KB) causing 502s on full-page refreshes. `docker/nginx.conf` also raises the buffers. Do not re-enable preloading without checking both.
- Dev containers that run `config:cache` against a mounted volume poison `bootstrap/cache/config.php` with container paths for host tooling. Clear with `php artisan config:clear` if tests fail with `/var/www/html/...` path errors.
- Pages live in lowercase `resources/js/pages`; `config/inertia.php` is published to point `testing.page_paths` there. Without it, Inertia's default (`js/Pages`) passes on case-insensitive macOS but fails every `assertInertia` component check on Linux CI ("page component file does not exist").
- Behind TLS-terminating proxies, `FORCE_HTTPS` (→ `URL::forceScheme`) only fixes generator-built URLs; **pagination links derive from the request URL** and stayed http (mixed-content blocked) until `trustProxies(at: '*')` was added in `bootstrap/app.php`. Keep both.
- Pi-hole caps concurrent API sessions (~16): the connector must always logout (`DELETE /api/auth`) in a `finally`.
- Pi-hole restarts FTL's resolver after **every CNAME config write**, taking its REST API down for seconds — rapid bulk pushes failed on every entry after the first. The connector serializes sessions per provider (cache lock) and waits a 5 s cooldown after CNAME writes. Do not "optimize" this away, and do not batch via `PATCH /api/config`: that replaces the whole array and wipes CNAMEs managed outside the app.
- Pi-hole's session lock and restart-cooldown cache keys (`pihole-session:{id}`, `pihole-restart-cooldown:{id}`) are keyed by **provider id**, not attachment id — one Pi-hole instance serves many zone attachments, and the keys MUST stay shared across all of them or pacing breaks.
- Technitium has **no stable record ids**: `external_id` is a canonical-JSON tuple that MUST be produced byte-identically by `createRecord()` and `listRecords()` (drift matches on string equality — key order, lowercased domain values). Null-TTL entries push the connector default 3600 and remote 3600 reads back as auto; don't "fix" either side alone.
- **sqlite does not enforce varchar lengths — Postgres does.** A green suite proves nothing about column width: Technitium's tuple external ids (full TXT rData, ~2 KB) truncated on `varchar(255)` in production only. Columns holding provider-derived values must be `text`; when adding one, ask what the largest connector value could be.
- Disabled attachments/providers are **paused**, not removed — editing an entry must never queue remote deletes against an inactive attachment (attachment disabled OR its provider disabled).
- **The FQDN seam**: `dns_entries.name` is zone-relative (`@`, `www`) but connectors always speak FQDN via the `DnsEntry::fqdn` accessor, which reads `entry->zone`. Always eager-load `entry.zone` on drift/list/push paths, and never hand a relative name to a connector or a FQDN to the database.
- `zone_providers.config` is `encrypted:array` like `providers.config` — never expose it raw in Inertia props; serve it filtered/blanked through the connector's `zoneConfigSchema()` (see `ZoneController::publicZoneConfig()`), exactly like credential `publicConfig`.
- Route ordering matters twice: literal `zones/{zone}/providers/discover` must be registered **before** the `{zoneProvider}` routes, and literal `entries/bulk/*` before the `{entry}` routes — otherwise the literal segment binds as a model id. Nested zone-provider routes also need `scopeBindings()`.
- `EntriesView` and `ActivityTable` are scope-driven: every request they make must target the scope's `baseUrl`, never a hard-coded `/entries` or `/activity` (they also serve `/zones/{id}/records` and `/zones/{id}/activity`).
- **Stale gate names are deny-all, silently**: `can:` middleware with an ability that no Gate defines and no policy resolves denies *everyone* (403), including Super Admin only saved by `Gate::before`. When renaming a gate or policy ability, grep routes, `HandleInertiaRequests`, `ZonePermissions`, and tests — a typo won't error, it will just lock users out.
- **Escalation guard invariant** (`UserController::update`): a non-Super-Admin may never change whether a user holds `super-admin` — in either direction. Do not weaken this when touching role updates; it is what stops a User Admin from minting (or demoting) Super Admins. Companions: User Admins cannot touch their own account; the last Super Admin can be neither demoted nor deleted.
- **Bulk actions silently shrink the selection**: ids in zones where the actor lacks a record-managing grant (and ids that no longer exist) are dropped, not 403'd (`DnsEntryBulkController::selectedEntries()`). Note it pins to zone-admin/zone-dns-manager grants directly — `accessibleZoneIds()` would wrongly treat Super Viewer as unrestricted here.
- **The dashboard never 403s**: every persona can load `/dashboard`; it scopes its stats/zones/feed via `accessibleZoneIds()` and renders a no-access state off the `noAccess` prop instead of aborting. Don't add auth middleware or aborts to it.

## The documentation system (three consumers, one source)

`docs/content/*.md` is the single source. Frontmatter contract:

```yaml
---
title: DNS Entries        # nav + page title
nav_order: 3              # sidebar ordering (index.md = 1)
description: One-liner    # meta/description
---
```

Consumers:
1. **In-app endpoint** `GET /docs[/{slug}]` (public, Blade-rendered) — serves the docs for *the installed version*, with a banner linking to the latest-version site (`config('app.docs_site_url')`).
2. **`docs-site/`** (Next.js, static export) — public site for *the latest version*, with a banner telling users on older versions to open `/docs` on their own instance. Deployed on **Vercel** straight from the repo (root directory `docs-site`); there is deliberately no Dockerfile, nginx config, k8s manifest, or CI build for it.
3. Humans reading the repo.

When app behavior changes, update the relevant `docs/content` page — both consumers pick it up automatically (endpoint at runtime, site at next build).

## Adding a DNS provider connector

1. `app/Connectors/FooConnector.php` extending `AbstractDnsConnector` (see `PiholeConnector` for a session-based **zoneless** example, `CloudflareConnector` for a token-based **zoned** one). Decide `capabilities()->supportsZones` first:
   - **Zoned** (per-zone API scope): declare `zoneConfigSchema()` (e.g. a zone id), implement `testZone()` (validate the attachment, verify the remote zone name matches) and `discoverZoneConfig(string $zoneName): ?array` (look the zone up to pre-fill the attachment form), and route record operations through `requireZoneContext()`.
   - **Zoneless** (one instance serves all zones): keep the `AbstractDnsConnector` defaults (`zoneConfigSchema()` = `[]`, `testZone()` = `testConnection()`, `discoverZoneConfig()` = `null`) — the provider will auto-attach to zones via `ZoneAttachmentService`.
   - Either way, remember connectors receive/return **FQDNs** (`$entry->fqdn`), never relative names.
2. Add the enum case in `app/Enums/ProviderType.php` (+ `label()`).
3. Register in `ConnectorRegistry::$connectors` — nothing else needs registering; both config schemas, capabilities, and auto-attachment flow from the class via `descriptors()`.
4. Add a provider mark icon in `resources/js/components/icons/` and map it in `resources/js/components/entries/helpers.tsx` + `pages/providers/lib.ts`.
5. Pest tests with `Http::fake()` fixtures of real API payloads (include `testZone`/`discoverZoneConfig` cases for zoned connectors).
6. Update `ARCHITECTURE.md`, `docs/content/providers.md`, and this file's layout section if needed.

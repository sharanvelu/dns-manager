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

DNS Manager: a homelab web app that manages DNS entries across multiple providers from one UI. Entries are pushed to selected providers (Cloudflare, Pi-hole v6 in v1) and a scheduled drift check flags out-of-band changes. The local Postgres DB is the source of truth.

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
docker build -t dns-manager:dev .   # production image (web+worker+scheduler roles)
```

Local dev services: Postgres 16 and Redis 7 (queue). `.env` uses `DB_*`, `REDIS_*`; queue connection is `redis` (predis client).

## Repository layout

```
app/Connectors/          # provider integrations — see ARCHITECTURE.md
app/Jobs/                # SyncEntryToProvider, DeleteEntryFromProvider, CheckProviderDrift, CheckProviderHealth
app/Services/SyncService.php  # provider targeting + push/delete orchestration
app/Http/Controllers/    # Dashboard, DnsEntry, Provider, Auth\Oidc, Settings (incl. Settings\ActivityLogController → settings/activity page + JSON data endpoint)
resources/js/pages/      # Inertia React pages (dashboard, entries/, providers/, auth/, settings/)
resources/js/components/icons/  # custom SVG icon set (original artwork, currentColor)
docs/content/            # user documentation source (markdown + frontmatter) — single source
docs-site/               # standalone Next.js docs site (latest-version docs; deployed via Vercel, not Docker)
k8s/                     # Kubernetes manifests; docker/ holds nginx/fpm/supervisor configs
routes/web.php           # all app routes; routes/auth.php OIDC; routes/api.php automation hooks
routes/console.php       # the schedule (Laravel 12 has no console Kernel — this file IS the scheduler)
```

## Conventions

- **PHP**: Laravel 12 conventions, typed signatures, Pint-formatted. Enums in `app/Enums`. Form Requests for validation. No comments that restate code.
- **TypeScript/React**: Inertia 2 + React 19, shadcn-style components in `resources/js/components/ui/` (do not hand-roll equivalents), Tailwind CSS 4 tokens (`text-muted-foreground`, `bg-card`, …), dark mode via `dark:` variants everywhere. Page-local components live in the page's folder.
- **Tests**: Pest 4. `tests/TestCase.php` applies `withoutVite()`, `Http::preventStrayRequests()` — every outbound HTTP call in a test must be faked or the test fails — and `Sleep::fake()`, so connector pacing (Pi-hole's post-CNAME restart cooldown) records sleeps instead of actually waiting. Factories exist for `User`, `Provider` (`->cloudflare()`, `->pihole()`), `DnsEntry` (`->cname()`, `->mx()`).
- **Secrets**: provider credentials live in the `providers.config` column with the `encrypted:array` cast. Never expose them in Inertia props; the Providers controller blanks secret fields (blank on update = keep stored value).
- **RBAC**: mutation routes sit behind `can:manage-entries` / `can:manage-providers` / `can:manage-users` middleware (Gates in `AppServiceProvider`, roles in `App\Enums\Role`); the activity-log viewer sits behind `can:view-activity` (Super Admin only). New mutating routes MUST go inside the matching middleware group, and their UI triggers must be hidden via the shared `auth.can` prop. `User::factory()` defaults to Super Admin so tests exercise behavior; use `->viewer()` / `->withRoles(...)` to test authorization itself.
- **Audit trail** (spatie/laravel-activitylog v5, see ARCHITECTURE.md): `DnsEntry`, `Provider`, and `User` carry `LogsActivity` with `logOnly` allowlists. The `Provider` allowlist MUST NEVER include `config` (secrets) or the health columns (`health_status`/`health_message`/`last_checked_at`) — credentials never reach the log (a change is logged as `config_changed: true` only) and background health/drift checks must not write activities. Morph aliases `entry`/`provider`/`user` live in `AppServiceProvider`; viewer is `Settings\ActivityLogController` at `/settings/activity` behind `can:view-activity`.

## Key gotchas (learned the hard way — keep current)

- Provider `config` cast requires a valid `APP_KEY`; tests inherit it from `.env`.
- Postgres-only SQL (`ilike`) breaks the sqlite test DB — use portable `LOWER(x) LIKE ?`.
- Vite preloading is **disabled** (`Vite::usePreloadTagAttributes(false)` in `AppServiceProvider`): the preload `Link` header (~4KB+) overflowed default nginx `fastcgi_buffer_size` (4KB) causing 502s on full-page refreshes. `docker/nginx.conf` also raises the buffers. Do not re-enable preloading without checking both.
- Dev containers that run `config:cache` against a mounted volume poison `bootstrap/cache/config.php` with container paths for host tooling. Clear with `php artisan config:clear` if tests fail with `/var/www/html/...` path errors.
- Pages live in lowercase `resources/js/pages`; `config/inertia.php` is published to point `testing.page_paths` there. Without it, Inertia's default (`js/Pages`) passes on case-insensitive macOS but fails every `assertInertia` component check on Linux CI ("page component file does not exist").
- Behind TLS-terminating proxies, `FORCE_HTTPS` (→ `URL::forceScheme`) only fixes generator-built URLs; **pagination links derive from the request URL** and stayed http (mixed-content blocked) until `trustProxies(at: '*')` was added in `bootstrap/app.php`. Keep both.
- Pi-hole caps concurrent API sessions (~16): the connector must always logout (`DELETE /api/auth`) in a `finally`.
- Pi-hole restarts FTL's resolver after **every CNAME config write**, taking its REST API down for seconds — rapid bulk pushes failed on every entry after the first. The connector serializes sessions per provider (cache lock) and waits a 5 s cooldown after CNAME writes. Do not "optimize" this away, and do not batch via `PATCH /api/config`: that replaces the whole array and wipes CNAMEs managed outside the app.
- Disabled providers are **paused**, not removed — editing an entry must never queue remote deletes against a disabled provider.

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

1. `app/Connectors/FooConnector.php` extending `AbstractDnsConnector` (see `PiholeConnector` for a session-based example, `CloudflareConnector` for token-based).
2. Add the enum case in `app/Enums/ProviderType.php` (+ `label()`).
3. Register in `ConnectorRegistry::$connectors`.
4. Add a provider mark icon in `resources/js/components/icons/` and map it in `pages/entries/helpers.tsx` + `pages/providers/lib.ts`.
5. Pest tests with `Http::fake()` fixtures of real API payloads.
6. Update `ARCHITECTURE.md`, `docs/content/providers.md`, and this file's layout section if needed.

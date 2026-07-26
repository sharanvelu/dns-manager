---
title: Overview
nav_order: 1
description: What DNS Manager is and what it can do for your homelab DNS.
---

# Overview

DNS Manager is a self-hosted control plane for your DNS records. You organize records into **zones** (your domains), connect each **provider** once with its credentials, attach providers to the zones they should serve, and DNS Manager pushes every record to its targets. The app's database is the source of truth: changes are pushed automatically when you save, and a scheduled drift check flags anything that was changed or removed behind the app's back.

DNS Manager ships with connectors for **Cloudflare** (public DNS), **Pi-hole v6** (local DNS), and **Technitium DNS Server** (self-hosted authoritative DNS).

## Features

- **DNS zones** — records are grouped by domain, with zone-relative names (`@` for the apex, `www`, `*.app`) and per-zone pages showing sync health, attached providers, and recent activity. Pasted full hostnames are relativized automatically. See [DNS Zones](zones).
- **Providers as reusable credentials** — enter a provider's credentials once and *attach* it to any number of zones; one Cloudflare API token can serve all your domains. Zoneless providers like Pi-hole attach to every zone automatically, with per-zone opt-out. See [Providers](providers).
- **Single pane of glass** — manage records across multiple DNS providers from one UI. Each entry shows a per-provider status chip, so you always know where a record lives and whether it is in sync.
- **Per-entry provider targeting** — every entry carries an explicit list of its zone's provider attachments it syncs to. The default is sensible (all enabled attachments that manage the record type), and you can narrow it per entry — for example, keep `nas` on Pi-hole only. See [DNS Entries](dns-entries).
- **Automatic push on save** — creating or editing an entry queues a push to each targeted provider. Pushes run as background jobs with automatic retries and backoff, so a briefly unreachable provider does not lose your change.
- **Zone discovery** — attaching a Cloudflare provider to a zone looks up the Zone ID from the zone name automatically; no copying IDs out of the Cloudflare dashboard. See [DNS Zones](zones).
- **Drift detection** — the scheduler checks every enabled provider every 15 minutes, comparing remote records against the database. You can also trigger a check per provider at any time. The database wins: drifted records are flagged, and a re-push ("Sync now") overwrites the remote state.
- **Provider health monitoring** — each provider card shows a Healthy / Error / Not checked badge with the last error message and the time of the last check.
- **Encrypted credentials** — API tokens and passwords are stored encrypted at rest (AES-256 via Laravel's `APP_KEY`) and are never sent back to the browser.
- **OIDC single sign-on** — authentication is delegated to any spec-compliant OpenID Connect provider (Authentik, Keycloak, Auth0, ...). Users are auto-provisioned on first login, with Gravatar avatars and an initials fallback.
- **Zone-based access control** — global roles (Super Admin, Super Viewer, User Admin) plus per-zone grants: give each user exactly the zones they need with combinable zone roles (Zone Admin, DNS Manager, Viewer, Provider Manager). The first user becomes Super Admin; everyone else starts with no access until granted. See [Users & Access](users).
- **Dynamic provider forms** — each connector declares its own configuration schema, and the Providers UI renders the form from it, including a test-connection button that validates credentials before you save.
- **Adopt existing records** — create an entry that already exists at a provider and the app takes over managing it (configurable per provider). See [Providers](providers).
- **Import from provider** — browse an attached provider's live records from the zone's Providers tab and selectively pull them into the zone; existing entries are updated and linked, never duplicated, and records outside the zone are skipped. See [DNS Zones](zones).
- **Bulk actions** — select entries in the list and sync, retarget providers, edit shared fields (type, value, TTL, comment), or delete them in one go, with per-entry validation that skips anything that would become invalid or duplicated. See [DNS Entries](dns-entries).
- **CSV import** — bulk-create entries into a chosen zone from a CSV file with per-row validation, duplicate skipping, and a downloadable sample template. See [DNS Entries](dns-entries).
- **Live list refresh** — refresh the entries list in place (no page reload), or turn on auto-reload at a chosen interval to watch sync statuses settle.
- **Sync activity feed** — every push, delete, import, and drift check is recorded and shown on the dashboard and on each zone's Providers tab. See [Dashboard](dashboard).
- **Audit trail** — every change made by a user (zones, entries, providers, users, access grants, sign-ins) is logged with field-level old → new diffs, filterable per record and per zone; visibility follows the role system, down to per-zone activity tabs. Provider secrets are never logged. See [Activity Log](activity-log).
- **Dark mode** — the whole UI is designed for both light and dark themes.
- **Kubernetes-ready** — one container image runs the web, queue worker, and scheduler roles via command overrides, with ready-made manifests in the repo. See [Installation](installation).
- **Extensible connector architecture** — providers are pluggable connectors behind a small interface; the Technitium connector shipped this way, and an Unbound connector is planned.

## Supported providers

| Provider | Record types | Notes |
| --- | --- | --- |
| Cloudflare | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR | Attaches per zone (Zone ID auto-discovered); proxying available for A/AAAA/CNAME; TTL 60–86400 seconds or automatic (proxied records always use automatic TTL); MX/SRV priority supported. |
| Pi-hole v6 | A, AAAA, CNAME | Zoneless — attaches to all zones automatically, with per-zone opt-out. A/AAAA become local hosts entries (no TTL); CNAME records support an optional TTL. No proxying or priority. |
| Technitium DNS Server | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR | Attaches per zone, addressed by the zone's name — no attachment settings; the zone must already exist on the server. MX/SRV priority supported; TTL any value (empty = 3600, treated as automatic). No proxying. |

## Where to go next

- [Installation](installation) — get DNS Manager running with Docker or Kubernetes.
- [DNS Zones](zones) — create zones, attach providers, and import existing records.
- [Dashboard](dashboard) — understand the stat tiles, zone cards, health badges, and activity feed.
- [DNS Entries](dns-entries) — create, edit, sync, and delete records.
- [Providers](providers) — connect Cloudflare, Pi-hole, and Technitium, test connections, and check drift.

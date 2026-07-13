---
title: Overview
nav_order: 1
description: What DNS Manager is and what it can do for your homelab DNS.
---

# Overview

DNS Manager is a self-hosted control plane for your DNS records. Instead of editing records in the Cloudflare dashboard and again in Pi-hole's admin UI, you define each entry once and DNS Manager pushes it to every provider you target. The app's database is the source of truth: changes are pushed automatically when you save, and a scheduled drift check flags anything that was changed or removed behind the app's back.

Version 1.0.0 ships with connectors for **Cloudflare** (public DNS) and **Pi-hole v6** (local DNS).

## Features

- **Single pane of glass** — manage records across multiple DNS providers from one UI. Each entry shows a per-provider status chip, so you always know where a record lives and whether it is in sync.
- **Per-entry provider targeting** — every entry carries an explicit list of providers it syncs to. The default is sensible (all enabled providers that manage the record type), and you can narrow it per entry — for example, keep `nas.home.lan` on Pi-hole only. See [DNS Entries](dns-entries).
- **Automatic push on save** — creating or editing an entry queues a push to each targeted provider. Pushes run as background jobs with automatic retries and backoff, so a briefly unreachable provider does not lose your change.
- **Drift detection** — the scheduler checks every enabled provider every 15 minutes, comparing remote records against the database. You can also trigger a check per provider at any time. The database wins: drifted records are flagged, and a re-push ("Sync now") overwrites the remote state.
- **Provider health monitoring** — each provider card shows a Healthy / Error / Not checked badge with the last error message and the time of the last check.
- **Encrypted credentials** — API tokens and passwords are stored encrypted at rest (AES-256 via Laravel's `APP_KEY`) and are never sent back to the browser.
- **OIDC single sign-on** — authentication is delegated to any spec-compliant OpenID Connect provider (Authentik, Keycloak, Auth0, ...). Users are auto-provisioned on first login, with Gravatar avatars and an initials fallback.
- **Role-based access control** — assign predefined roles (Super Admin, DNS Manager, Providers Manager, Viewer) per user, combinable as a union of permissions. The first user becomes Super Admin. See [Users & Roles](users).
- **Dynamic provider forms** — each connector declares its own configuration schema, and the Providers UI renders the form from it, including a test-connection button that validates credentials before you save.
- **Adopt existing records** — create an entry that already exists at a provider and the app takes over managing it (configurable per provider). See [Providers](providers).
- **Import from provider** — browse a provider's live records and selectively pull them in; existing entries are updated and linked, never duplicated. See [Providers](providers).
- **Bulk actions** — select entries in the list and sync, retarget providers, edit shared fields (type, value, TTL, comment), or delete them in one go, with per-entry validation that skips anything that would become invalid or duplicated. See [DNS Entries](dns-entries).
- **CSV import** — bulk-create entries from a CSV file with per-row validation, duplicate skipping, and a downloadable sample template. See [DNS Entries](dns-entries).
- **Live list refresh** — refresh the entries list in place (no page reload), or turn on auto-reload at a chosen interval to watch sync statuses settle.
- **Activity log** — every push, delete, and drift check is recorded and shown in the dashboard's recent-activity feed.
- **Dark mode** — the whole UI is designed for both light and dark themes.
- **Kubernetes-ready** — one container image runs the web, queue worker, and scheduler roles via command overrides, with ready-made manifests in the repo. See [Installation](installation).
- **Extensible connector architecture** — providers are pluggable connectors behind a small interface; Technitium and Unbound connectors are planned.

## Supported providers

| Provider | Record types | Notes |
| --- | --- | --- |
| Cloudflare | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR | Proxying available for A/AAAA/CNAME; TTL 60–86400 seconds or automatic (proxied records always use automatic TTL); MX/SRV priority supported. |
| Pi-hole v6 | A, AAAA, CNAME | A/AAAA become local hosts entries (no TTL); CNAME records support an optional TTL. No proxying or priority. |

## Where to go next

- [Installation](installation) — get DNS Manager running with Docker or Kubernetes.
- [Dashboard](dashboard) — understand the stat tiles, health badges, and activity feed.
- [DNS Entries](dns-entries) — create, edit, sync, and delete records.
- [Providers](providers) — connect Cloudflare and Pi-hole, test connections, and check drift.

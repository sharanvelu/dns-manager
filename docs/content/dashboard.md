---
title: Dashboard
nav_order: 4
description: What the dashboard stat tiles, zone cards, provider health cards, and activity feed mean.
---

# Dashboard

The dashboard is the landing page after sign-in. It answers one question at a glance: is everything in sync, and if not, where do I look?

## What you see depends on your access

Super Admins and Super Viewers see the full dashboard described below. Users whose access comes from [zone grants](users#zone-roles) get a **scoped** dashboard: the stat tiles, zone cards, and recent-activity feed cover **only their zones**, and the provider cards section is omitted entirely (provider health is a global concern — the "Providers healthy" chip reads 0/0 for them).

A user with **no zone access at all** sees a "no access yet" placeholder instead — *"No access yet — ask an administrator to grant you access to a zone."* For User Admins the placeholder points at their actual job instead, with a **Go to Users** button.

## Stat tiles

| Tile | Exact meaning |
| --- | --- |
| **Total managed entries** | Number of DNS entries stored in the app across all zones you can access, regardless of sync state — including local-only entries that target no provider. |
| **Fully in sync** | Entries that are assigned to at least one provider attachment **and** whose every assigned attachment reports `synced`. A local-only entry (no providers) does not count as in sync. |
| **Drifted** | Entries with **at least one** attachment in the `drifted` state — the remote record differs from the entry, or no longer exists at the provider. An entry that is fine on Cloudflare but drifted on Pi-hole counts here. |
| **Errors** | Entries with at least one attachment in the `error` state — the last push or delete for that provider failed. |

Because an entry can be drifted on one provider and errored on another, the Drifted and Errors tiles can both count the same entry. Next to the section heading, a **Providers healthy** chip shows `X/Y`: providers whose most recent check (scheduled health check or drift check) succeeded, out of all configured providers.

## Zone cards

Each [zone](zones) you can access gets a card showing its record count, one small icon per attached provider type, and a status rollup: **All in sync** when every record is fully synced, plus *N drifted* and *N errors* counts when something is off. Click a card to open the zone's Records tab. Until you create your first zone, this section walks you there instead.

## Provider cards

Each configured provider gets a card showing:

- **Health badge**
  - **Healthy** — the last check completed successfully. The badge is refreshed by the scheduled connectivity health check (every 5 minutes by default) and by every drift check.
  - **Error** — the last check failed; hover the badge to read the exact error message (bad credentials, unreachable host, ...).
  - **Not checked** — the provider has never been checked. A check is queued automatically when you add a provider, so this state is normally brief.
- **Record counts** — how many records (across all zones) are assigned to this provider, how many are in sync, and drifted/errored counts when nonzero.
- **Disabled** — a muted badge when the provider is disabled. Disabled providers are paused: nothing is pushed to them and their records are protected from deletion.

The timestamp on each card is the time of the last check (health or drift).

## Recent activity

The feed shows the 15 most recent sync-log events. Every background job writes one:

| Action | Meaning |
| --- | --- |
| `push` | An entry was created or updated at a provider. |
| `delete` | An entry was removed from a provider. |
| `import` | Records were imported from a provider into a zone (result: how many were created and updated). |
| `drift-check` | A provider's records were compared against the database (result: how many records were checked and how many drifted). |
| `provider-health-check` | A provider's connectivity was tested with the connector's connection test (same as **Test connection** in the UI). |

Each event carries a success or error status and a message, plus the zone, provider, and entry involved.

This feed tracks background sync jobs, not user actions — for the audit trail of who changed what, see the [Activity Log](activity-log).

## When something is amber or red

- **Drifted entries** — someone or something changed records directly at the provider. The zone card tells you which zone; open its Records tab (or [DNS Entries](dns-entries)), hover the amber chip to see what drifted, and use **Sync now** to re-push the entry (the app's database wins and overwrites the remote record). If the remote change is the one you want, edit the entry to match instead. **Sync all** on the zone header stomps widespread drift in one go.
- **Errored entries** — hover the red chip on the entry row for the exact error, fix the cause, then **Sync now**. Failed jobs also retry automatically with backoff.
- **Unhealthy provider** — open [Providers](providers), edit the provider, use **Test connection** to diagnose, and run **Check drift** once it is fixed.

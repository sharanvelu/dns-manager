---
title: Dashboard
nav_order: 3
description: What the dashboard stat tiles, provider health cards, and activity feed mean.
---

# Dashboard

The dashboard is the landing page after sign-in. It answers one question at a glance: is everything in sync, and if not, where do I look?

## Stat tiles

| Tile | Exact meaning |
| --- | --- |
| **Total entries** | Number of DNS entries stored in the app, regardless of sync state — including local-only entries that target no provider. |
| **Fully in sync** | Entries that are assigned to at least one provider **and** whose every assigned provider reports `synced`. A local-only entry (no providers) does not count as in sync. |
| **Drifted** | Entries with **at least one** provider in the `drifted` state — the remote record differs from the entry, or no longer exists at the provider. An entry that is fine on Cloudflare but drifted on Pi-hole counts here. |
| **Errors** | Entries with at least one provider in the `error` state — the last push or delete for that provider failed. |
| **Providers healthy** | Shown as `X/Y`: providers whose most recent check (scheduled health check or drift check) succeeded, out of all configured providers. |

Because an entry can be drifted on one provider and errored on another, the Drifted and Errors tiles can both count the same entry.

## Provider cards

Each configured provider gets a card showing:

- **Health badge**
  - **Healthy** — the last check completed successfully. The badge is refreshed by the scheduled connectivity health check (every 5 minutes by default) and by every drift check.
  - **Error** — the last check failed; hover the badge to read the exact error message (bad credentials, unreachable host, ...).
  - **Not checked** — the provider has never been checked. A check is queued automatically when you add a provider, so this state is normally brief.
- **Record counts** — how many entries are assigned to this provider and how many of them are currently in sync.
- **Disabled** — a muted badge when the provider is disabled. Disabled providers are paused: nothing is pushed to them and their records are protected from deletion.

The timestamp on each card is the time of the last check (health or drift).

## Recent activity

The feed shows the 15 most recent sync-log events. Every background job writes one:

| Action | Meaning |
| --- | --- |
| `push` | An entry was created or updated at a provider. |
| `delete` | An entry was removed from a provider. |
| `drift-check` | A provider's records were compared against the database (result: how many records were checked and how many drifted). |
| `provider-health-check` | A provider's connectivity was tested with the connector's connection test (same as **Test connection** in the UI). |

Each event carries a success or error status and a message, plus the provider and entry involved.

## When something is amber or red

- **Drifted entries** — someone or something changed records directly at the provider. Open [DNS Entries](dns-entries), hover the amber chip to see what drifted, and use **Sync now** to re-push the entry (the app's database wins and overwrites the remote record). If the remote change is the one you want, edit the entry to match instead.
- **Errored entries** — hover the red chip on the [DNS Entries](dns-entries) page for the exact error, fix the cause, then **Sync now**. Failed jobs also retry automatically with backoff.
- **Unhealthy provider** — open [Providers](providers), edit the provider, use **Test connection** to diagnose, and run **Check drift** once it is fixed.

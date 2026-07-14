---
title: Providers
nav_order: 5
description: Connect Cloudflare and Pi-hole, test connections, check drift, and manage provider lifecycle.
---

# Providers

The Providers page shows one card per configured provider: its health badge (**Healthy**, **Error** with the message on hover, or **Not checked**), the record types it manages, how many records are assigned to it and how many are in sync, and when it was last checked. Card actions: **Edit**, **Check drift**, **Enable/Disable**, and **Delete** (the latter two behind the kebab menu). Super Admins also get an **Activity** item in the kebab menu that opens the provider's audit history from the [Activity Log](activity-log). Disabled providers render dimmed with a "Disabled" badge.

## Adding a provider

Click **Add provider** and pick a provider type (Cloudflare or Pi-hole in v1). The connection form below is generated from the connector's own schema, so each type asks only for what it needs. Give the provider a display name (max 100 characters, e.g. "Home Pi-hole"), fill in the connection fields, optionally trim the managed record types, then **Test connection** and save. A drift check is queued immediately after saving, which also sets the health badge.

You can add the same connector type more than once — for example one Cloudflare provider per zone.

### Cloudflare

| Field | Value |
| --- | --- |
| **API Token** | A token with **Zone.DNS Edit** and **Zone Read** permissions. |
| **Zone ID** | Found on the zone **Overview** page in the Cloudflare dashboard (right-hand column). |
| **Adopt existing records** | On by default — see [Adopting existing records](#adopting-existing-records). |

To create the token in the Cloudflare dashboard: profile icon → **My Profile** → **API Tokens** → **Create Token** → use the **Edit zone DNS** template. It grants `Zone.DNS: Edit`; add `Zone.Zone: Read` under permissions, and scope the token to the specific zone under "Zone Resources". Copy the token immediately — Cloudflare shows it only once.

### Pi-hole (v6)

| Field | Value |
| --- | --- |
| **Base URL** | The Pi-hole address, e.g. `https://pihole.local` — no trailing slash. |
| **App password** | Generate one in Pi-hole under **Settings → Web interface/API → app password**. This is separate from your admin login password. |
| **Verify TLS certificate** | On by default. Turn it off when your Pi-hole serves a self-signed certificate. |
| **Adopt existing records** | On by default — see [Adopting existing records](#adopting-existing-records). |

Requires Pi-hole v6 (the REST API generation). The app authenticates per operation and releases its session immediately afterwards, so it stays well under Pi-hole's concurrent-session cap.

## Adopting existing records

What happens when you create an entry that **already exists at the provider** is controlled per provider by the **Adopt existing records** toggle:

- **On (default)** — the app adopts the existing remote record and manages it from then on. The remote record is aligned to your entry (TTL, proxy status, and — for an existing CNAME with a different target — the content too): the app's database wins, consistently with drift handling. This is the way to start managing records that predate DNS Manager: create the same entry here and it takes over.
- **Off** — creating an entry that already exists at the provider fails, and the entry's status chip shows an error explaining the conflict. Use this if you want the app to only ever touch records it created itself.

Adoption applies only to unambiguous matches (same name and type). If Cloudflare holds several records on the same name (round-robin A records) and none matches your entry's content exactly, the conflict is reported as an error instead of guessing.

## Importing records from a provider

The **Import** action on a provider card (requires the DNS Manager role) pulls the provider's live records into DNS Manager — the bulk counterpart to adopt-on-conflict:

1. The dialog lists every remote record whose type this provider manages, each marked **New** (no matching local entry), **Will update** (a matching entry exists and will be updated and linked), or **Managed** (already linked to this provider — shown for completeness, not selectable).
2. Tick the records you want — everything not already managed is preselected — and press **Import**.
3. Selected records are **upserted**: new entries are created, matching entries (same name, type, and content) are updated in place, and each is linked to this provider as already-synced. Nothing is ever duplicated and existing records never cause errors.

Imported entries are assigned to **this provider only** — they are not pushed anywhere else. To sync an imported entry to additional providers, edit it and tick them under provider selection. Records with types outside the provider's managed list are hidden (the dialog shows how many).

## Managed record types

Each connector supports a fixed set of record types (see the matrix below), and every provider instance can narrow that further with the **Managed record types** checkboxes — at least one must stay selected. Only the selected types are synced to this provider; entries of other types simply never target it. Use this, for example, to let a Cloudflare provider manage only TXT records while another system owns the rest of the zone.

If you remove a type that existing entries already use on this provider, those records are deleted from the provider the next time each affected entry is saved or re-synced.

## Test connection

**Test connection** in the add/edit dialog runs against the values currently in the form, before anything is saved (when editing, blank secret fields fall back to the stored values):

- **Cloudflare** — looks up the configured zone, which validates the token and the Zone ID in one call; on success it reports the zone name and status. Both user-owned and account-owned API tokens work (the app deliberately avoids Cloudflare's `/user/tokens/verify` endpoint, which rejects account-owned tokens).
- **Pi-hole** — authenticates with the app password and reads the Pi-hole version; on success it reports the connected version.

The result renders inline in the dialog, including the provider's error message on failure.

The same connection test also runs on a schedule (every 5 minutes by default, configurable via `PROVIDER_HEALTH_CHECK_CRON`, disabled with `PROVIDER_HEALTH_CHECK_ENABLED=false`) against every enabled provider and keeps the health badge current between drift checks. It can also be triggered externally via `POST /api/hooks/provider-health-check` — see [Installation](installation) for the webhook setup.

## Check drift

**Check drift** queues an immediate drift check for the provider, the same one the scheduler runs every 15 minutes: the app lists all records at the provider, compares each assigned entry against its remote counterpart, and marks entries `synced` or `drifted`. It only compares fields the provider actually supports (for example, TTL is never compared for Pi-hole hosts entries). A successful check sets the health badge to Healthy; a failed one sets it to Error with the message. Drift checks only run for enabled providers.

## Enable / Disable

Disabling a provider **pauses** it:

- Nothing is pushed to it — it disappears from the provider checkboxes in the entry form, and scheduled drift and health checks skip it.
- Its records are **protected from deletion**: existing entry-to-provider assignments are kept, and saving or deleting entries never triggers remote deletes against a disabled provider.

Re-enable the provider and use **Sync now** on affected entries (or wait for edits) to bring it back up to date.

## Editing a provider

Edit changes the name, connection settings, managed record types, and enabled flag; the provider *type* cannot be changed after creation. Secret fields show a masked placeholder — **leave a secret blank to keep the stored value**; only type a new one to replace it. Saving queues a fresh drift check.

## Deleting a provider

Deleting a provider removes it, its sync states, and its history from the app — but **records at the provider are left untouched**; the app just stops managing them. The confirmation dialog states exactly that. If you want the remote records gone, delete the entries (or deselect this provider on them) *before* deleting the provider.

## How credentials are stored

Provider configuration (API tokens, app passwords, URLs) is encrypted at rest in the database with AES-256 using your `APP_KEY`. Secrets are never included in pages sent to the browser — edit forms receive blank secret fields. Consequence: if you lose `APP_KEY`, stored credentials cannot be decrypted and must be re-entered. See [Installation](installation).

## Provider capability matrix

| Capability | Cloudflare | Pi-hole v6 |
| --- | --- | --- |
| Record types | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR | A, AAAA, CNAME |
| Proxied | A, AAAA, and CNAME records only | Not supported |
| TTL | 60–86400 seconds, or automatic when empty; proxied records always use automatic | Hosts entries (A/AAAA): none; CNAME: optional TTL. TTL is excluded from drift comparison |
| Priority | MX and SRV records | Not supported |

Fields a provider does not support are simply not sent to it and are never counted as drift there. See [DNS Entries](dns-entries) for how these fields appear in the entry form.

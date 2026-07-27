---
title: Providers
nav_order: 6
description: Connect Cloudflare, Pi-hole, and Technitium once, attach them to zones, test connections, check drift, and manage provider lifecycle.
---

# Providers

A **provider is a set of credentials, entered once**. It holds what the app needs to talk to a DNS backend — a Cloudflare API token, a Pi-hole address and app password, a Technitium address and API token — plus provider-wide settings like the managed record types. A provider does nothing by itself: you **attach** it to [zones](zones), and the attachment carries the zone-specific settings. Records always sync through an attachment, never to a "bare" provider.

The connectors illustrate the model:

- **Cloudflare** — one API token typically has access to many Cloudflare zones. Create **one** Cloudflare provider with that token and attach it to each of your zones; the per-zone **Zone ID** lives on the attachment and is [auto-discovered](zones#zone-discovery-cloudflare) from the zone name when you attach. No more one-provider-per-zone.
- **Pi-hole** — Pi-hole has no zone concept; it answers for any name. A Pi-hole provider is **zoneless**: it attaches itself to **all zones automatically** (current and future), and you opt individual zones out when you don't want them served — see [Zoneless providers](zones#zoneless-providers-and-opting-out).
- **Technitium** — Technitium hosts real zones, but they are addressed by name, so the attachment needs **no settings at all**: attach the provider to a zone whose name matches a zone hosted on the server and records flow. Attaching (and the attachment's **Test** action) simply verifies the zone exists on the server.

The Providers page shows one card per provider: its health badge (**Healthy**, **Error** with the message on hover, or **Not checked**), when it was last checked, the record types it manages, and a **Zones** section — how many records are assigned to it and how many are in sync, plus a chip per attached zone (dimmed when the attachment is disabled; click to open the zone). Zoneless providers show an **All zones** badge, with opted-out zones struck through. Zone-supporting providers get an **Attach to zone** button for any zones they aren't attached to yet. Card actions live behind the kebab menu: **Edit**, **Check drift**, **Enable/Disable**, and **Delete** for Super Admins, plus **Activity** (the provider's audit history) for Super Admins and Super Viewers.

Who can do what ([role system](users)): the Providers page and its credentials are visible to **Super Admins** and **Super Viewers** only, and only **Super Admins** can add, edit, test, enable/disable, or delete a provider — Super Viewers get a read-only view (secrets stay hidden either way). A zone's provider **attachments** are managed separately on the zone's page, by that zone's **Zone Admins** and **Provider Managers** — see [Attaching a provider](zones#attaching-a-provider).

## Adding a provider

Click **Add provider** and pick a provider type (Cloudflare, Pi-hole, or Technitium). The connection form below is generated from the connector's own schema, so each type asks only for what it needs. Give the provider a display name (max 100 characters, e.g. "Home Pi-hole"), fill in the connection fields, optionally trim the managed record types, then **Test connection** and save.

After saving:

- A **Cloudflare** or **Technitium** provider sits idle until you [attach it to a zone](zones#attaching-a-provider).
- A **Pi-hole** provider attaches itself to every existing zone immediately.

### Cloudflare

| Field | Value |
| --- | --- |
| **API Token** | A token with **Zone.DNS Edit** and **Zone Read** permissions. |
| **Adopt existing records** | On by default — see [Adopting existing records](#adopting-existing-records). |

There is deliberately **no Zone ID field** here — the token is the credential; zone IDs live on the [zone attachments](zones#attaching-a-provider) and are discovered automatically from each zone's name.

To create the token in the Cloudflare dashboard: profile icon → **My Profile** → **API Tokens** → **Create Token** → use the **Edit zone DNS** template. It grants `Zone.DNS: Edit`; add `Zone.Zone: Read` under permissions, and scope the token to the zones you want DNS Manager to serve under "Zone Resources" (or all zones, if one token should cover everything). Copy the token immediately — Cloudflare shows it only once.

### Pi-hole (v6)

| Field | Value |
| --- | --- |
| **Base URL** | The Pi-hole address, e.g. `https://pihole.local` — no trailing slash. |
| **App password** | Generate one in Pi-hole under **Settings → Web interface/API → app password**. This is separate from your admin login password. |
| **Verify TLS certificate** | On by default. Turn it off when your Pi-hole serves a self-signed certificate. |
| **Adopt existing records** | On by default — see [Adopting existing records](#adopting-existing-records). |

Requires Pi-hole v6 (the REST API generation). The app authenticates per operation and releases its session immediately afterwards, so it stays well under Pi-hole's concurrent-session cap.

Pi-hole restarts its DNS resolver after every CNAME change, which takes its API offline for a few seconds. The app accounts for this: operations against a Pi-hole run one at a time, and after each CNAME change the next operation waits a few seconds for the resolver to come back. A bulk sync that touches many CNAME records therefore completes gradually — entries flip to **Synced** one by one rather than failing while Pi-hole restarts.

Because Pi-hole holds records for *all* your zones in one flat list, importing from it happens per zone, and records outside the zone you're importing into are skipped and counted — see [Importing records](zones#importing-records-from-a-provider).

### Technitium DNS Server

| Field | Value |
| --- | --- |
| **Base URL** | The Technitium web service address, e.g. `https://technitium.local:53443` — no trailing slash. |
| **API Token** | A **permanent** API token. Create one in the Technitium admin panel under **Administration → Sessions → Create Token** (or via the `user/createToken` API). Session tokens from a plain login expire — use a permanent token. |
| **Verify TLS certificate** | On by default. Turn it off when the server uses a self-signed certificate. |
| **Adopt existing records** | On by default — see [Adopting existing records](#adopting-existing-records). |

A Technitium attachment carries **no zone settings**: the app addresses the remote zone by the zone's own name, so a zone named `example.com` here syncs to the zone `example.com` on the server. The zone must already exist on the Technitium server (the app never creates zones); attaching verifies it does, and the attachment **Test** action reports "Zone … does not exist on this Technitium server" when it doesn't.

Two behaviors to know about:

- **No stable record identifiers** — like Pi-hole, Technitium identifies a record by its full value tuple rather than an id. The app tracks records the same way, so renames and edits are handled as old-value → new-value updates. Nothing to configure; it just explains why a record edited *at the server* shows up as drift rather than being followed.
- **TTL** — Technitium always stores a TTL. Entries with an empty (automatic) TTL are pushed with `3600`, and records at `3600` are treated as automatic in drift comparison — so automatic and an explicit `3600` count as the same TTL and never drift against each other. If you want a TTL that is compared exactly, set any explicit value other than 3600.

Attachment management lives on each zone's **Providers** tab and requires the **Zone Admin** or **Provider Manager** role on that zone — full details in [DNS Zones](zones#attaching-a-provider). In short, per attachment you can:

- **Attach / Detach** (or opt a zone in/out of a zoneless provider) — detaching never deletes records at the provider.
- **Edit config** — the zone-specific connector settings, e.g. override the discovered Cloudflare Zone ID.
- **Test** — validate the attachment (for Cloudflare: that the Zone ID belongs to the zone's domain; for Technitium: that the zone exists on the server).
- **Enable/Disable for the zone** — a per-zone pause, independent of the provider-wide [Enable/Disable](#enable--disable).
- **Import records** — pull the provider's live records into the zone (requires record management on the zone: **Zone Admin** or **DNS Manager**).

## Adopting existing records

What happens when you create an entry that **already exists at the provider** is controlled per provider by the **Adopt existing records** toggle:

- **On (default)** — the app adopts the existing remote record and manages it from then on. The remote record is aligned to your entry (TTL, proxy status, and — for an existing CNAME with a different target — the content too): the app's database wins, consistently with drift handling. This is the way to start managing records that predate DNS Manager: create the same entry here and it takes over. (To take over many records at once, use [Import records](zones#importing-records-from-a-provider) instead.)
- **Off** — creating an entry that already exists at the provider fails, and the entry's status chip shows an error explaining the conflict. Use this if you want the app to only ever touch records it created itself.

Adoption applies only to unambiguous matches (same name and type). If Cloudflare holds several records on the same name (round-robin A records) and none matches your entry's content exactly, the conflict is reported as an error instead of guessing.

## Managed record types

Each connector supports a fixed set of record types (see the matrix below), and every provider instance can narrow that further with the **Managed record types** checkboxes — at least one must stay selected. Only the selected types are synced to this provider; entries of other types simply never target it, in any zone. Use this, for example, to let a Cloudflare provider manage only TXT records while another system owns the rest of the zone.

If you remove a type that existing entries already use on this provider, those records are deleted from the provider the next time each affected entry is saved or re-synced.

## Test connection

**Test connection** in the add/edit dialog runs against the values currently in the form, before anything is saved (when editing, blank secret fields fall back to the stored values):

- **Cloudflare** — verifies the token by listing the zones it can access; on success it reports how many zones are accessible. Both user-owned and account-owned API tokens work (the app deliberately avoids Cloudflare's `/user/tokens/verify` endpoint, which rejects account-owned tokens). Whether a specific zone's ID is right is checked per attachment with the **Test** action on the zone page.
- **Pi-hole** — authenticates with the app password and reads the Pi-hole version; on success it reports the connected version.
- **Technitium** — verifies the API token by listing the zones the server hosts; on success it reports how many. Whether a specific zone exists is checked per attachment with the **Test** action on the zone page.

The result renders inline in the dialog, including the provider's error message on failure.

The same connection test also runs on a schedule (every 5 minutes by default, configurable via `PROVIDER_HEALTH_CHECK_CRON`, disabled with `PROVIDER_HEALTH_CHECK_ENABLED=false`) against every enabled provider and keeps the health badge current between drift checks. It can also be triggered externally via `POST /api/hooks/provider-health-check` — see [Installation](installation) for the webhook setup.

## Check drift

**Check drift** queues an immediate drift check for the provider, the same one the scheduler runs every 15 minutes: the app lists the records at the provider (per attached zone for Cloudflare and Technitium; one listing covering all attached zones for Pi-hole), compares each assigned entry against its remote counterpart, and marks it `synced` or `drifted` per attachment. It only compares fields the provider actually supports (for example, TTL is never compared for Pi-hole hosts entries). A successful check sets the health badge to Healthy; a failed one sets it to Error with the message — and one zone attachment failing doesn't block the check for the others. Drift checks only run for enabled providers.

## Enable / Disable

Disabling a provider **pauses it everywhere**:

- Nothing is pushed to it — it disappears from the provider checkboxes in the entry form for every zone, and scheduled drift and health checks skip it. Its zone attachment cards show a **Provider disabled** badge.
- Its records are **protected from deletion**: existing entry-to-attachment assignments are kept, and saving entries never triggers remote deletes against a disabled provider.

Re-enable the provider and use **Sync now** on affected entries (or **Sync all** on affected zones) to bring it back up to date. To pause a provider for just one zone, disable the attachment on the zone page instead.

## Editing a provider

Edit changes the name, connection settings, managed record types, and enabled flag; the provider *type* cannot be changed after creation. Secret fields show a masked placeholder — **leave a secret blank to keep the stored value**; only type a new one to replace it. Zone-specific settings are edited on each attachment, not here.

## Deleting a provider

Deleting a provider removes it, **detaches it from all of its zones**, and drops its sync states and history from the app — but **records at the provider are left untouched**; the app just stops managing them. The confirmation dialog states exactly that, including how many zones it will be detached from. If you want the remote records gone, delete the entries (or deselect this provider on them) *before* deleting the provider.

## How credentials are stored

Provider configuration (API tokens, app passwords, URLs) is encrypted at rest in the database with AES-256 using your `APP_KEY`. Secrets are never included in pages sent to the browser — edit forms receive blank secret fields. Consequence: if you lose `APP_KEY`, stored credentials cannot be decrypted and must be re-entered. See [Installation](installation).

## Provider capability matrix

| Capability | Cloudflare | Pi-hole v6 | Technitium |
| --- | --- | --- | --- |
| Zones | Per-zone attachment; Zone ID auto-discovered | Zoneless — auto-attaches to all zones, per-zone opt-out | Per-zone attachment; addressed by zone name, no attachment settings |
| Record types | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR | A, AAAA, CNAME | A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR |
| Proxied | A, AAAA, and CNAME records only | Not supported | Not supported |
| TTL | 60–86400 seconds, or automatic when empty; proxied records always use automatic | Hosts entries (A/AAAA): none; CNAME: optional TTL. TTL is excluded from drift comparison | Any value; empty (automatic) pushes 3600, and 3600 reads back as automatic |
| Priority | MX and SRV records | Not supported | MX and SRV records |

Fields a provider does not support are simply not sent to it and are never counted as drift there. See [DNS Entries](dns-entries) for how these fields appear in the entry form.

---
title: DNS Entries
nav_order: 5
description: Create, edit, sync, and delete DNS records across zones and understand per-provider sync statuses.
---

# DNS Entries

The DNS Entries page shows your records across **all zones you can access** in one table; each zone's **Records tab** shows the same table scoped to that zone (see [DNS Zones](zones)). Everything below applies to both, except where noted.

Permissions are **per zone** ([role system](users)): creating, editing, syncing, importing, and deleting records requires the **Zone Admin** or **DNS Manager** role on the entry's zone (Super Admins can do everything). Rows in zones where you hold neither role — for example as a zone Viewer, or everywhere as a Super Viewer — are **read-only**: no selection checkbox and no mutating row actions.

Columns: **Name** (zone-relative — hover for the full FQDN; an orange cloud icon marks proxied records), **Zone** (global page only; links to the zone's records), **Type**, **Content**, **TTL** (`Auto` when unset), **Providers** (one status chip per assigned provider attachment), and **Updated**. Every column except Providers is sortable — click a header to sort by it (server-side, across all pages), click again to flip the direction; the default is Name ascending, sorting combines with the active filters, and `Auto` TTLs always sort last. Row actions live behind the kebab menu at the end of each row: Edit, Sync now, and Delete when you can manage the entry's zone, and **Activity** — the entry's audit history from the [Activity Log](activity-log) — when you can view that zone's activity (Zone Admin or Viewer of the zone, plus Super Admins and Super Viewers). Rows in zones you can manage also get a **selection checkbox** for [bulk actions](#bulk-actions).

Above the table you can search (matches name and content, case-insensitive) and filter by zone (global page only), record type, provider, and sync status. Results are paginated 25 per page. Filters combine, and a "Clear filters" button appears when a filtered view is empty.

Entries must be unique on the combination of name, type, and content **within their zone**.

## Refreshing the list

The toolbar has a **refresh button** that reloads just the entries list — filters, scroll position, and any open dialog stay put; the page itself never reloads. Next to it, an **auto-reload dropdown** re-fetches the list on a schedule (every 5s, 15s, 30s, 1m, or 5m — or off). While auto-reload is on, a **countdown timer** with a progress ring shows exactly when the next refresh fires; pressing the refresh button restarts it. Auto-reload pauses while the browser tab is in the background, and your chosen interval is remembered per browser. This is handy for watching sync statuses settle after a bulk change.

## Creating an entry

Click **Add entry** — the button (like **Import CSV**) appears only when you can manage records in at least one zone, and is disabled until a [zone](zones) exists, since entries live in zones. The form starts with the zone and adapts to the record type you pick; the zone select offers only the zones you can manage:

| Field | Rules |
| --- | --- |
| **Zone** | Required, chosen first — it determines the FQDN and which providers are offered. On a zone's Records tab the zone is pre-set. **The zone cannot be changed when editing.** |
| **Name** | Required, **relative to the zone**: `@` for the apex, `www`, `*.app`, `a.b`; labels may start with an underscore for service and verification records (`_sip._tcp`, `_dmarc`). Pasting the full hostname works — it is relativized automatically. See [zone-relative names](zones#zone-relative-record-names). |
| **Type** | One of A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR. |
| **Content** | Required, max 2048 characters. The label and validation adapt to the type — see below. |
| **TTL** | Optional. 60–86400 seconds; leave empty for automatic. |
| **Priority** | Only shown for MX and SRV, where it is required. 0–65535, lower is preferred. |
| **Proxied** | Checkbox, shown only when at least one targeted provider supports proxying (Cloudflare). Routes traffic through the provider's proxy network. |
| **Comment** | Optional note, max 255 characters. |

Content per record type:

| Type | Content | Example |
| --- | --- | --- |
| A | IPv4 address (validated) | `192.168.1.10` |
| AAAA | IPv6 address (validated) | `2001:db8::1` |
| CNAME | Target hostname | `server.home.lan` |
| MX | Mail server hostname | `mail.example.com` |
| TXT | Text value (quoting for Cloudflare is handled for you) | `v=spf1 include:example.com ~all` |
| SRV | `weight port target` | `5 5060 sipserver.example.com` |
| NS | Target hostname | `ns1.example.com` |
| CAA | `flags tag "value"` | `0 issue "letsencrypt.org"` |
| PTR | Target hostname | `host.example.com` |

Saving creates the entry and immediately queues a push to every selected provider.

## Provider selection

At the bottom of the form, a **Sync to providers** panel lists the chosen zone's *enabled* provider [attachments](zones#attaching-a-provider) that manage the chosen record type, as checkboxes:

- **All compatible providers are checked by default** when creating an entry.
- **Changing the record type — or the zone — resets the selection** back to the default (all compatible attachments).
- **Unchecking every provider is allowed** — the entry is stored locally only. An amber warning tells you so: "No providers selected — the entry will only be stored locally" (and, when editing, "...and removed from providers it currently syncs to").
- If **no enabled attachment manages the chosen type at all**, the panel itself turns amber: "No enabled provider attached to this zone manages `TYPE` records — this entry will not sync anywhere." You can still save the entry.

Disabled attachments, disabled providers, and providers whose managed record types exclude the chosen type never appear in the list. Providers attached to *other* zones are never offered — attach the provider to this zone first.

## Editing an entry

Editing works like creating, with three differences:

- The **zone is fixed** — an entry cannot move between zones.
- The provider selection reflects the entry's **current assignment**, not the default.
- **Deselecting a provider deletes the record from that provider** when you save — the app pushes the change to the still-selected attachments and queues a remote delete on the deselected ones. Exception: attachments that are currently *paused* (attachment disabled for the zone, or provider disabled globally) are left untouched — their records stay in place and their assignment is kept until re-enabled.

Changing the record type in the edit form also resets the provider selection to all compatible attachments, so re-check it before saving.

## Sync statuses

Each provider chip on an entry row shows one of five states — sync state is tracked **per attachment**:

| Status | Meaning |
| --- | --- |
| **Pending** | A push has been queued but has not completed yet. If entries stay pending forever, your queue worker is not running — see [Installation](installation). |
| **Synced** | The provider's record matches the entry. |
| **Drifted** | The drift check found the remote record was changed or deleted outside the app. Hover the chip: the tooltip says whether the record differs or no longer exists at the provider. |
| **Error** | The last push or delete failed. Hover the chip for the provider's error message (e.g. an invalid token or an API rejection). |
| **Deleting** | A remote delete is queued or in flight for this provider. |

Drift and error chips show the detail in a tooltip on hover; the same events appear in the [Dashboard](dashboard) activity feed and on the zone's Providers tab.

## Sync now

**Sync now** (in the row's kebab menu) re-queues a push to the entry's currently assigned attachments. Use it to:

- Overwrite drift — the app's database is the source of truth, so re-pushing restores the record at the provider to what the entry says. If the record was deleted at the provider entirely, the push detects that and recreates it from scratch.

Re-syncing an entry that is assigned to no attachments (deliberately local-only) does nothing — it never falls back to pushing everywhere.
- Retry after fixing the cause of an error.

To re-push a whole zone at once, use **Sync all** on the [zone header](zones#sync-all).

## Bulk actions

Tick the checkbox on one or more rows (the header checkbox selects the whole page; selections accumulate across pages) and a **bulk actions bar** appears above the table showing the count, a **Clear** button, and four actions applied to every selected entry. Selection is limited to entries in zones you can manage — read-only rows have no checkbox, and the server drops any entry you cannot manage from a bulk request rather than failing it:

- **Sync now** — re-queues a push for each entry to its currently assigned attachments, exactly like the per-row action.
- **Providers** — replaces each entry's provider assignment with the selection you make in the dialog: records sync to the ticked attachments and are **removed from unticked ones**. Because targeting is defined per zone, this action requires the selection to stay **within a single zone** — with entries from several zones selected, the button is disabled with the hint "Select entries from a single zone to change providers." Attachments that don't manage an entry's record type are skipped for that entry (nothing is force-pushed somewhere incompatible). Ticking nothing turns the entries local-only — as always, paused attachments are left untouched, not purged.
- **Edit** — bulk-edits the **type**, **value**, **TTL**, and/or **comment** (works across zones). Tick only the fields you want to change; unticked fields keep each entry's current value. An empty TTL means automatic, and an empty comment clears it. Every entry is re-validated with the change merged in — entries that would become invalid (say, changing the type to A when the value isn't an IPv4 address) or that would duplicate another entry in their zone are **skipped, never half-applied**, and the result message reports how many were updated and how many skipped and why. Changing the type re-targets attachments automatically: attachments that don't manage the new type get a remote delete, and priority is cleared when the new type doesn't carry one. Successful edits push to each entry's assigned attachments.
- **Delete** — after a confirmation dialog, removes each entry from every provider it is assigned to and then from the list, with the same semantics as [single delete](#deleting-an-entry).

Entries deleted elsewhere while selected are silently dropped from the action rather than failing it.

## Importing entries from CSV

The **Import CSV** button in the toolbar opens a modal for bulk-creating entries. The import is **zone-scoped**: pick the target zone first (pre-set on a zone's Records tab).

- **Format**: a header row followed by data rows. Columns: `name, type, content, ttl, priority, proxied, comment` — only `name`, `type`, and `content` are required; the rest may be omitted or left empty. **Names are zone-relative** (`www`, `@` for the apex, `*.app`); full hostnames under the zone are relativized automatically. `proxied` accepts `true`/`false` (blank = false). Download the **sample file** from the modal to start from a working template.
- **Validation**: every row is validated with the same rules as the entry form. Invalid rows are rejected individually — the modal reports each one with its line number and reason, while valid rows still import.
- **Duplicates**: rows matching an existing entry in the zone (same name, type, and content) are skipped and counted in the result summary.
- **Provider targeting**: imported entries sync to every compatible enabled attachment of the zone (the default targeting). To narrow an imported entry to specific providers, edit it afterwards.
- **Limits**: up to 1000 rows per file, 1 MB max.

To pull existing records *from a provider* instead, use **Import records** on the zone's attachment card — see [DNS Zones](zones#importing-records-from-a-provider).

## Deleting an entry

**Delete** (kebab menu, with a confirmation dialog) removes the record from every provider it is assigned to first; the local entry row disappears once the last provider confirms the deletion. Until then the entry remains visible with its chips in the **Deleting** state. If the entry was never pushed anywhere (local-only), it is removed immediately. Records held by *paused* attachments (attachment disabled for the zone, or provider disabled globally) are **left in place at the provider** — deletes are never sent through a disabled provider, so those records become unmanaged once the entry is gone.

Note the asymmetry with [zone deletion](zones#deleting-a-zone): deleting an *entry* deletes its remote records; deleting a *zone* (or detaching a provider) never does.

## Pi-hole specifics worth knowing

- **CNAME targets must be resolvable by Pi-hole itself** — Pi-hole only answers local CNAMEs whose target it can already resolve (another local record, or an upstream-resolvable name). An unresolvable target results in a record that does not answer, even though it syncs fine.
- **A and AAAA records become hosts entries**, which have **no TTL** in Pi-hole. Whatever TTL you set locally is used by other providers; Pi-hole ignores it, and the drift check knows not to flag that as drift. CNAME records do carry your TTL to Pi-hole when set.
- Pi-hole has no in-place update, so edits are applied as delete-then-recreate on the Pi-hole side. This is normal and momentary.

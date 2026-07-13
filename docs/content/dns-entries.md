---
title: DNS Entries
nav_order: 4
description: Create, edit, sync, and delete DNS records and understand per-provider sync statuses.
---

# DNS Entries

The DNS Entries page is where you manage records. The table shows one row per entry with columns for **Name** (with an orange cloud icon when the record is proxied), **Type**, **Content**, **TTL** (`Auto` when unset), **Providers** (one status chip per assigned provider), and **Updated**. Row actions — Edit, Sync now, Delete — live behind the kebab menu at the end of each row.

Above the table you can search (matches name and content, case-insensitive) and filter by record type, provider, and sync status. Results are paginated 25 per page. Filters combine, and a "Clear filters" button appears when a filtered view is empty.

Entries must be unique on the combination of name, type, and content.

## Refreshing the list

The toolbar has a **refresh button** that reloads just the entries list — filters, scroll position, and any open dialog stay put; the page itself never reloads. Next to it, an **auto-reload dropdown** re-fetches the list on a schedule (every 5s, 15s, 30s, 1m, or 5m — or off). Auto-reload pauses while the browser tab is in the background, and your chosen interval is remembered per browser. This is handy for watching sync statuses settle after a bulk change.

## Creating an entry

Click **Add entry**. The form adapts to the record type you pick:

| Field | Rules |
| --- | --- |
| **Name** | Required. A valid domain name (e.g. `app.example.com`), max 253 characters. A leading `*.` wildcard is allowed (e.g. `*.lab.example.com`), and labels may start with an underscore for service and verification records (e.g. `_sip._tcp.example.com`, `_dmarc.example.com`). |
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

At the bottom of the form, a **Sync to providers** panel lists every *enabled* provider that manages the chosen record type, as checkboxes:

- **All compatible providers are checked by default** when creating an entry.
- **Changing the record type resets the selection** back to the default (all compatible providers for the new type).
- **Unchecking every provider is allowed** — the entry is stored locally only. An amber warning tells you so: "No providers selected — the entry will only be stored locally" (and, when editing, "...and removed from providers it currently syncs to").
- If **no enabled provider manages the chosen type at all**, the panel itself turns amber: "No enabled provider manages `TYPE` records — this entry will not sync anywhere." You can still save the entry.

Disabled providers, and providers whose managed record types exclude the chosen type, never appear in the list. See [Providers](providers) for how to change what a provider manages.

## Editing an entry

Editing works like creating, with two differences:

- The provider selection reflects the entry's **current assignment**, not the default.
- **Deselecting a provider deletes the record from that provider** when you save — the app pushes the change to the still-selected providers and queues a remote delete on the deselected ones. Exception: providers that are currently *disabled* are paused, not purged — their records are left in place and their assignment is kept until the provider is re-enabled.

Changing the record type in the edit form also resets the provider selection to all compatible providers, so re-check it before saving.

## Sync statuses

Each provider chip on an entry row shows one of five states:

| Status | Meaning |
| --- | --- |
| **Pending** | A push has been queued but has not completed yet. If entries stay pending forever, your queue worker is not running — see [Installation](installation). |
| **Synced** | The provider's record matches the entry. |
| **Drifted** | The drift check found the remote record was changed or deleted outside the app. Hover the chip: the tooltip says whether the record differs or no longer exists at the provider. |
| **Error** | The last push or delete failed. Hover the chip for the provider's error message (e.g. an invalid token or an API rejection). |
| **Deleting** | A remote delete is queued or in flight for this provider. |

Drift and error chips show the detail in a tooltip on hover; the same events appear in the [Dashboard](dashboard) activity feed.

## Sync now

**Sync now** (in the row's kebab menu) re-queues a push to the entry's currently assigned providers. Use it to:

- Overwrite drift — the app's database is the source of truth, so re-pushing restores the record at the provider to what the entry says.
- Retry after fixing the cause of an error.

## Importing entries from CSV

The **Import CSV** button in the toolbar opens a modal for bulk-creating entries.

- **Format**: a header row followed by data rows. Columns: `name, type, content, ttl, priority, proxied, comment` — only `name`, `type`, and `content` are required; the rest may be omitted or left empty. `proxied` accepts `true`/`false` (blank = false). Download the **sample file** from the modal to start from a working template.
- **Validation**: every row is validated with the same rules as the entry form. Invalid rows are rejected individually — the modal reports each one with its line number and reason, while valid rows still import.
- **Duplicates**: rows matching an existing entry (same name, type, and content) are skipped and counted in the result summary.
- **Provider targeting**: imported entries sync to every compatible enabled provider (the default targeting). To narrow an imported entry to specific providers, edit it afterwards.
- **Limits**: up to 1000 rows per file, 1 MB max.

## Deleting an entry

**Delete** (kebab menu, with a confirmation dialog) removes the record from every provider it is assigned to first; the local entry row disappears once the last provider confirms the deletion. Until then the entry remains visible with its chips in the **Deleting** state. If the entry was never pushed anywhere (local-only), it is removed immediately.

## Pi-hole specifics worth knowing

- **CNAME targets must be resolvable by Pi-hole itself** — Pi-hole only answers local CNAMEs whose target it can already resolve (another local record, or an upstream-resolvable name). An unresolvable target results in a record that does not answer, even though it syncs fine.
- **A and AAAA records become hosts entries**, which have **no TTL** in Pi-hole. Whatever TTL you set locally is used by other providers; Pi-hole ignores it, and the drift check knows not to flag that as drift. CNAME records do carry your TTL to Pi-hole when set.
- Pi-hole has no in-place update, so edits are applied as delete-then-recreate on the Pi-hole side. This is normal and momentary.

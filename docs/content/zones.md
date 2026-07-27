---
title: DNS Zones
nav_order: 3
description: Create zones, use zone-relative record names, attach providers, import records, manage per-zone access, and understand what deleting a zone does.
---

# DNS Zones

A **zone** is a domain — `example.com`, `home.lan` — and it is where your DNS records live. Every entry belongs to exactly one zone, record names are stored relative to it, and the zone decides which providers its records can sync to: you [attach providers](#attaching-a-provider) to the zone, and each entry targets a subset of those attachments.

The **Zones** page lists **the zones you have access to** — all of them for Super Admins and Super Viewers, otherwise only the zones you hold a [zone role](users#zone-roles) on. Each row shows the record count, a status rollup (**All in sync**, *N drifted*, *N errors*), and a small icon per attached provider (dimmed when the attachment is disabled). Click a zone to open its [Records tab](#the-zone-page). Row actions live behind the kebab menu: **Open** for everyone, **Activity** for Super Admins and Super Viewers, and **Edit**/**Delete** for Super Admins (Zone Admins edit their zone from its own page instead).

## Creating a zone

Click **Add zone** (creating zones is reserved for **Super Admins**) and enter the domain:

- **Domain** — a bare domain like `example.com` (lowercased, trailing dot trimmed, must be unique). **The domain cannot be changed after creation** — the edit dialog only lets you change the description. To rename a zone, create a new one and re-create or re-import its records.
- **Description** — an optional note (max 255 characters), shown on the zone list and header.

The create dialog also tells you up front what the new zone will sync to:

- If you have **zoneless providers** (Pi-hole), each is listed with a note that it *serves all zones and will be attached automatically; you can opt out later* — see [Zoneless providers](#zoneless-providers-and-opting-out).
- If you have **no providers at all**, an amber warning says the zone won't sync anywhere until a provider is attached. That's fine — records are stored locally until then.

## Zone-relative record names

Record names inside a zone are stored **relative to the zone**:

| You want | Name to enter | FQDN in zone `example.com` |
| --- | --- | --- |
| The zone apex | `@` | `example.com` |
| A host | `www` | `www.example.com` |
| A wildcard | `*.app` | `*.app.example.com` |
| A service/verification label | `_dmarc` | `_dmarc.example.com` |
| A deeper name | `a.b` | `a.b.example.com` |

Pasting a **full hostname is fine**: `www.example.com` entered in zone `example.com` is automatically converted to `www` (and `example.com` itself becomes `@`) before validation. Careful with names under a *different* domain though — `www.other.com` is not inside the zone, so it is treated as the relative name `www.other.com` (FQDN `www.other.com.example.com`). Everywhere a relative name is shown, hovering it reveals the full FQDN in a tooltip.

## The zone page

Selecting a zone opens its **Records** tab. Every zone page shares a header — the zone name and description, a **Sync all** button (for those who can manage the zone's records), and a kebab menu with **Edit zone** (Zone Admins), **Activity** (those with zone-activity access), and **Delete zone** (Super Admins only) — above the tab pills. You only see the tabs your roles allow:

- **Records** — a row of stat tiles (**Records**, **Fully in sync**, **Drifted**, **Errors**, with the same meanings as the [global dashboard](dashboard)) above the zone's entries, in exactly the same table as the global [DNS Entries](dns-entries) page but scoped to this zone (no Zone column, and creating or importing here is pinned to this zone). When something has drifted, the **Drifted** tile shows a **Sync** button (for users who can manage the zone's records): it re-queues a push for **only the drifted records**, each to only the attachment it drifted on — untouched providers of the same entry are not re-pushed. As always, the app's database wins.
- **Providers** — the zone's attached [providers](#attaching-a-provider) with their management controls, and the not-yet-attached providers under **Available**.
- **Activity** — the zone's audit trail, visible to the zone's **Zone Admins** and **Viewers** plus Super Admins and Super Viewers (a zone **DNS Manager** does *not* see it): changes to the zone itself (including provider attach/detach events) *plus* every entry change stamped with this zone — see [Activity Log](activity-log).
- **Access** — who has been granted roles on this zone; see [Zone access](#zone-access).

When the zone has no providers attached, the Providers tab shows an amber banner — *"No providers attached — records in this zone are only stored locally."* — with an **Attach a provider** shortcut.

## Zone access

The **Access** tab manages who can do what in this zone — the same grants as the zone-access card on each [user's detail page](users#the-users-section), viewed from the zone's side. Each grant is one user with a combination of [zone roles](users#zone-roles): **Zone Admin**, **DNS Manager**, **Viewer**, **Provider Manager**.

Who sees the tab:

- **Super Admins** and the zone's **Zone Admins** can view and manage the grants.
- **User Admins** can too — even on zones they otherwise cannot open (they land on the Access tab without the Records/Providers tabs).
- **Super Viewers** see it read-only, with a banner ("Read-only — you can see who has access to this zone but not change it").
- Other zone roles (DNS Manager, Viewer, Provider Manager) don't see the tab at all.

The tab lists each grant with the user's avatar, email, role badges, and when it was granted. **Grant access** opens a dialog to pick a user (only users without an existing grant on this zone are offered) and tick zone roles; a grant's kebab menu offers **Edit roles** and **Remove access**. Removing a grant takes away all of that user's access to this zone without touching their global roles. Super Admins are never listed — they always have full access to every zone.

**Zone Admin grants are special.** For actors whose only claim to the tab is their own Zone Admin grant (not Super Admin or User Admin):

- The **Zone Admin checkbox is disabled** in the grant dialog — they cannot mint new Zone Admins.
- Existing grants that include Zone Admin show a **lock icon** ("Managed by Super Admins or User Admins") instead of the kebab menu — they cannot edit or remove them.
- They can never change or remove **their own** grant.

Only Super Admins and User Admins can create, change, or revoke grants involving the Zone Admin role. Every grant change is audited — see [Activity Log](activity-log).

## Attaching a provider

Providers hold credentials and are configured **once** on the [Providers](providers) page (Super Admins); a zone uses a provider through an **attachment**, which carries only the zone-specific settings. Managing a zone's attachments requires the **Zone Admin** or **Provider Manager** role on that zone.

On the zone's **Providers** tab, attached providers appear as cards and every not-yet-attached enabled provider is listed under **Available** with an **Attach** button (there is also an **Attach provider** button, and an **Attach to zone** button on the provider's own card). The attach dialog reminds you: *the provider's credentials are reused — only zone-specific settings live on the attachment.*

### Zone discovery (Cloudflare)

For connectors with zone-specific settings, the dialog **auto-discovers** them the moment the provider/zone pair is chosen. For Cloudflare it looks up the **Zone ID** from the zone name via the Cloudflare API:

- On success: *"Matched Cloudflare zone example.com (023e105f…)"* and the Zone ID field is filled in.
- On failure: *"No Cloudflare zone matches example.com — check the credential's zone access."* — typically the API token is scoped to other zones.

Discovered values only fill **blank** fields — anything you typed always wins. Use **Discover again** to re-run the lookup, or simply type the Zone ID manually. If you submit with the field blank, the server tries discovery once more and refuses the attachment with an explicit message if it still can't find it.

### The attachment card

Each attachment card shows the provider's health badge, per-attachment record counts (*N records · N in sync*, plus drifted/error counts), and the zone-specific settings as chips (e.g. the Cloudflare zone ID). Badges call out special states: **All zones** (zoneless provider), **Paused** (attachment disabled for this zone), **Provider disabled** (the provider is disabled globally). Its action buttons (shown only to those whose roles allow each action) offer:

- **Test** — validates this specific attachment, with the result shown inline on the card. For Cloudflare it checks that the configured Zone ID actually belongs to this domain: success reports *"Connected to zone example.com"*; a mismatched ID reports *"Zone ID belongs to other.com — expected example.com"*.
- **Import records** — see [below](#importing-records-from-a-provider). Requires record management on the zone (**Zone Admin** or **DNS Manager** zone role).
- **Disable / Enable** — a per-zone pause. Disabled attachments receive no pushes and their remote records are never deleted; entries keep their assignment so re-enabling picks up where it left off. (Disabling the *provider* on the Providers page pauses it for every zone the same way.)
- **Edit config** — change the attachment's connector settings (e.g. override the Cloudflare zone ID). Credentials stay on the provider and are never edited here. Only shown for connectors that have zone settings.
- **Detach** (or **Opt out** for zoneless providers) — removes the attachment. The zone's records stop syncing to that provider, and **records already at the provider are NOT deleted** — the app just stops managing them.

## Zoneless providers and opting out

Some connectors have no zone concept of their own — **Pi-hole** answers for any name you give it. These providers are **attached to every zone automatically**: to all existing zones when the provider is created, and to every new zone at creation (the create-zone dialog spells this out).

The escape hatch is per zone: **Opt out** on the attachment card detaches it, and nothing re-attaches an opted-out pair automatically. Opted-out zones appear **struck through** on the provider's card on the [Providers](providers) page (hover: *"Opted out — re-attach from the zone's page."*). To opt back in, attach the provider again from the zone's **Available** list.

## Importing records from a provider

**Import records** (on the attachment card) pulls the provider's live records into the zone — the way to take over records that predate DNS Manager, or to seed a fresh install:

1. The dialog lists the remote records that fall inside this zone, each marked **New** (no matching entry), **Will update** (a matching entry exists and will be updated and linked), or **Managed** (already linked to this attachment — shown for completeness, not selectable). Remote names are shown zone-relative.
2. Records that don't belong are filtered out and only counted: records **outside the zone** are skipped (*"N records outside this zone were skipped"* — expected for Pi-hole, which holds records for many zones in one place), and records whose types the provider doesn't [manage](providers#managed-record-types) are hidden.
3. Tick what you want — everything not already managed is preselected — and press **Import**. Selected records are upserted: new entries are created, matching entries (same name, type, and content) are updated in place, and each is linked to **this attachment only**, already in sync — nothing is pushed anywhere else and nothing is duplicated. To sync an imported entry to more of the zone's providers, edit it afterwards.

The result (*"Imported N new and updated M existing entries from …"*) is also recorded as an **import** event in the sync activity feed.

## Sync all

**Sync all** in the zone header re-queues a push for **every record in the zone** to its currently assigned attachments — the bulk version of a per-entry **Sync now**. It appears for users who can manage the zone's records (**Zone Admin** or **DNS Manager**). Use it after re-enabling an attachment or to stomp widespread drift; the app's database wins.

For a lighter touch, the **Sync** button on the Records tab's **Drifted** stat tile re-pushes **only the drifted records**, each to only the provider attachment it drifted on (paused attachments keep waiting, as always).

## Deleting a zone

**Delete zone** removes the zone and all of its records **from DNS Manager only**. The confirmation dialog states the exact consequence: the zone's N records are removed from the app, and **records at the attached providers are NOT deleted** — the app just stops managing them, and no delete jobs are queued. This cannot be undone (though you can re-create the zone and [re-import](#importing-records-from-a-provider) the records, since they still exist at the providers).

If you want the remote records gone too, delete the entries first (entry deletion *does* remove records from providers — see [DNS Entries](dns-entries#deleting-an-entry)) and delete the zone afterwards.

Zone deletion is reserved for **Super Admins** — even a Zone Admin cannot delete their zone. The zone's audit history survives deletion — see [Activity Log](activity-log).

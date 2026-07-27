# DESIGN.md

UI/UX conventions for DNS Manager. Keep in sync with the code — see the sync rule in [AGENTS.md](AGENTS.md).

## Foundations

- **Component library**: shadcn-style primitives in `resources/js/components/ui/` (Button, Card, Dialog, Select, Checkbox, DropdownMenu, Tooltip, Badge, Sidebar, …). Use these; never hand-roll equivalents or add a component library dependency.
- **Styling**: Tailwind CSS 4 with theme tokens (`bg-card`, `text-muted-foreground`, `border`, `bg-muted/40`). Every screen must look correct in **light and dark** mode — accent colors always carry `dark:` variants.
- **Aesthetic**: restrained, Linear/Vercel-like. Generous whitespace, 1.5px-stroke iconography, small muted labels over large `tabular-nums` values.
- **Typography**: Instrument Sans (bunny.net). Monospace (`font-mono`) for DNS data: names, record content, IPs.

## Iconography (`resources/js/components/icons/`)

App icons are original SVG artwork (`SVGProps<SVGSVGElement>`, `currentColor`, rounded caps, `strokeWidth 1.5`, legible at 16px). **Exception — provider marks are the real brand logos** (user decision, 2026-07-26): identifying third-party services, rendered as filled paths in their official brand colors so they stay recognizable and dim correctly via `opacity-*`.

- `DnsLogo` — app mark (hexagon + resolving route-dots); also `public/favicon.svg`.
- Provider marks: `ProviderCloudflareMark` (official two-tone orange cloud, Simple Icons path split per segment), `ProviderPiholeMark` (official glyph, brand red `#96060C`, brighter tint in dark mode via `currentColor` + text-color classes), `ProviderTechnitiumMark` (brand-blue `#6699FF` maze traced from the official 48×48 logo), `ProviderGenericMark` (original server-node artwork — fallback and future connectors).
- `ZoneMark` — the zone glyph, used in zone cards, zone headers, and dashboard zone tiles.
- Status glyphs: `StatusSyncedIcon` / `StatusPendingIcon` / `StatusDriftedIcon` / `StatusErrorIcon` / `StatusDeletingIcon`.
- Empty states: `EmptyEntriesIllustration`, `EmptyProvidersIllustration`, `EmptyZonesIllustration`, `EmptyActivityIllustration` (160×120, low-opacity layered strokes).
- `RecordTypeBadge` — colored monospace chip per record type (fixed color map, light+dark).
- Generic UI icons come from `lucide-react` — do not redraw those.

## Status color language (use consistently everywhere)

| State | Color | Icon |
| --- | --- | --- |
| synced / healthy / success | emerald (`text-emerald-600 dark:text-emerald-400`) | StatusSyncedIcon |
| pending | muted + pulse | StatusPendingIcon |
| drifted / warnings | amber | StatusDriftedIcon |
| error / unhealthy | red | StatusErrorIcon |
| deleting / disabled | muted (strikethrough / `opacity-60`) | StatusDeletingIcon |

Color never appears alone — always paired with an icon or label (accessibility).

## Page patterns

- **Layout**: `AppLayout` with collapsible sidebar, breadcrumbs, user menu (Gravatar avatar → initials fallback via `use-initials`). The user menu holds exactly three things: the identity label, **Profile**, and **Log out**; `/profile` is a single page (name form + appearance preference) with no settings section or sub-nav around it. `<Head title>` on every page. Sidebar items use **prefix-based active matching** (`nav-main.tsx`: exact path or `path.startsWith(url + '/')`, query string stripped) so nested pages like `/zones/3/records` keep Zones active without cross-matching.
- **Sidebar is persona-driven** (`app-sidebar.tsx`, gated on `auth.can`; empty sections render nothing):

  | Item | Prop gate | SA | SV | UA | zone-granted user |
  | --- | --- | :-: | :-: | :-: | :-: |
  | Dashboard / Zones / DNS Entries | `hasZoneAccess` | ✓ | ✓ | — | ✓ |
  | Providers | `viewProviders` | ✓ | ✓ | — | — |
  | Settings → Users | `viewUsers` | ✓ | ✓ | ✓ | — |
  | Settings → Activity | `viewGlobalActivity` | ✓ | ✓ | — | — |

  The Settings group is a **collapsible `NavGroup`** (`nav-group.tsx`): `Collapsible` wrapping a `SidebarGroup`, the group label as `CollapsibleTrigger` with a `ChevronRight` that rotates 90° when open (`group-data-[state=open]/collapsible:rotate-90`), default open, same prefix-based active matching as `NavMain`. Reuse it for any future grouped nav section.
- **Dashboard**: stat tiles (accent only when the state warrants: green when fully in sync, amber/red only when count > 0), zone cards, provider health cards, recent-activity feed.
- **Zone header** (`components/zone-tabs.tsx`): shared by all zone pages — `ZoneMark` + mono zone name + muted description on the left; "Sync all" button (`manageRecords`) + kebab (Edit zone / Activity / Delete zone, each gated on its `zoneCan` key) on the right; below, pill-style tab nav **Records / Providers / Activity / Access** (active pill = `bg-primary text-primary-foreground`, matching by path prefix). There is no zone overview page — `/zones/{id}` redirects to the Records tab, which opens with the zone stat-tile row (Records / Fully in sync / Drifted / Errors) above the entries table. The Providers tab holds the attachment cards with **visible action buttons** (Test / Import records / Disable–Enable / Edit config / Detach-or-Opt-out, each gated on `zoneCan`) and the Available-providers attach list. **Tabs render only when reachable** — Records + Providers need `viewZone`, Activity `viewActivity`, Access `viewAccess`; a User Admin can land on Access without being able to see the zone itself, so never offer a tab that would 403.
- **Zone cards** (dashboard + zones index): `ZoneMark`, mono zone name linking to the zone, tabular-nums record count, attached-provider marks with tooltip labels. The status line renders **only when it says something**: emerald "All in sync" only when the zone has records and every one is synced; amber drifted count and red error count only when > 0; a zone with nothing to report shows no status line at all (never a filler "0 drifted").
- **Tables** (entries): semantic `<table>`, mono data cells, per-provider status chips with error detail in tooltips (a drifted chip's tooltip also lists each drifted field with mono `tracked:` / `actual:` lines from `driftDetails`), row actions behind a kebab `DropdownMenu`, Laravel paginator links as buttons. Management affordances are **per zone** (the `zoneCan` map prop): when the user can manage records in at least one visible zone, a leading checkbox column (header = select page) drives a bulk-actions bar above the table (`bg-primary/5` border tint; count + Clear left, Sync now / Providers / Edit / Delete right) — but rows in zones the user cannot manage are not selectable and hide their mutating row actions; selected rows tint `bg-primary/5`. The Providers bulk action is enabled only for single-zone selections (tooltip explains why otherwise); its dialog opens in **Replace** mode with a `ToggleGroup` to switch to **Attach**/**Detach** (those start unticked, require ≥1 tick, and adjust the description + submit-button verb).
- **Forms**: in `Dialog`s, driven by Inertia `useForm`; `InputError` under every field; type-aware progressive disclosure (priority only for MX/SRV, proxied only when a proxy-capable provider is targeted). Provider credential forms AND zone-attachment forms render dynamically from the connector's `configSchema` / `zoneConfigSchema` via the shared `ConfigFieldInput` — secrets masked with placeholder "•••••••• (unchanged — leave blank to keep)".
- **Attach-provider dialog discovery**: when the provider+zone pairing has zone config, discovery **auto-runs** (and re-runs on pairing change). Results render inline: green panel "Matched {connector} zone {name} (…)" on success, amber panel with an actionable message when not found; a "Discover again" button retries. Discovered values only fill **blank** fields — manual input always wins.
- **Destructive actions**: confirmation dialog stating the real consequence (e.g. "remote records will be deleted" for entry deletion, vs "Records at the provider will NOT be deleted — the app just stops managing them" for detach/opt-out, and "Records at attached providers will NOT be deleted" for zone deletion). Detaching an auto-attached zoneless provider is worded as **opting out** ("Opt {zone} out of {provider}?").
- **Read-only banner**: when a viewer persona opens a page they cannot mutate (Users index for Super Viewers, the zone Access tab without `manageAccess`), show a quiet notice under the page title instead of disabled controls: muted panel (`bg-muted/40 text-muted-foreground rounded-lg border p-3 text-xs`) with a `ShieldCheck` icon and one sentence ("Read-only — you can view … but not change …"). Mutating buttons/kebabs are then simply not rendered.
- **Grant dialog** (`components/users/grant-dialog.tsx`): the one shared UI for granting/editing zone access, entered from both the user detail page (fixed user, pick zone) and the zone Access tab (fixed zone, pick user). Roles are a checkbox list where each option renders its label plus its muted enum `description` — never bare role names. When the actor is a zone-admin (`disallowZoneAdmin`), the Zone Admin option renders disabled at `opacity-60` with a tooltip explaining only a Super Admin or User Admin can grant it; on the Access tab, existing zone-admin grants such an actor cannot touch show a lock-icon "Managed by Super Admins" chip in place of the row kebab.
- **Feedback**: flash success/error via a bottom-right auto-dismissing (4s) toast; amber inline panels for "this will sync nowhere" warnings; test-connection results render inline in the dialog (never a navigation).
- **Empty states**: illustration + one-line explanation + primary CTA. Distinguish "nothing exists yet" from "no results for these filters" (offer Clear filters).
- **Relative time**: tiny local `timeAgo`/`relativeTime` helpers — no date library.

## Shared components (`resources/js/components/`)

Page-local components live in the page's folder — **except** these, promoted because two or more pages render them. Reuse them; never fork a page-local copy.

- `entries/` — `EntriesView` and its dialogs/rows/filter bar. Takes an `EntriesScope` (`{ baseUrl, zone? }`): serves `/entries` (zone column + filter shown) and `/zones/{id}/records` (zone-locked: no zone column, create dialog pins the zone). All requests target `scope.baseUrl` — never hard-code `/entries`.
- `activity/activity-table.tsx` — `ActivityTable`, `baseUrl`-driven: serves `/activity` and `/zones/{id}/activity`.
- `zone-tabs.tsx` — the zone page header (see above).
- `zones/` — zone dialogs: `zone-form-dialog`, `zone-delete-dialog`, `attach-provider-dialog`, `detach-provider-dialog`, `attachment-config-dialog`, `import-records-dialog`.
- `users/grant-dialog.tsx` — `GrantDialog` (see "Grant dialog" above): user detail page + zone Access tab.
- `nav-group.tsx` — `NavGroup`, the collapsible sidebar group (currently Settings).
- `stat-tile.tsx` — `StatTile` (small muted label over large tabular-nums value; `neutral|green|amber|red` accents; optional `action` node rendered beside the value — e.g. the Drifted tile's "Sync" button on the zone Records tab, shown only when drifted > 0 and `manageRecords`) — dashboard + zone Records tab.
- `config-fields.tsx` — `ConfigFieldInput` + `defaultConfigFor`, schema-driven config inputs for provider credentials AND zone-attachment config.
- `flash-toast.tsx` — the bottom-right flash toast, rendered per page.

## Docs surfaces

- In-app `/docs`: minimal Blade layout consistent with the app (same font, muted palette), version banner pinned at top.
- `docs-site/` (Next.js): same visual language; landing page presents the feature set; persistent "latest version" banner.

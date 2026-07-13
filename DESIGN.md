# DESIGN.md

UI/UX conventions for DNS Manager. Keep in sync with the code — see the sync rule in [AGENTS.md](AGENTS.md).

## Foundations

- **Component library**: shadcn-style primitives in `resources/js/components/ui/` (Button, Card, Dialog, Select, Checkbox, DropdownMenu, Tooltip, Badge, Sidebar, …). Use these; never hand-roll equivalents or add a component library dependency.
- **Styling**: Tailwind CSS 4 with theme tokens (`bg-card`, `text-muted-foreground`, `border`, `bg-muted/40`). Every screen must look correct in **light and dark** mode — accent colors always carry `dark:` variants.
- **Aesthetic**: restrained, Linear/Vercel-like. Generous whitespace, 1.5px-stroke iconography, small muted labels over large `tabular-nums` values.
- **Typography**: Instrument Sans (bunny.net). Monospace (`font-mono`) for DNS data: names, record content, IPs.

## Iconography (`resources/js/components/icons/`)

Original SVG artwork only — never trademarked provider logos. All icons: `SVGProps<SVGSVGElement>`, `currentColor`, rounded caps, `strokeWidth 1.5`, legible at 16px.

- `DnsLogo` — app mark (hexagon + resolving route-dots); also `public/favicon.svg`.
- Provider marks: `ProviderCloudflareMark` (abstract cloud+edge), `ProviderPiholeMark` (shield/funnel), `ProviderGenericMark` (server node — fallback and future connectors).
- Status glyphs: `StatusSyncedIcon` / `StatusPendingIcon` / `StatusDriftedIcon` / `StatusErrorIcon` / `StatusDeletingIcon`.
- Empty states: `EmptyEntriesIllustration`, `EmptyProvidersIllustration`, `EmptyActivityIllustration` (160×120, low-opacity layered strokes).
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

- **Layout**: `AppLayout` with collapsible sidebar (Dashboard / DNS Entries / Providers), breadcrumbs, user menu (Gravatar avatar → initials fallback via `use-initials`). `<Head title>` on every page.
- **Dashboard**: stat tiles (accent only when the state warrants: green when fully in sync, amber/red only when count > 0), provider health cards, recent-activity feed.
- **Tables** (entries): semantic `<table>`, mono data cells, per-provider status chips with error detail in tooltips, row actions behind a kebab `DropdownMenu`, Laravel paginator links as buttons.
- **Forms**: in `Dialog`s, driven by Inertia `useForm`; `InputError` under every field; type-aware progressive disclosure (priority only for MX/SRV, proxied only when a proxy-capable provider is targeted). Provider config forms render dynamically from the connector's `configSchema` — secrets masked with placeholder "•••••••• (unchanged — leave blank to keep)".
- **Destructive actions**: confirmation dialog stating the real consequence (e.g. "records at the provider will NOT be deleted" vs "remote records will be deleted").
- **Feedback**: flash success/error via a bottom-right auto-dismissing (4s) toast; amber inline panels for "this will sync nowhere" warnings; test-connection results render inline in the dialog (never a navigation).
- **Empty states**: illustration + one-line explanation + primary CTA. Distinguish "nothing exists yet" from "no results for these filters" (offer Clear filters).
- **Relative time**: tiny local `timeAgo`/`relativeTime` helpers — no date library.

## Docs surfaces

- In-app `/docs`: minimal Blade layout consistent with the app (same font, muted palette), version banner pinned at top.
- `docs-site/` (Next.js): same visual language; landing page presents the feature set; persistent "latest version" banner.

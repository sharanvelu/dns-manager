/**
 * Shared stroke-icon set (24×24, lucide-style geometry) for the directive
 * contract (`::card{icon="..."}`), the grouped sidebar and the landing
 * page. One data source, two adapters: `iconHast()` (lib/markdown) and
 * `<DocIcon />` (components/DocIcon.tsx). Colors always come from
 * `currentColor` so hues stay palette-token driven.
 *
 * Names are the spec contract — do not rename:
 * installation, authentication, dashboard, zones, dns-entries, providers,
 * users, activity, cloudflare, pihole, technitium, sync, shield, globe,
 * terminal.
 */

export interface IconShape {
  tag: "path" | "circle" | "rect" | "line";
  attrs: Record<string, string>;
}

export const ICONS: Record<string, IconShape[]> = {
  installation: [
    { tag: "path", attrs: { d: "M12 15V3" } },
    { tag: "path", attrs: { d: "m7 10 5 5 5-5" } },
    { tag: "path", attrs: { d: "M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" } },
  ],
  authentication: [
    {
      tag: "path",
      attrs: {
        d: "M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z",
      },
    },
    { tag: "circle", attrs: { cx: "16.5", cy: "7.5", r: ".5", fill: "currentColor" } },
  ],
  dashboard: [
    { tag: "rect", attrs: { width: "7", height: "9", x: "3", y: "3", rx: "1" } },
    { tag: "rect", attrs: { width: "7", height: "5", x: "14", y: "3", rx: "1" } },
    { tag: "rect", attrs: { width: "7", height: "9", x: "14", y: "12", rx: "1" } },
    { tag: "rect", attrs: { width: "7", height: "5", x: "3", y: "16", rx: "1" } },
  ],
  zones: [
    { tag: "circle", attrs: { cx: "12", cy: "12", r: "10" } },
    { tag: "path", attrs: { d: "M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" } },
    { tag: "path", attrs: { d: "M2 12h20" } },
  ],
  "dns-entries": [
    { tag: "path", attrs: { d: "M3 6h.01" } },
    { tag: "path", attrs: { d: "M8 6h13" } },
    { tag: "path", attrs: { d: "M3 12h.01" } },
    { tag: "path", attrs: { d: "M8 12h13" } },
    { tag: "path", attrs: { d: "M3 18h.01" } },
    { tag: "path", attrs: { d: "M8 18h13" } },
  ],
  providers: [
    { tag: "path", attrs: { d: "M12 22v-5" } },
    { tag: "path", attrs: { d: "M9 8V2" } },
    { tag: "path", attrs: { d: "M15 8V2" } },
    { tag: "path", attrs: { d: "M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z" } },
  ],
  users: [
    { tag: "path", attrs: { d: "M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" } },
    { tag: "circle", attrs: { cx: "9", cy: "7", r: "4" } },
    { tag: "path", attrs: { d: "M22 21v-2a4 4 0 0 0-3-3.87" } },
    { tag: "path", attrs: { d: "M16 3.13a4 4 0 0 1 0 7.75" } },
  ],
  activity: [
    {
      tag: "path",
      attrs: {
        d: "M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2",
      },
    },
  ],
  /* Provider marks — stylized stroke glyphs, deliberately not the
     trademarked logos (those would need brand assets). */
  cloudflare: [
    { tag: "path", attrs: { d: "M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z" } },
  ],
  pihole: [
    { tag: "circle", attrs: { cx: "12", cy: "12", r: "10" } },
    { tag: "circle", attrs: { cx: "12", cy: "12", r: "6" } },
    { tag: "circle", attrs: { cx: "12", cy: "12", r: "2" } },
  ],
  technitium: [
    { tag: "rect", attrs: { width: "20", height: "8", x: "2", y: "2", rx: "2" } },
    { tag: "rect", attrs: { width: "20", height: "8", x: "2", y: "14", rx: "2" } },
    { tag: "path", attrs: { d: "M6 6h.01" } },
    { tag: "path", attrs: { d: "M6 18h.01" } },
  ],
  sync: [
    { tag: "path", attrs: { d: "M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8" } },
    { tag: "path", attrs: { d: "M21 3v5h-5" } },
    { tag: "path", attrs: { d: "M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16" } },
    { tag: "path", attrs: { d: "M8 16H3v5" } },
  ],
  shield: [
    {
      tag: "path",
      attrs: {
        d: "M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z",
      },
    },
  ],
  globe: [
    { tag: "circle", attrs: { cx: "12", cy: "12", r: "10" } },
    { tag: "path", attrs: { d: "M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" } },
    { tag: "path", attrs: { d: "M2 12h20" } },
  ],
  terminal: [
    { tag: "path", attrs: { d: "m4 17 6-6-6-6" } },
    { tag: "path", attrs: { d: "M12 19h8" } },
  ],
};

/**
 * Icon → palette hue token (fallback --docs-accent). Lets card grids and
 * the sidebar tint icons per topic while every literal stays in
 * app/palette.css.
 */
export const ICON_HUES: Record<string, string> = {
  installation: "--docs-group-installation",
  authentication: "--docs-group-authentication",
  dashboard: "--docs-group-dashboard",
  zones: "--docs-group-zones",
  "dns-entries": "--docs-group-dns-entries",
  providers: "--docs-group-providers",
  users: "--docs-group-users",
  activity: "--docs-group-activity",
  cloudflare: "--docs-group-providers",
  pihole: "--docs-group-providers",
  technitium: "--docs-group-providers",
  sync: "--docs-group-dns-entries",
  shield: "--docs-group-authentication",
  globe: "--docs-group-zones",
  terminal: "--docs-group-installation",
};

export function iconHue(name: string): string {
  return ICON_HUES[name] ?? "--docs-accent";
}

export function hasIcon(name: string): boolean {
  return Object.prototype.hasOwnProperty.call(ICONS, name);
}

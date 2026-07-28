import type { DocMeta } from "./docs";

/** Site-wide configuration — the only place hard-coded site facts live. */
export const siteConfig = {
  name: "DNS Manager",
  title: "DNS Manager Docs",
  description: "Documentation for DNS Manager, the homelab DNS control plane.",
  githubUrl: "https://github.com/sharanvelu/dns-manager",
} as const;

export interface NavGroup {
  title: string;
  items: DocMeta[];
}

/**
 * Sidebar grouping is a docs-site presentation concern; the shared
 * `docs/content` frontmatter contract (title / nav_order / description)
 * stays untouched. Slugs not listed here fall into a default group so new
 * pages never disappear from navigation.
 */
const SIDEBAR_GROUPS: Array<{ title: string; slugs: string[] }> = [
  { title: "Getting Started", slugs: ["index", "installation"] },
  { title: "Core Concepts", slugs: ["zones", "dashboard", "dns-entries", "providers"] },
  { title: "Administration", slugs: ["users", "activity-log"] },
];

const DEFAULT_GROUP_TITLE = "More";

export function groupNav(nav: DocMeta[]): NavGroup[] {
  const bySlug = new Map(nav.map((doc) => [doc.slug, doc]));
  const known = new Set(SIDEBAR_GROUPS.flatMap((group) => group.slugs));

  const groups: NavGroup[] = SIDEBAR_GROUPS.map((group) => ({
    title: group.title,
    items: group.slugs
      .map((slug) => bySlug.get(slug))
      .filter((doc): doc is DocMeta => doc !== undefined)
      .sort((a, b) => a.navOrder - b.navOrder),
  })).filter((group) => group.items.length > 0);

  const rest = nav.filter((doc) => !known.has(doc.slug));
  if (rest.length > 0) {
    groups.push({ title: DEFAULT_GROUP_TITLE, items: rest });
  }

  return groups;
}

/** Canonical href for a doc slug under the trailing-slash static export. */
export function docHref(slug: string): string {
  return slug === "index" ? "/" : `/${slug}/`;
}

/**
 * ─── THE DOCS PAGE REGISTRY ──────────────────────────────────────────────
 *
 * Single source of truth for every documentation page. Drives the grouped
 * sidebar, the docs home cards, prev/next pagination, generateMetadata and
 * the ⌘K search index (title / description / headings — no separate
 * search-index.json build).
 *
 * ADDING A PAGE = one entry here + one file at
 * `app/docs/<group>/<slug>/page.tsx` (group index pages use slug "" and
 * live at `app/docs/<group>/page.tsx`). Pages render through
 * `<DocArticle group slug>` (components/docs/DocArticle.tsx), which looks
 * its own metadata up here.
 *
 * `headings` lists the page's H2/H3 texts for search deep-linking — keep
 * it in sync with the page's actual `<H2>`/`<H3>` headings (anchor ids
 * are derived with the same slugger, lib/slug.ts).
 *
 * This module is imported by client components (search) — keep it free of
 * Node APIs.
 */

export interface DocPage {
  /** Group directory, e.g. "zones" — must exist in GROUPS below. */
  group: string;
  /** Page directory, e.g. "creating"; "" for the group's index page. */
  slug: string;
  title: string;
  description: string;
  /** Order within the group (index pages conventionally 0). */
  order: number;
  /** H2/H3 texts, searchable and deep-linked via slugified anchors. */
  headings: string[];
}

/**
 * Groups in canonical order. `hue` must be a `--docs-group-*` token from
 * app/palette.css; `icon` a lib/icons.ts name.
 */
export interface DocGroup {
  dir: string;
  label: string;
  icon: string;
  hue: string;
}

export const GROUPS: DocGroup[] = [
  { dir: "installation", label: "Installation", icon: "installation", hue: "--docs-group-installation" },
  { dir: "authentication", label: "Authentication", icon: "authentication", hue: "--docs-group-authentication" },
  { dir: "dashboard", label: "Dashboard", icon: "dashboard", hue: "--docs-group-dashboard" },
  { dir: "zones", label: "Zones", icon: "zones", hue: "--docs-group-zones" },
  { dir: "dns-entries", label: "DNS Entries", icon: "dns-entries", hue: "--docs-group-dns-entries" },
  { dir: "providers", label: "Providers", icon: "providers", hue: "--docs-group-providers" },
  { dir: "users", label: "Users", icon: "users", hue: "--docs-group-users" },
  { dir: "activity", label: "Activity", icon: "activity", hue: "--docs-group-activity" },
];

export const PAGES: DocPage[] = [
  // ── Installation ───────────────────────────────────────────────────
  {
    group: "installation",
    slug: "",
    title: "Installation",
    description:
      "What you are deploying, what it needs, and where to go next — Docker, Kubernetes, configuration, and upgrades.",
    order: 0,
    headings: ["Requirements", "Choose your path", "After installing"],
  },
  {
    group: "installation",
    slug: "docker",
    title: "Docker",
    description: "Run DNS Manager as a single self-contained Docker container.",
    order: 1,
    headings: [
      "Quick start",
      "Generate an application key",
      "Start the container",
      "Verify and sign in",
      "Roles inside the container",
      "Scaling out (advanced)",
      "Next",
    ],
  },
  {
    group: "installation",
    slug: "kubernetes",
    title: "Kubernetes",
    description: "Deploy DNS Manager with the ready-made manifests under k8s/ — one pod covers all roles.",
    order: 2,
    headings: [
      "The manifests",
      "Deploy",
      "Replace the placeholders",
      "Create the namespace",
      "Create the secret",
      "Apply everything",
      "Verify",
      "Optional: persistent storage",
      "Optional: Kubernetes-native scheduler",
      "Scaling out (advanced)",
    ],
  },
  {
    group: "installation",
    slug: "configuration",
    title: "Configuration",
    description:
      "The full environment-variable reference — app, database, Redis, OIDC, container roles, scheduler, automation hooks, and activity retention.",
    order: 3,
    headings: [
      "Application",
      "Database",
      "Redis & queue",
      "Authentication (OIDC)",
      "Container roles",
      "Scheduler",
      "External automation (webhooks)",
      "Activity retention",
      "Advanced & framework variables",
      "Application extras",
      "Database & Redis extras",
      "Queue behavior",
      "Cache & sessions",
      "Logging",
      "Maintenance, mail & unused drivers",
    ],
  },
  {
    group: "installation",
    slug: "upgrading",
    title: "Upgrading",
    description: "Pull the new image tag, restart, done — migrations run automatically at container start.",
    order: 4,
    headings: ["Pull the new image tag", "Restart the container", "That's it"],
  },

  // ── Authentication ─────────────────────────────────────────────────
  {
    group: "authentication",
    slug: "",
    title: "Authentication",
    description:
      "DNS Manager is OIDC-only — no local passwords, users auto-provisioned on first sign-in, sessions and sign-ins audited.",
    order: 0,
    headings: ["How sessions work", "Set it up"],
  },
  {
    group: "authentication",
    slug: "setup",
    title: "Provider setup",
    description: "Register an OIDC client with your identity provider and configure the OIDC_* environment variables.",
    order: 1,
    headings: [
      "What the app needs from your provider",
      "Environment variables",
      "Worked example",
      "Create the client at your identity provider",
      "Register the redirect URI",
      "Find your issuer URL",
      "Set the environment and restart",
      "Sign in",
      "Troubleshooting",
    ],
  },
  {
    group: "authentication",
    slug: "first-login",
    title: "First login",
    description: "How the first Super Admin is bootstrapped, how users are provisioned and matched, and what to do next.",
    order: 2,
    headings: ["Who becomes the first admin", "How accounts are matched", "What to do next"],
  },

  // ── Dashboard ──────────────────────────────────────────────────────
  {
    group: "dashboard",
    slug: "",
    title: "Dashboard",
    description: "Stat tiles, zone cards, provider health and the activity feed at a glance.",
    order: 0,
    headings: [],
  },

  // ── Zones ──────────────────────────────────────────────────────────
  {
    group: "zones",
    slug: "",
    title: "Overview",
    description:
      "What a zone is, how zone-relative names work, and a tour of the Zones page and the per-zone tabs.",
    order: 0,
    headings: ["The Zones page", "The zone page", "Next steps"],
  },
  {
    group: "zones",
    slug: "creating",
    title: "Creating zones",
    description:
      "Create a zone, edit its description, and understand what deleting a zone does — and does not — remove.",
    order: 1,
    headings: [
      "Create a zone",
      "Open the Zones page and click Add zone",
      "Enter the domain",
      "Add a description (optional)",
      "Review where the zone will sync",
      "Edit a zone",
      "Delete a zone",
    ],
  },
  {
    group: "zones",
    slug: "providers",
    title: "Attaching providers",
    description:
      "Attach providers to a zone, let Cloudflare zone discovery fill the Zone ID, manage attachments, opt out of zoneless providers, and import existing records.",
    order: 2,
    headings: [
      "Attach a provider",
      "Open the zone's Providers tab",
      "Click Attach",
      "Fill the zone-specific settings",
      "Zone discovery (Cloudflare)",
      "The attachment card",
      "Zoneless providers and opting out",
      "Import records from a provider",
      "Review the remote records",
      "Note what was filtered out",
      "Select and import",
    ],
  },
  {
    group: "zones",
    slug: "access",
    title: "Zone access",
    description:
      "The four combinable zone roles, granting access from the Access tab, and the limits on what Zone Admins can grant.",
    order: 3,
    headings: [
      "Zone roles",
      "Who sees the Access tab",
      "Grant access",
      "Open the zone's Access tab and click Grant access",
      "Tick the zone roles",
      "Save",
      "Zone Admin grants are special",
    ],
  },

  // ── DNS Entries ────────────────────────────────────────────────────
  {
    group: "dns-entries",
    slug: "",
    title: "Overview",
    description:
      "The anatomy of a DNS entry, zone-relative name rules, the supported record types, and a tour of the entries table.",
    order: 0,
    headings: ["Anatomy of an entry", "Zone-relative names", "Supported record types", "The entries table", "Next steps"],
  },
  {
    group: "dns-entries",
    slug: "managing",
    title: "Managing entries",
    description: "Create, edit, and delete DNS entries, target providers per entry, import from CSV, and use bulk actions.",
    order: 1,
    headings: [
      "Create an entry",
      "Click Add entry",
      "Pick the zone first",
      "Fill the form",
      "Choose the providers",
      "Save",
      "Per-entry provider targeting",
      "Edit an entry",
      "Bulk actions",
      "Import entries from CSV",
      "Deleting entries",
    ],
  },
  {
    group: "dns-entries",
    slug: "sync",
    title: "Sync & drift",
    description:
      "Automatic pushes, per-provider status chips, scheduled drift checks, and re-pushing with Sync now and Sync all.",
    order: 2,
    headings: ["Sync statuses", "The drift check", "Sync now", "Sync all and the Drifted tile", "Watching statuses settle"],
  },

  // ── Providers ──────────────────────────────────────────────────────
  {
    group: "providers",
    slug: "",
    title: "Providers",
    description:
      "Providers are reusable credentials — connect Cloudflare, Pi-hole, or Technitium once and attach them to any number of zones.",
    order: 0,
    headings: ["Zoned vs zoneless", "The provider card", "Provider health", "Who can do what", "Capability matrix"],
  },
  {
    group: "providers",
    slug: "cloudflare",
    title: "Cloudflare",
    description:
      "Connect Cloudflare with one API token, attach it to many zones with auto-discovered Zone IDs, and optionally proxy records.",
    order: 1,
    headings: [
      "Connection fields",
      "Creating the API token",
      "Open the API Tokens page",
      'Use the "Edit zone DNS" template',
      "Scope the token",
      "Copy the token immediately",
      "Zone discovery",
      "Record types and features",
      "Quirks worth knowing",
    ],
  },
  {
    group: "providers",
    slug: "pihole",
    title: "Pi-hole",
    description: "Connect Pi-hole v6 as a zoneless local-DNS provider that auto-attaches to every zone, with per-zone opt-out.",
    order: 2,
    headings: ["Connection fields", "Zoneless: auto-attach with opt-out", "Record types and TTL", "Behavior to know about"],
  },
  {
    group: "providers",
    slug: "technitium",
    title: "Technitium",
    description:
      "Connect Technitium DNS Server as a self-hosted authoritative provider — zones addressed by name, no attachment settings.",
    order: 3,
    headings: ["Connection fields", "Attachments carry no settings", "Record types and features", "Behavior to know about"],
  },
  {
    group: "providers",
    slug: "managing",
    title: "Managing providers",
    description:
      "Add, test, edit, disable, and delete providers — including what test connection checks, how secrets are edited, and what delete really does.",
    order: 4,
    headings: [
      "Adding a provider",
      "Open the Providers page and click Add provider",
      "Fill in the connection form",
      "Optionally trim the managed record types",
      "Test connection and save",
      "Test connection",
      "Check drift",
      "Managed record types",
      "Adopting existing records",
      "Enable / Disable",
      "Editing a provider",
      "Deleting a provider",
      "How credentials are stored",
    ],
  },

  // ── Users ──────────────────────────────────────────────────────────
  {
    group: "users",
    slug: "",
    title: "Users & Access",
    description:
      "The two-level access model — global roles plus per-zone grants — and how it shapes what each user sees.",
    order: 0,
    headings: ["Global roles", "Zone roles", "What each persona sees", "Next steps"],
  },
  {
    group: "users",
    slug: "managing",
    title: "Managing users",
    description: "How users are provisioned, editing global roles, granting zone access, and the safety rails.",
    order: 1,
    headings: [
      "How users are created",
      "The Users section",
      "Editing global roles",
      "Open the user",
      "Tick roles in the Global roles card",
      "Save roles",
      "Granting zone access",
      "Add zone access",
      "Tick zone roles",
      "Manage existing grants",
      "Safety rails",
      "Deleting a user",
    ],
  },

  // ── Activity ───────────────────────────────────────────────────────
  {
    group: "activity",
    slug: "",
    title: "Activity Log",
    description:
      "The audit trail — who changed what and when — where to view it, how to filter it, and how retention works.",
    order: 0,
    headings: [
      "What is recorded",
      "What is not recorded",
      "Where to see it",
      "The global viewer",
      "Per-zone activity",
      "Quick access from a record",
      "Retention & flushing",
    ],
  },
];

/* ── Derived helpers ─────────────────────────────────────────────────── */

/** Canonical URL of a page: /docs/<group>/[<slug>/]. */
export function pageUrl(page: Pick<DocPage, "group" | "slug">): string {
  return page.slug === ""
    ? `/docs/${page.group}/`
    : `/docs/${page.group}/${page.slug}/`;
}

export function groupConfig(dir: string): DocGroup {
  return (
    GROUPS.find((group) => group.dir === dir) ?? {
      dir,
      label: dir.charAt(0).toUpperCase() + dir.slice(1),
      icon: "globe",
      hue: "--docs-accent",
    }
  );
}

/** All pages in canonical reading order (group order, then page order). */
export function orderedPages(): DocPage[] {
  const groupIndex = (dir: string) => {
    const index = GROUPS.findIndex((group) => group.dir === dir);
    return index === -1 ? GROUPS.length : index;
  };
  return [...PAGES].sort(
    (a, b) =>
      groupIndex(a.group) - groupIndex(b.group) ||
      a.group.localeCompare(b.group) ||
      a.order - b.order ||
      a.title.localeCompare(b.title),
  );
}

export interface NavGroup extends DocGroup {
  items: DocPage[];
}

/** Groups with their pages, sidebar-ready (empty groups omitted). */
export function navGroups(): NavGroup[] {
  const groups: NavGroup[] = [];
  for (const page of orderedPages()) {
    let group = groups.find((candidate) => candidate.dir === page.group);
    if (!group) {
      group = { ...groupConfig(page.group), items: [] };
      groups.push(group);
    }
    group.items.push(page);
  }
  return groups;
}

export function findPage(group: string, slug = ""): DocPage | undefined {
  return PAGES.find((page) => page.group === group && page.slug === slug);
}

/** Previous/next page around the given page, in canonical order. */
export function adjacentPages(group: string, slug = ""): {
  prev: DocPage | undefined;
  next: DocPage | undefined;
} {
  const pages = orderedPages();
  const index = pages.findIndex((page) => page.group === group && page.slug === slug);
  if (index === -1) return { prev: undefined, next: undefined };
  return {
    prev: index > 0 ? pages[index - 1] : undefined,
    next: index < pages.length - 1 ? pages[index + 1] : undefined,
  };
}

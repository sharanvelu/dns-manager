/**
 * Site-wide configuration — the only place hard-coded site facts live.
 * (Docs page structure lives in lib/registry.ts; icons in lib/icons.ts.)
 * Importable from client components — no Node APIs here.
 */
export const siteConfig = {
  name: "DNS Manager",
  title: "DNS Manager Docs",
  description: "Documentation for DNS Manager, the homelab DNS control plane.",
  githubUrl: "https://github.com/sharanvelu/dns-manager",
} as const;

/** Where "Get started" CTAs point. */
export const GET_STARTED_URL = "/docs/installation/";

/** The docs home. */
export const DOCS_HOME_URL = "/docs/";

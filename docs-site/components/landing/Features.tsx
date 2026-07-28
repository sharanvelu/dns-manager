import type { CSSProperties } from "react";
import DocIcon from "@/components/DocIcon";
import { iconHue } from "@/lib/icons";

/**
 * Feature showcase. Every claim is grounded in the docs overview
 * (previously docs/content/index.md) — do not add features that do not
 * exist in the app.
 */

const FEATURES: Array<{ icon: string; title: string; body: string }> = [
  {
    icon: "zones",
    title: "Zones with relative names",
    body: "Records grouped by domain — @ for the apex, www, *.app. Pasted full hostnames are relativized automatically.",
  },
  {
    icon: "providers",
    title: "Providers as reusable credentials",
    body: "One Cloudflare API token can serve all your domains: enter credentials once, attach them to any number of zones.",
  },
  {
    icon: "dashboard",
    title: "Single pane of glass",
    body: "Manage records across every provider from one UI. Per-provider status chips show where each record lives and whether it is in sync.",
  },
  {
    icon: "sync",
    title: "Push on save, drift detection",
    body: "Saves queue background pushes with retries and backoff. Every provider is drift-checked on a 15-minute schedule — Sync now re-pushes.",
  },
  {
    icon: "dns-entries",
    title: "Per-entry provider targeting",
    body: "Each entry selects the zone attachments it syncs to. Sensible defaults, narrowable per entry — keep nas on Pi-hole only.",
  },
  {
    icon: "globe",
    title: "Zone discovery & import",
    body: "Cloudflare Zone IDs are looked up from the zone name. Browse a provider's live records and pull them in without duplicates.",
  },
  {
    icon: "authentication",
    title: "OIDC single sign-on",
    body: "Sign-in is delegated to any spec-compliant OpenID Connect provider — Authentik, Keycloak, Auth0 — with auto-provisioned users.",
  },
  {
    icon: "users",
    title: "Zone-based access control",
    body: "Global roles plus per-zone grants with combinable zone roles. Everyone starts with no access until granted.",
  },
  {
    icon: "activity",
    title: "Audit trail",
    body: "Every change is logged with field-level old → new diffs, filterable per record and per zone. Provider secrets are never logged.",
  },
  {
    icon: "shield",
    title: "Encrypted credentials",
    body: "API tokens and passwords are stored encrypted at rest (AES-256) and never sent back to the browser.",
  },
  {
    icon: "terminal",
    title: "Bulk actions & CSV import",
    body: "Select entries to sync, retarget, edit or delete in one go — or bulk-create from a CSV with per-row validation.",
  },
  {
    icon: "installation",
    title: "Kubernetes-ready",
    body: "One container image runs web, queue worker and scheduler roles, with ready-made manifests in the repo.",
  },
];

export default function Features() {
  return (
    <section className="mx-auto max-w-7xl px-4 pb-20 sm:px-6">
      <p className="mb-2 text-center text-xs font-semibold uppercase tracking-[0.1em] text-accent">
        Features
      </p>
      <h2 className="mb-10 text-center text-3xl font-semibold tracking-tight">
        Everything your homelab DNS needs
      </h2>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {FEATURES.map((feature) => (
          <div
            key={feature.title}
            className="landing-feature group flex flex-col gap-3 rounded-2xl border border-border bg-surface p-5 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
            style={{ "--card-hue": `var(${iconHue(feature.icon)})` } as CSSProperties}
          >
            <span
              aria-hidden="true"
              className="flex size-9 items-center justify-center rounded-lg"
              style={{
                color: "var(--card-hue)",
                backgroundColor: "color-mix(in srgb, var(--card-hue) 11%, transparent)",
              }}
            >
              <DocIcon name={feature.icon} size={17} strokeWidth={1.5} />
            </span>
            <h3 className="text-sm font-semibold tracking-tight">{feature.title}</h3>
            <p className="text-[13px] leading-relaxed text-muted">{feature.body}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

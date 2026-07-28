import Link from "next/link";
import DocIcon from "@/components/DocIcon";

/**
 * Supported providers — facts from the docs overview's provider table.
 */

const PROVIDERS: Array<{
  icon: string;
  name: string;
  kind: string;
  href: string;
  points: string[];
}> = [
  {
    icon: "cloudflare",
    name: "Cloudflare",
    kind: "Public DNS",
    href: "/docs/providers/cloudflare/",
    points: [
      "A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR",
      "Zone ID auto-discovered from the zone name",
      "Proxying for A/AAAA/CNAME, MX/SRV priority",
    ],
  },
  {
    icon: "pihole",
    name: "Pi-hole v6",
    kind: "Local DNS",
    href: "/docs/providers/pihole/",
    points: [
      "A, AAAA, CNAME",
      "Zoneless — attaches to every zone automatically",
      "Per-zone opt-out when a zone shouldn't be served",
    ],
  },
  {
    icon: "technitium",
    name: "Technitium",
    kind: "Self-hosted authoritative DNS",
    href: "/docs/providers/technitium/",
    points: [
      "A, AAAA, CNAME, MX, TXT, SRV, NS, CAA, PTR",
      "Attaches per zone, addressed by the zone's name",
      "MX/SRV priority; flexible TTLs",
    ],
  },
];

export default function ProviderShowcase() {
  return (
    <section className="relative overflow-hidden border-y border-border bg-background-soft/60">
      <div
        aria-hidden="true"
        className="pointer-events-none absolute inset-x-0 top-0 flex justify-center"
      >
        <div
          className="h-64 w-[60rem] max-w-full -translate-y-1/2 rounded-full opacity-10 blur-3xl"
          style={{
            background:
              "linear-gradient(90deg, var(--docs-gradient-from), var(--docs-gradient-via), var(--docs-gradient-to))",
          }}
        />
      </div>
      <div className="relative mx-auto max-w-7xl px-4 py-20 sm:px-6">
        <p className="mb-2 text-center text-xs font-semibold uppercase tracking-[0.1em] text-accent">
          Integrations
        </p>
        <h2 className="mb-3 text-center text-3xl font-semibold tracking-tight">
          Supported providers
        </h2>
        <p className="mx-auto mb-10 max-w-xl text-center text-sm leading-relaxed text-muted">
          Providers are pluggable connectors behind a small interface — the
          Technitium connector shipped that way, and an Unbound connector is
          planned.
        </p>
        <div className="grid gap-4 sm:grid-cols-3">
          {PROVIDERS.map((provider) => (
            <Link
              key={provider.name}
              href={provider.href}
              className="landing-provider group rounded-2xl border border-border bg-surface p-6 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
            >
              <span className="gradient-mark flex size-10 items-center justify-center rounded-xl text-accent-fg shadow-sm">
                <DocIcon name={provider.icon} size={19} />
              </span>
              <h3 className="mt-4 text-[15px] font-semibold tracking-tight transition-colors group-hover:text-accent">
                {provider.name}
              </h3>
              <p className="mt-0.5 text-xs font-medium uppercase tracking-[0.06em] text-faint">
                {provider.kind}
              </p>
              <ul className="mt-4 space-y-1.5 text-[13px] leading-relaxed text-muted">
                {provider.points.map((point) => (
                  <li key={point} className="flex gap-2">
                    <span
                      aria-hidden="true"
                      className="mt-[0.55em] size-1 shrink-0 rounded-full bg-accent"
                    />
                    {point}
                  </li>
                ))}
              </ul>
            </Link>
          ))}
        </div>
      </div>
    </section>
  );
}

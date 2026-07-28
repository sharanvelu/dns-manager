import { ArrowRight } from "lucide-react";
import Link from "next/link";
import GitHubIcon from "@/components/GitHubIcon";
import { navGroups, pageUrl } from "@/lib/registry";
import { GET_STARTED_URL, siteConfig } from "@/lib/site";

/**
 * Landing footer: closing CTA band + documentation group links.
 */
export default function Footer({ version }: { version: string }) {
  const groups = navGroups();

  return (
    <footer>
      <div className="mx-auto max-w-7xl px-4 py-20 text-center sm:px-6">
        <h2 className="text-balance text-3xl font-semibold tracking-tight">
          Put your DNS on <span className="gradient-text">autopilot</span>
        </h2>
        <p className="mx-auto mt-3 max-w-md text-sm leading-relaxed text-muted">
          Self-hosted, open source, and running in one container — on Docker or
          Kubernetes.
        </p>
        <div className="mt-7 flex flex-wrap items-center justify-center gap-3">
          <Link
            href={GET_STARTED_URL}
            className="gradient-cta inline-flex h-10 items-center gap-2 rounded-lg px-5 text-sm font-medium text-accent-fg shadow-sm transition-opacity hover:opacity-90"
          >
            Get started
            <ArrowRight size={15} strokeWidth={1.75} />
          </Link>
          <a
            href={siteConfig.githubUrl}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-surface px-5 text-sm font-medium text-foreground transition-colors hover:bg-background-soft"
          >
            <GitHubIcon size={15} />
            View on GitHub
          </a>
        </div>
      </div>

      <div className="border-t border-border">
        <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
          {groups.map((group) => (
            <div key={group.dir}>
              <p className="mb-2 text-[11px] font-semibold uppercase tracking-[0.08em] text-faint">
                {group.label}
              </p>
              <ul className="space-y-1">
                {group.items.map((item) => (
                  <li key={pageUrl(item)}>
                    <Link
                      href={pageUrl(item)}
                      className="text-[13px] text-muted transition-colors hover:text-foreground"
                    >
                      {item.slug === "" ? "Overview" : item.title}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
        <div className="border-t border-border-soft">
          <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-3 px-4 py-6 text-sm text-muted sm:flex-row sm:px-6">
            <span>
              {siteConfig.name} · <span className="tabular-nums">v{version}</span>
            </span>
            <span className="flex items-center gap-5">
              <a
                href={siteConfig.githubUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 transition-colors hover:text-foreground"
              >
                <GitHubIcon size={14} />
                GitHub
              </a>
              <Link href="/docs/" className="transition-colors hover:text-foreground">
                Documentation
              </Link>
            </span>
          </div>
        </div>
      </div>
    </footer>
  );
}

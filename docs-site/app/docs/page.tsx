import { ArrowRight } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";
import type { CSSProperties } from "react";
import DocIcon from "@/components/DocIcon";
import Sidebar from "@/components/Sidebar";
import { navGroups, pageUrl } from "@/lib/registry";
import { GET_STARTED_URL, siteConfig } from "@/lib/site";

export const metadata: Metadata = {
  title: siteConfig.title,
  description: siteConfig.description,
};

/**
 * Docs home (/docs/) — gradient masthead + one card per group. This page
 * (not the product landing) is what the in-app "Docs" link opens.
 */
export default function DocsHomePage() {
  const groups = navGroups();

  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6">
      <div className="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-10">
        <aside className="hidden lg:block">
          <Sidebar groups={groups} />
        </aside>

        <main className="min-w-0 py-10 lg:py-12">
          <section className="relative overflow-hidden rounded-3xl border border-border p-8 sm:p-10">
            <div
              aria-hidden="true"
              className="pointer-events-none absolute -right-24 -top-24 size-72 rounded-full opacity-20 blur-3xl"
              style={{
                background:
                  "radial-gradient(closest-side, var(--docs-gradient-to), transparent 70%)",
              }}
            />
            <div
              aria-hidden="true"
              className="pointer-events-none absolute -bottom-28 -left-16 size-72 rounded-full opacity-20 blur-3xl"
              style={{
                background:
                  "radial-gradient(closest-side, var(--docs-gradient-from), transparent 70%)",
              }}
            />
            <div className="relative">
              <p className="mb-2 text-xs font-semibold uppercase tracking-[0.1em] text-accent">
                Documentation
              </p>
              <h1 className="text-balance text-4xl font-semibold tracking-tight">
                Run your DNS from <span className="gradient-text">one place</span>
              </h1>
              <p className="mt-4 max-w-xl text-[15px] leading-relaxed text-muted">
                Install {siteConfig.name}, connect your providers, and manage
                every record across Cloudflare, Pi-hole and Technitium — with
                automatic pushes, drift detection and a full audit trail.
              </p>
              <div className="mt-6 flex flex-wrap gap-3">
                <Link
                  href={GET_STARTED_URL}
                  className="gradient-cta inline-flex h-9 items-center gap-2 rounded-lg px-4 text-sm font-medium text-accent-fg shadow-sm transition-opacity hover:opacity-90"
                >
                  Get started
                  <ArrowRight size={14} strokeWidth={1.75} />
                </Link>
                <Link
                  href="/docs/dns-entries/"
                  className="inline-flex h-9 items-center rounded-lg border border-border bg-surface px-4 text-sm font-medium text-foreground transition-colors hover:bg-background-soft"
                >
                  How entries sync
                </Link>
              </div>
            </div>
          </section>

          <section className="mt-10">
            <h2 className="mb-4 text-sm font-semibold uppercase tracking-[0.08em] text-faint">
              Browse by topic
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
              {groups.map((group) => {
                const index = group.items.find((item) => item.slug === "");
                const rest = group.items.filter((item) => item.slug !== "");
                const href = index ? pageUrl(index) : pageUrl(group.items[0]);
                return (
                  <div
                    key={group.dir}
                    className="doc-card group relative"
                    style={{ "--card-hue": `var(${group.hue})` } as CSSProperties}
                  >
                    <span className="doc-card-icon" aria-hidden="true">
                      <DocIcon name={group.icon} size={18} />
                    </span>
                    <span className="doc-card-body">
                      <Link href={href} className="doc-card-title doc-card-cover">
                        {group.label}
                      </Link>
                      <span className="doc-card-text">
                        {index?.description ?? ""}
                      </span>
                      {rest.length > 0 && (
                        <span className="relative z-10 mt-3 flex flex-wrap gap-x-4 gap-y-1">
                          {rest.map((item) => (
                            <Link
                              key={pageUrl(item)}
                              href={pageUrl(item)}
                              className="text-xs font-medium text-muted underline-offset-4 transition-colors hover:text-accent hover:underline"
                            >
                              {item.title}
                            </Link>
                          ))}
                        </span>
                      )}
                    </span>
                  </div>
                );
              })}
            </div>
          </section>
        </main>
      </div>
    </div>
  );
}

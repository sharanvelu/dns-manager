"use client";

import { Menu, X } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { CSSProperties } from "react";
import { useEffect, useState } from "react";
import DocIcon from "@/components/DocIcon";
import type { NavGroup } from "@/lib/registry";
import { pageUrl } from "@/lib/registry";
import { siteConfig } from "@/lib/site";
import GitHubIcon from "./GitHubIcon";

export default function MobileNav({
  groups,
  version,
}: {
  groups: NavGroup[];
  version: string;
}) {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();
  const normalized = pathname.endsWith("/") ? pathname : `${pathname}/`;

  // Close on navigation.
  useEffect(() => {
    setOpen(false);
  }, [pathname]);

  // Scroll lock + Escape while open.
  useEffect(() => {
    if (!open) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const onKey = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };
    document.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = previous;
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <div className="lg:hidden">
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Open navigation"
        className="inline-flex size-8 items-center justify-center rounded-md text-muted transition-colors hover:bg-background-soft hover:text-foreground"
      >
        <Menu size={18} strokeWidth={1.5} />
      </button>

      {open && (
        <div className="fixed inset-0 z-50">
          <button
            type="button"
            aria-label="Close navigation"
            onClick={() => setOpen(false)}
            className="absolute inset-0 bg-black/40 backdrop-blur-xs"
          />
          <div className="absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r border-border bg-background shadow-2xl">
            <div className="flex h-14 items-center justify-between border-b border-border px-4">
              <span className="text-sm font-semibold tracking-tight">
                {siteConfig.name}
                <span className="ml-2 text-xs font-medium tabular-nums text-faint">
                  v{version}
                </span>
              </span>
              <button
                type="button"
                onClick={() => setOpen(false)}
                aria-label="Close navigation"
                className="inline-flex size-8 items-center justify-center rounded-md text-muted hover:bg-background-soft hover:text-foreground"
              >
                <X size={16} strokeWidth={1.5} />
              </button>
            </div>

            <nav
              aria-label="Documentation"
              className="flex-1 space-y-6 overflow-y-auto px-3 py-5"
            >
              <Link
                href="/docs/"
                className={
                  normalized === "/docs/"
                    ? "block rounded-md bg-accent-soft px-2.5 py-1.5 text-sm font-medium text-accent"
                    : "block rounded-md px-2.5 py-1.5 text-sm text-muted transition-colors hover:bg-background-soft hover:text-foreground"
                }
              >
                Docs home
              </Link>
              {groups.map((group) => (
                <div
                  key={group.dir}
                  style={{ "--group-hue": `var(${group.hue})` } as CSSProperties}
                >
                  <p className="mb-1.5 flex items-center gap-2 px-2.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-faint">
                    <span
                      aria-hidden="true"
                      className="flex size-5 items-center justify-center rounded-md"
                      style={{
                        color: "var(--group-hue)",
                        backgroundColor:
                          "color-mix(in srgb, var(--group-hue) 12%, transparent)",
                      }}
                    >
                      <DocIcon name={group.icon} size={12} strokeWidth={2} />
                    </span>
                    {group.label}
                  </p>
                  <ul className="space-y-0.5">
                    {group.items.map((item) => {
                      const href = pageUrl(item);
                      const active = normalized === href;
                      return (
                        <li key={href}>
                          <Link
                            href={href}
                            aria-current={active ? "page" : undefined}
                            className={
                              active
                                ? "sidebar-link sidebar-link-active block rounded-md px-2.5 py-1.5 text-sm font-medium"
                                : "block rounded-md px-2.5 py-1.5 text-sm text-muted transition-colors hover:bg-background-soft hover:text-foreground"
                            }
                          >
                            {item.slug === "" ? "Overview" : item.title}
                          </Link>
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ))}
            </nav>

            <div className="border-t border-border px-4 py-3">
              <a
                href={siteConfig.githubUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-2 text-sm text-muted hover:text-foreground"
              >
                <GitHubIcon size={15} />
                GitHub
              </a>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

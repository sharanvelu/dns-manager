"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import type { CSSProperties } from "react";
import DocIcon from "@/components/DocIcon";
import type { NavGroup } from "@/lib/registry";
import { pageUrl } from "@/lib/registry";

/**
 * Grouped docs sidebar. Active state derives from the pathname (client),
 * so the same server-rendered layout works on every static page. Each
 * group carries its palette hue (--docs-group-*) for the icon chip and
 * the active item accent.
 */
export default function Sidebar({ groups }: { groups: NavGroup[] }) {
  const pathname = usePathname();
  const normalized = pathname.endsWith("/") ? pathname : `${pathname}/`;

  return (
    <nav
      aria-label="Documentation"
      className="sticky top-14 max-h-[calc(100vh-3.5rem)] overflow-y-auto py-8 pr-3"
    >
      <div className="space-y-6">
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
                  backgroundColor: "color-mix(in srgb, var(--group-hue) 12%, transparent)",
                }}
              >
                <DocIcon name={group.icon} size={12} strokeWidth={2} />
              </span>
              {group.label}
            </p>
            <ul className="space-y-0.5 border-l border-border-soft pl-2 ml-[1.15rem]">
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
                          ? "sidebar-link sidebar-link-active block rounded-md px-2.5 py-1.5 text-[13px] font-medium"
                          : "block rounded-md px-2.5 py-1.5 text-[13px] text-muted transition-colors hover:bg-background-soft hover:text-foreground"
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
      </div>
    </nav>
  );
}

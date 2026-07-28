import Link from "next/link";
import type { NavGroup } from "@/lib/site";
import { docHref } from "@/lib/site";

export default function Sidebar({
  groups,
  currentSlug,
}: {
  groups: NavGroup[];
  currentSlug?: string;
}) {
  return (
    <nav
      aria-label="Documentation"
      className="sticky top-14 max-h-[calc(100vh-3.5rem)] overflow-y-auto py-8 pr-3"
    >
      <div className="space-y-7">
        {groups.map((group) => (
          <div key={group.title}>
            <p className="mb-2 px-2.5 text-[11px] font-semibold uppercase tracking-[0.08em] text-faint">
              {group.title}
            </p>
            <ul className="space-y-0.5">
              {group.items.map((item) => {
                const active = item.slug === currentSlug;
                return (
                  <li key={item.slug}>
                    <Link
                      href={docHref(item.slug)}
                      aria-current={active ? "page" : undefined}
                      className={
                        active
                          ? "block rounded-md bg-accent-soft px-2.5 py-1.5 text-[13px] font-medium text-accent"
                          : "block rounded-md px-2.5 py-1.5 text-[13px] text-muted transition-colors hover:bg-background-soft hover:text-foreground"
                      }
                    >
                      {item.title}
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

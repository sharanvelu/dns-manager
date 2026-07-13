import Link from "next/link";
import type { DocMeta } from "@/lib/docs";

export default function DocsSidebar({
  nav,
  version,
  currentSlug,
}: {
  nav: DocMeta[];
  version: string;
  currentSlug: string;
}) {
  return (
    <aside className="sidebar">
      <Link href="/" className="sidebar-title">
        DNS Manager Docs
      </Link>
      <p className="sidebar-version">v{version}</p>
      <nav aria-label="Documentation">
        {nav.map((doc) => {
          const href = doc.slug === "index" ? "/" : `/${doc.slug}/`;
          const active = doc.slug === currentSlug;
          return (
            <Link
              key={doc.slug}
              href={href}
              className={active ? "active" : undefined}
              aria-current={active ? "page" : undefined}
            >
              {doc.title}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}

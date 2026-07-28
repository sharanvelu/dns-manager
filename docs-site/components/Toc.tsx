"use client";

import { usePathname } from "next/navigation";
import { useEffect, useState } from "react";

/**
 * "On this page" outline (xl+ only). Headings are extracted from the
 * rendered DOM (the [data-doc-article] article's h2/h3 with ids — exactly
 * what the <H2>/<H3>/<Step> helpers emit), with IntersectionObserver
 * scroll-spy.
 */

interface TocItem {
  depth: 2 | 3;
  id: string;
  text: string;
}

export default function Toc() {
  const pathname = usePathname();
  const [toc, setToc] = useState<TocItem[]>([]);
  const [activeId, setActiveId] = useState<string | null>(null);

  // Extract headings from the DOM after every navigation.
  useEffect(() => {
    const article = document.querySelector("[data-doc-article]");
    if (!article) {
      setToc([]);
      return;
    }
    const items: TocItem[] = [];
    for (const el of article.querySelectorAll<HTMLElement>("h2[id], h3[id]")) {
      const clone = el.cloneNode(true) as HTMLElement;
      clone.querySelectorAll(".heading-anchor").forEach((anchor) => anchor.remove());
      items.push({
        depth: el.tagName === "H2" ? 2 : 3,
        id: el.id,
        text: clone.textContent?.trim() ?? "",
      });
    }
    setToc(items);
    setActiveId(null);
  }, [pathname]);

  useEffect(() => {
    if (toc.length === 0) return;
    const headings = toc
      .map((item) => document.getElementById(item.id))
      .filter((el): el is HTMLElement => el !== null);
    if (headings.length === 0) return;

    const visible = new Set<string>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) visible.add(entry.target.id);
          else visible.delete(entry.target.id);
        }
        // First visible heading in document order wins; when none are in
        // view (e.g. long section body), keep the last active one.
        for (const item of toc) {
          if (visible.has(item.id)) {
            setActiveId(item.id);
            return;
          }
        }
      },
      { rootMargin: "-80px 0px -70% 0px" },
    );
    for (const el of headings) observer.observe(el);
    return () => observer.disconnect();
  }, [toc]);

  if (toc.length === 0) return null;

  return (
    <nav
      aria-label="On this page"
      className="sticky top-14 max-h-[calc(100vh-3.5rem)] overflow-y-auto py-12 pl-2"
    >
      <p className="mb-3 text-[11px] font-semibold uppercase tracking-[0.08em] text-faint">
        On this page
      </p>
      <ul className="space-y-1 border-l border-border-soft text-[13px]">
        {toc.map((item) => {
          const active = item.id === activeId;
          return (
            <li key={item.id}>
              <a
                href={`#${item.id}`}
                className={[
                  "-ml-px block border-l py-0.5 leading-snug transition-colors",
                  item.depth === 3 ? "pl-6" : "pl-3",
                  active
                    ? "border-accent font-medium text-accent"
                    : "border-transparent text-muted hover:text-foreground",
                ].join(" ")}
              >
                {item.text}
              </a>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}

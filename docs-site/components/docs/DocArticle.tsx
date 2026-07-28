import type { Metadata } from "next";
import type { CSSProperties, ReactNode } from "react";
import CodeCopy from "@/components/CodeCopy";
import PrevNext from "@/components/PrevNext";
import Sidebar from "@/components/Sidebar";
import Toc from "@/components/Toc";
import { findPage, groupConfig, navGroups } from "@/lib/registry";

/**
 * The docs page shell — every content page renders through this. Looks
 * up its own title/description in lib/registry.ts, renders the 3-column
 * layout (grouped sidebar / article / client-side scroll-spy TOC), the
 * group eyebrow, prev/next pagination and the code-copy wiring.
 *
 * A page file is just:
 *
 *   import DocArticle, { docMetadata } from "@/components/docs/DocArticle";
 *   export const metadata = docMetadata("zones", "creating");
 *   export default function Page() {
 *     return (
 *       <DocArticle group="zones" slug="creating">
 *         …bare JSX prose (p, ul, table) + the component kit…
 *       </DocArticle>
 *     );
 *   }
 *
 * Prose styling for bare h2/h3/p/ul/ol/table/a/code inside the article
 * comes from app/prose.css (.prose). Use <H2>/<H3> for anchored headings.
 */

export function docMetadata(group: string, slug = ""): Metadata {
  const page = findPage(group, slug);
  if (!page) return { title: "DNS Manager Docs" };
  return {
    title: `${page.title} — DNS Manager Docs`,
    description: page.description || undefined,
  };
}

export default function DocArticle({
  group,
  slug = "",
  children,
}: {
  group: string;
  slug?: string;
  children: ReactNode;
}) {
  const page = findPage(group, slug);
  const config = groupConfig(group);
  // Tolerate a page file landing before its registry entry: fall back to
  // a title derived from the slug instead of crashing the build.
  const title = page?.title ?? (slug || group).replace(/[-_]+/g, " ");
  const description = page?.description ?? "";

  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6">
      <div className="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-10 xl:grid-cols-[16rem_minmax(0,1fr)_14rem] xl:gap-12">
        <aside className="hidden lg:block">
          <Sidebar groups={navGroups()} />
        </aside>

        <main className="min-w-0 py-8 lg:py-12">
          <article className="prose max-w-[74ch]" data-doc-article>
            <p
              className="doc-eyebrow"
              style={{ "--group-hue": `var(${config.hue})` } as CSSProperties}
            >
              {config.label}
            </p>
            <h1>{title}</h1>
            {description && <p className="doc-lede">{description}</p>}
            {children}
          </article>
          <div className="max-w-[74ch]">
            <PrevNext group={group} slug={slug} />
          </div>
          <CodeCopy />
        </main>

        <div className="hidden xl:block">
          <Toc />
        </div>
      </div>
    </div>
  );
}

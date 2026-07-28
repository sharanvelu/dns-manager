import type { Metadata } from "next";
import { notFound } from "next/navigation";
import CodeCopy from "@/components/CodeCopy";
import PrevNext from "@/components/PrevNext";
import Sidebar from "@/components/Sidebar";
import Toc from "@/components/Toc";
import {
  getAllDocs,
  getDoc,
  getNav,
  renderDoc,
  stripLeadingH1,
} from "@/lib/docs";
import { groupNav } from "@/lib/site";

export const dynamicParams = false;

export function generateStaticParams(): Array<{ slug: string }> {
  return getAllDocs()
    .filter((doc) => doc.slug !== "index")
    .map((doc) => ({ slug: doc.slug }));
}

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}): Promise<Metadata> {
  const { slug } = await params;
  const doc = getDoc(slug);
  if (!doc) return { title: "Not found — DNS Manager Docs" };
  return {
    title: `${doc.title} — DNS Manager Docs`,
    description: doc.description || undefined,
  };
}

export default async function DocPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const doc = getDoc(slug);
  if (!doc || slug === "index") notFound();

  // Render our own title block (h1 + description lede) for layout
  // control; drop the markdown's leading H1 so it isn't duplicated.
  const { html, toc } = await renderDoc(stripLeadingH1(doc.markdown));
  const nav = getNav();

  return (
    <div className="mx-auto max-w-7xl px-4 sm:px-6">
      <div className="lg:grid lg:grid-cols-[15rem_minmax(0,1fr)] lg:gap-10 xl:grid-cols-[15rem_minmax(0,1fr)_14rem] xl:gap-12">
        <aside className="hidden lg:block">
          <Sidebar groups={groupNav(nav)} currentSlug={slug} />
        </aside>

        <main className="min-w-0 py-8 lg:py-10">
          <article className="prose max-w-[72ch]">
            <h1>{doc.title}</h1>
            {doc.description && <p className="doc-lede">{doc.description}</p>}
            <div dangerouslySetInnerHTML={{ __html: html }} />
          </article>
          <div className="max-w-[72ch]">
            <PrevNext nav={nav} currentSlug={slug} />
          </div>
          <CodeCopy />
        </main>

        <div className="hidden xl:block">
          <Toc toc={toc} />
        </div>
      </div>
    </div>
  );
}

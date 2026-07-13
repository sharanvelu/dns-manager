import type { Metadata } from "next";
import { notFound } from "next/navigation";
import DocsSidebar from "@/components/DocsSidebar";
import { getAllDocs, getDoc, getNav, getVersion, markdownToHtml } from "@/lib/docs";

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

  const html = await markdownToHtml(doc.markdown);
  const hasOwnH1 = /^#\s+/m.test(doc.markdown);

  return (
    <div className="docs-shell">
      <DocsSidebar nav={getNav()} version={getVersion()} currentSlug={slug} />
      <main className="doc-main">
        <article className="prose">
          {!hasOwnH1 && <h1>{doc.title}</h1>}
          <div dangerouslySetInnerHTML={{ __html: html }} />
        </article>
      </main>
    </div>
  );
}

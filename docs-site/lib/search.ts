import type { Root } from "mdast";
import remarkGfm from "remark-gfm";
import remarkParse from "remark-parse";
import { unified } from "unified";
import { visit } from "unist-util-visit";
import { getAllDocs, renderDoc } from "./docs";

/**
 * Build-time search index, exported as static JSON
 * (`app/search-index.json/route.ts`) and consumed lazily by
 * `components/SearchDialog.tsx`. Heading ids come from the same render
 * pipeline as the pages, so deep links always match the emitted anchors.
 */

export interface SearchHeading {
  id: string;
  text: string;
}

export interface SearchDocEntry {
  slug: string;
  title: string;
  description: string;
  headings: SearchHeading[];
  plainText: string;
}

function toPlainText(markdown: string): string {
  const tree = unified().use(remarkParse).use(remarkGfm).parse(markdown) as Root;
  const parts: string[] = [];
  visit(tree, (node) => {
    if (node.type === "text" || node.type === "inlineCode" || node.type === "code") {
      parts.push((node as { value: string }).value);
    }
  });
  return parts.join(" ").replace(/\s+/g, " ").trim().slice(0, 8000);
}

export async function buildSearchIndex(): Promise<SearchDocEntry[]> {
  const docs = getAllDocs();
  return Promise.all(
    docs.map(async (doc) => {
      const { toc } = await renderDoc(doc.markdown);
      return {
        slug: doc.slug,
        title: doc.title,
        description: doc.description,
        headings: toc.map(({ id, text }) => ({ id, text })),
        plainText: toPlainText(doc.markdown),
      };
    }),
  );
}
